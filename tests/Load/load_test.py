#!/usr/bin/env python3
"""Load test for public submissions: many respondents at once on one form, the way a browser does it.

Each virtual respondent fetches the anti-spam challenge, solves it (spread over this machine's cores, so the
load generator is not the bottleneck) and submits like the browser: a busy form (503, 502, 504, no answer) is tried
again for a minute with the same submission id and the server's retry pass; then it counts as "not saved". After every stage the stored responses are counted: every
submission the server accepted must be stored exactly once.

Run on demand against a test instance, never against production. Python 3 standard library only.

  python3 tests/Load/load_test.py --base https://nc.example --user alice --password-file pw.txt
  python3 tests/Load/load_test.py ... --stages 10,50,200 --per-user 5 --prefill 5000
  python3 tests/Load/load_test.py ... --no-retry      # one try per submission, for a baseline on a server before 3a-5

The account creates its own form (anonymous, multiple answers, public link) and deletes it afterwards unless
--keep is given. --prefill fills the form first through signed-in submissions and records how the time per
submission grows with the number of stored responses. The report (JSON + Markdown) goes to --out.
"""
import argparse, base64, collections, concurrent.futures as cf, hashlib, json, os, random, statistics, threading, time, uuid
import urllib.error, urllib.parse, urllib.request

TIMEOUT = 180
# As the browser does (src/utils/retry.js): a busy form is tried again for a minute, with the same submission id.
RETRY_WINDOW_S = 60
TRANSIENT = (0, 502, 503, 504)


class Client:
    def __init__(self, base, user, secret):
        self.app = base.rstrip('/') + '/index.php/apps/formvox'
        self.ocs = base.rstrip('/') + '/ocs/v2.php'
        self.user = user
        self.secret = secret

    def request(self, method, url, body=None, auth=False, agent=None):
        headers = {'Accept': 'application/json', 'OCS-APIRequest': 'true', 'User-Agent': agent or 'formvox-load-test'}
        if auth:
            headers['Authorization'] = 'Basic ' + base64.b64encode(f'{self.user}:{self.secret}'.encode()).decode()
        data = None
        if body is not None:
            data = json.dumps(body).encode()
            headers['Content-Type'] = 'application/json'
        start = time.perf_counter()
        try:
            with urllib.request.urlopen(urllib.request.Request(url, data=data, method=method, headers=headers), timeout=TIMEOUT) as r:
                status, raw = r.status, r.read()
        except urllib.error.HTTPError as e:
            status, raw = e.code, e.read()
        except Exception as e:  # timeouts, refused or reset connections
            return 0, {'error': type(e).__name__}, time.perf_counter() - start
        elapsed = time.perf_counter() - start
        try:
            return status, json.loads(raw or b'null'), elapsed
        except ValueError:
            return status, None, elapsed


def solve(challenge):
    """ALTCHA: the number n in [0, maxnumber] with sha256(salt + n) == challenge."""
    salt, target = challenge['salt'].encode(), challenge['challenge']
    for n in range(challenge['maxnumber'] + 1):
        if hashlib.sha256(salt + str(n).encode()).hexdigest() == target:
            return n
    return None


def percentile(values, p):
    if not values:
        return None
    ordered = sorted(values)
    return ordered[min(len(ordered) - 1, round(p / 100 * (len(ordered) - 1)))]


def stored(client, fid):
    status, data, elapsed = client.request('GET', f'{client.app}/api/form/{fid}/responses', auth=True)
    return (len(data['responses']) if status == 200 else None), elapsed


def answers(size):
    return {'name': 'Load test', 'note': ('x' * size) if size else 'ok'}


def retry_delay(attempt):
    """What the browser waits: at least two seconds, longer each time, never more than eight, plus a little jitter."""
    return min(2 * 2 ** min(attempt, 2), 8) + random.random() * 0.5


def respondent(client, fid, token, times, size, solver, barrier, agent, retry):
    """One respondent submitting `times` times, each like a browser; returns one record per submission."""
    records = []
    barrier.wait()
    for _ in range(times):
        start = time.perf_counter()
        s, challenge, t_challenge = client.request('GET', f'{client.app}/public/{fid}/{token}/challenge', agent=agent)
        if s != 200:
            records.append({'status': s, 'stage': 'challenge', 'outcome': 'refused', 'total': time.perf_counter() - start})
            continue
        t0 = time.perf_counter()
        number = solver.submit(solve, challenge).result()
        t_solve = time.perf_counter() - t0
        altcha = {k: challenge[k] for k in ('algorithm', 'challenge', 'salt', 'signature')} | {'number': number}
        body = {'answers': answers(size), 'altcha': altcha}
        if retry:
            body['submissionId'] = str(uuid.uuid4())
        deadline = time.perf_counter() + RETRY_WINDOW_S
        busy, first, submits = 0, None, []
        while True:
            s, answer, t_submit = client.request('POST', f'{client.app}/public/{fid}/{token}/submit', body, agent=agent)
            submits.append(t_submit)
            first = s if first is None else first
            if not retry or s not in TRANSIENT or time.perf_counter() >= deadline:
                break
            busy += 1
            time.sleep(min(retry_delay(busy - 1), max(0, deadline - time.perf_counter())))
            # The pass spares the retry a new challenge; without one (no answer at all) the browser solves a new one.
            retry_pass = answer.get('retryToken') if isinstance(answer, dict) else None
            if retry_pass:
                body = {'answers': body['answers'], 'submissionId': body['submissionId'], 'retryToken': retry_pass}
                continue
            s2, challenge, _ = client.request('GET', f'{client.app}/public/{fid}/{token}/challenge', agent=agent)
            if s2 != 200:
                break
            fresh = {k: challenge[k] for k in ('algorithm', 'challenge', 'salt', 'signature')} | {'number': solver.submit(solve, challenge).result()}
            body = {'answers': body['answers'], 'submissionId': body['submissionId'], 'altcha': fresh}
        if 200 <= s < 300:
            outcome = 'stored'
        elif s in TRANSIENT:
            outcome = 'not_saved'
        else:
            outcome = 'refused'
        records.append({
            'status': s, 'first_status': first, 'stage': 'submit', 'outcome': outcome, 'busy': busy,
            'challenge': t_challenge, 'solve': t_solve, 'submit': submits[-1], 'submits': len(submits),
            'total': time.perf_counter() - start, 'maxnumber': challenge['maxnumber'],
            'reason': answer.get('reason') if isinstance(answer, dict) else None,
        })
    return records


def run_stage(client, fid, token, users, times, size, solver, retry):
    before, _ = stored(client, fid)
    barrier = threading.Barrier(users)
    start = time.perf_counter()
    with cf.ThreadPoolExecutor(max_workers=users) as pool:
        futures = [pool.submit(respondent, client, fid, token, times, size, solver, barrier, f'formvox-load-test/{i}', retry) for i in range(users)]
        records = [r for f in futures for r in f.result()]
    wall = time.perf_counter() - start
    after, t_results = stored(client, fid)
    ok = [r for r in records if r['stage'] == 'submit' and 200 <= r['status'] < 300]
    submit = [r['submit'] for r in ok]
    total = [r['total'] for r in ok]
    return {
        'concurrent': users, 'attempted': len(records), 'accepted': len(ok),
        'stored_before': before, 'stored_after': after,
        'lost': (len(ok) - (after - before)) if None not in (before, after) else None,
        'errors': dict(collections.Counter(f"{r['status']} {r.get('reason') or r['stage']}" for r in records if r not in ok)),
        # What the respondents saw in the end, and how often a form was busy on the way there.
        'outcomes': dict(collections.Counter(r['outcome'] for r in records)),
        'busy_answers': sum(r.get('busy', 0) for r in records),
        'first_try_errors': dict(collections.Counter(f"{r['first_status']}" for r in records if r.get('first_status') not in (None, 200, 201))),
        'wall_s': round(wall, 2), 'throughput_per_s': round(len(ok) / wall, 2) if wall else None,
        'submit_ms': {p: ms(percentile(submit, p)) for p in (50, 95, 99)} | {'max': ms(max(submit, default=None))},
        'total_ms': {p: ms(percentile(total, p)) for p in (50, 95, 99)},
        'solve_ms_median': ms(statistics.median([r['solve'] for r in ok])) if ok else None,
        'difficulty': dict(collections.Counter(r['maxnumber'] for r in records if 'maxnumber' in r)),
        'results_page_ms': ms(t_results),
    }


def ms(seconds):
    return None if seconds is None else round(seconds * 1000)


def prefill(client, fid, token, count, size, workers, bucket):
    """Fill the form through signed-in submissions (no challenge) and time them per bucket of responses."""
    curve, done, lock = [], [0], threading.Lock()
    times = collections.defaultdict(list)

    def one(_):
        s, _, t = client.request('POST', f'{client.app}/public/{fid}/{token}/submit', {'answers': answers(size)}, auth=True)
        with lock:
            if 200 <= s < 300:
                done[0] += 1
                times[done[0] // bucket].append(t)
        return s

    with cf.ThreadPoolExecutor(max_workers=workers) as pool:
        statuses = collections.Counter(pool.map(one, range(count)))
    for b in sorted(times):
        curve.append({'responses_up_to': (b + 1) * bucket, 'submit_ms_median': ms(statistics.median(times[b])), 'submit_ms_p95': ms(percentile(times[b], 95))})
    return {'requested': count, 'statuses': dict(statuses), 'curve': curve}


def markdown(report):
    lines = [f"# FormVox load test — {report['started']}", '', f"Instance: {report['base']}  ", f"Answer size: {report['answer_size']} chars, {report['per_user']} submissions per respondent", '']
    if report.get('prefill'):
        lines += ['## Time per submission as the form grows (signed in, no challenge)', '', '| responses stored | median ms | p95 ms |', '|---|---|---|']
        lines += [f"| {c['responses_up_to']} | {c['submit_ms_median']} | {c['submit_ms_p95']} |" for c in report['prefill']['curve']]
        lines += ['', f"Statuses: {report['prefill']['statuses']}", '']
    lines += [f"Browser retry: {'on (same submission id, pass, 60 s)' if report.get('retry', True) else 'off'}", '']
    lines += ['## Respondents at once', '', '| at once | accepted | stored | lost | outcomes | busy answers | errors | per s | submit p50 / p95 / p99 ms | total p95 ms | solve ms | difficulty | results page ms |', '|---|---|---|---|---|---|---|---|---|---|---|---|---|']
    for s in report['stages']:
        stored_delta = None if None in (s['stored_before'], s['stored_after']) else s['stored_after'] - s['stored_before']
        lines.append(f"| {s['concurrent']} | {s['accepted']}/{s['attempted']} | {stored_delta} | {s['lost']} | {s['outcomes']} | {s['busy_answers']} | {s['errors'] or '-'} | {s['throughput_per_s']} | "
                     f"{s['submit_ms'][50]} / {s['submit_ms'][95]} / {s['submit_ms'][99]} | {s['total_ms'][95]} | {s['solve_ms_median']} | {s['difficulty']} | {s['results_page_ms']} |")
    return '\n'.join(lines) + '\n'


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('--base', required=True)
    ap.add_argument('--user', required=True)
    ap.add_argument('--password-file', required=True)
    ap.add_argument('--stages', default='10,50,200', help='respondents at once, per stage')
    ap.add_argument('--per-user', type=int, default=5, help='submissions per respondent per stage')
    ap.add_argument('--answer-size', type=int, default=200, help='characters in the free-text answer')
    ap.add_argument('--prefill', type=int, default=0, help='responses to store before the stages')
    ap.add_argument('--prefill-workers', type=int, default=10)
    ap.add_argument('--prefill-bucket', type=int, default=500)
    ap.add_argument('--keep', action='store_true', help='keep the form afterwards')
    ap.add_argument('--no-retry', action='store_true', help='one try per submission, as before 3a-5 (for a baseline on an older server)')
    ap.add_argument('--out', default=None)
    args = ap.parse_args()

    password = open(args.password_file).read().strip()
    base = Client(args.base, args.user, password)
    # An app password: cheaper to check per request than the account password.
    s, data, _ = base.request('GET', f'{base.ocs}/core/getapppassword?format=json', auth=True)
    app_password = (data or {}).get('ocs', {}).get('data', {}).get('apppassword') if s == 200 else None
    client = Client(args.base, args.user, app_password or password)

    started = time.strftime('%Y-%m-%d %H:%M:%S')
    s, form, _ = client.request('POST', f'{client.app}/api/forms', {'title': f'Load test {started}'}, auth=True)
    fid = int(form['fileId'])
    s, upd, _ = client.request('PUT', f'{client.app}/api/form/{fid}', {
        'questions': [{'id': 'name', 'type': 'text', 'question': 'Name'}, {'id': 'note', 'type': 'textarea', 'question': 'Note'}],
        'settings': {'public_token': 'new', 'anonymous': True, 'allow_multiple': True},
    }, auth=True)
    token = upd['form']['settings']['public_token']
    report = {'started': started, 'base': args.base, 'form': fid, 'answer_size': args.answer_size, 'per_user': args.per_user, 'retry': not args.no_retry, 'stages': []}
    print(f'form {fid}', flush=True)
    try:
        if args.prefill:
            print(f'prefill {args.prefill} ...', flush=True)
            report['prefill'] = prefill(client, fid, token, args.prefill, args.answer_size, args.prefill_workers, args.prefill_bucket)
        with cf.ProcessPoolExecutor(max_workers=os.cpu_count()) as solver:
            for users in (int(x) for x in args.stages.split(',')):
                stage = run_stage(client, fid, token, users, args.per_user, args.answer_size, solver, not args.no_retry)
                report['stages'].append(stage)
                print(f"{users:>4} at once: {stage['accepted']}/{stage['attempted']} accepted, lost {stage['lost']}, outcomes {stage['outcomes']}, "
                      f"busy {stage['busy_answers']}, {stage['throughput_per_s']}/s, submit p95 {stage['submit_ms'][95]} ms, errors {stage['errors'] or '-'}", flush=True)
    finally:
        if not args.keep:
            client.request('DELETE', f'{client.app}/api/form/{fid}', auth=True)
        if app_password:
            client.request('DELETE', f'{client.ocs}/core/apppassword', auth=True)

    out = args.out or f'load-report-{time.strftime("%Y%m%d-%H%M%S")}'
    os.makedirs(out, exist_ok=True)
    with open(os.path.join(out, 'report.json'), 'w') as f:
        json.dump(report, f, indent=2)
    with open(os.path.join(out, 'report.md'), 'w') as f:
        f.write(markdown(report))
    print(f'report: {out}/report.md')
    lost = sum(s['lost'] or 0 for s in report['stages'])
    raise SystemExit(1 if lost else 0)


if __name__ == '__main__':
    main()
