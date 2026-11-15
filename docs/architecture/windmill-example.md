# Example: PDFs into a Nextcloud folder with Windmill

This example keeps the PDF of every response in a Nextcloud folder, in a subfolder per answer: one folder per school,
for example. It uses [Windmill](https://www.windmill.dev), an open-source automation server, but the steps carry over to
any server that can receive a webhook. It is an example to adapt, not part of FormVox. It was tested with FormVox 1.6.0
and Windmill CE 1.723.

## What it does

One Python script, run in two ways:

- **On every webhook** (`response.created`, `response.updated`). It checks the signature, fetches the PDF the webhook
  names with the form's API key, compares its SHA-256 and size, and puts it in the folder over WebDAV. Then it reads
  the file back.
- **On a schedule.** It goes through every response of the form with the external API and fetches each PDF that is not
  in its folder yet. That catches up on webhooks that never arrived: Windmill was down, or a webhook went with
  `"pdf": null` because its PDF could not be made in time.

It never overwrites or deletes a file:

- A PDF that is already there with the same content is skipped, so a webhook that arrives twice, or a scheduled run
  after a webhook, changes nothing. Nextcloud refuses the write when the file exists (`If-None-Match: *`), also when
  two runs write at the same moment.
- A changed response (`response.updated`) gets a new PDF. The script puts it next to the original as
  `…_changed-<time>.pdf`, so the PDF of the response as it was sent stays.
- A deletion (`response.deleted`) is not passed on: whether a stored PDF goes too is your choice.

## Before you start

- **The form:** turn on **Keep a PDF of every response** in the share dialog. Only responses sent after that have a
  PDF; for older ones the API answers `409`, and the scheduled run counts them under `no_pdf`.
- **An API key** of the form with the permission **Read responses**.
- **A Nextcloud account** that may write to the target folder, a team folder for example, with an app password
  (**Personal settings → Security**).
- **Nextcloud's `overwrite.cli.url`** set to its public address: the webhook's `pdf.url` comes from it. When Windmill
  runs on Nextcloud's own network, also set `allow_local_remote_servers` (see [Configuration](../admin/configuration.md)).
- **In Windmill,** three variables, each marked **secret**: the webhook's secret, the API key and the app password.

## 1. The script

Create a Python script in Windmill and paste the code below. Adapt the settings at the top:

- `NEXTCLOUD_URL`: Nextcloud's address as in the webhook's `pdf.url`. With or without `/index.php` in `pdf.url`, both
  are accepted; a `pdf.url` anywhere else is refused, so the API key is never sent elsewhere.
- `FORM_ID`: the form's id, `form.id` in the webhook and `{fileId}` in the API.
- `FOLDER_QUESTION`: the id of the question whose answer names the subfolder. Characters other than letters, digits,
  spaces and `.,()&-` become `_`, and an empty answer goes to `unsorted`. Leave it empty for no subfolders.
- The paths of the three variables.

```python
# Windmill script (Python): keep the PDF of every FormVox response in a Nextcloud folder.
#
# Two ways to run it (docs/architecture/windmill-example.md):
#  - from an HTTP route that receives the form's webhook: the preprocessor hands main() the raw body and the
#    signature headers, and the PDF the webhook names is fetched, checked and stored;
#  - on a schedule, without arguments: every response of the form is checked, and a PDF that is missing from the
#    folder is fetched and stored. That catches up on webhooks that never arrived.
#
# Nothing is ever overwritten or deleted. A PDF that is already there with the same content is skipped, so a webhook
# that arrives twice, or a scheduled run after a webhook, changes nothing.
import base64
import hashlib
import hmac
import json
import re
import time
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET
from email.message import Message

import wmill

# --- Settings: adapt these -----------------------------------------------------------------------------------------
NEXTCLOUD_URL = "https://cloud.example.com"  # Nextcloud's address as in the webhook's pdf.url, no trailing slash
FORM_ID = 12345  # the form's id: form.id in the webhook, {fileId} in the API
NC_USER = "formvox-archive"  # the Nextcloud account that writes the PDFs
TARGET_FOLDER = "Forms/Registration"  # a folder of that account; created when it is missing
FOLDER_QUESTION = "q_school"  # the question whose answer names the subfolder, or "" for no subfolders
# Windmill variables, each marked secret:
WEBHOOK_SECRET_VAR = "f/formvox/webhook_secret"  # the webhook's secret, shown once when the webhook is created
API_KEY_VAR = "f/formvox/api_key"  # an API key of the form with the permission "Read responses"
NC_PASSWORD_VAR = "f/formvox/nc_app_password"  # an app password of NC_USER
MAX_AGE_SECONDS = 300  # a webhook older than this is refused
# --------------------------------------------------------------------------------------------------------------------

API = f"{NEXTCLOUD_URL}/apps/formvox/api/v1/external/forms/{FORM_ID}/responses"
# pdf.url has /index.php/ in it when Nextcloud does not use pretty URLs.
PDF_URL_PREFIXES = (API + "/", API.replace("/apps/", "/index.php/apps/", 1) + "/")


def verify_signature(raw_body: str, signature: str, timestamp: str, secret: str, now: float | None = None) -> str | None:
    """None when the webhook is genuine and fresh; otherwise why it is refused."""
    if not signature or not timestamp.isdigit():
        return "missing_signature_headers"
    expected = "v1=" + hmac.new(secret.encode(), f"{timestamp}.{raw_body}".encode(), hashlib.sha256).hexdigest()
    if not hmac.compare_digest(expected, signature):
        return "bad_signature"
    if abs((time.time() if now is None else now) - int(timestamp)) > MAX_AGE_SECONDS:
        return "stale_timestamp"
    return None


def http(method: str, url: str, headers: dict, data: bytes | None = None) -> tuple[int, Message, bytes]:
    request = urllib.request.Request(url, data=data, method=method, headers=headers)
    try:
        with urllib.request.urlopen(request, timeout=60) as answer:
            return answer.status, answer.headers, answer.read()
    except urllib.error.HTTPError as error:
        return error.code, error.headers, error.read()


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def subfolder_for(answers: dict) -> list[str]:
    """The folder a response's PDF goes into, as path parts: TARGET_FOLDER, then the answer to FOLDER_QUESTION."""
    parts = [part for part in TARGET_FOLDER.split("/") if part]
    if FOLDER_QUESTION:
        value = answers.get(FOLDER_QUESTION)
        if isinstance(value, list):
            value = ", ".join(str(item) for item in value)
        # No "/" and no name of only dots: an answer cannot point outside TARGET_FOLDER.
        parts.append(re.sub(r"[^\w .,()&-]", "_", str(value or "")).strip(" .") or "unsorted")
    return parts


def changed_name(filename: str, generated_at: str | None) -> str:
    """A changed response's new PDF goes next to the original, never over it."""
    stamp = re.sub(r"\D", "", (generated_at or "")[:19]) or time.strftime("%Y%m%d%H%M%S")
    stem = filename[:-4] if filename.lower().endswith(".pdf") else filename
    return f"{stem}_changed-{stamp}.pdf"


class Folder:
    """The Nextcloud account's files over WebDAV."""

    def __init__(self, app_password: str):
        self.base = f"{NEXTCLOUD_URL}/remote.php/dav/files/{urllib.parse.quote(NC_USER)}"
        self.auth = {"Authorization": "Basic " + base64.b64encode(f"{NC_USER}:{app_password}".encode()).decode()}
        self.names: dict[str, set[str]] = {}

    def url(self, parts: list[str]) -> str:
        return self.base + "".join("/" + urllib.parse.quote(part) for part in parts)

    def file_names(self, parts: list[str]) -> set[str]:
        """The names of the files in a folder, read once per run; empty when the folder does not exist yet."""
        key = "/".join(parts)
        if key not in self.names:
            body = b'<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/></d:prop></d:propfind>'
            status, _, answer = http("PROPFIND", self.url(parts) + "/", {**self.auth, "Depth": "1"}, body)
            names = set()
            if status == 207:
                for href in ET.fromstring(answer).iter("{DAV:}href"):
                    names.add(urllib.parse.unquote((href.text or "").rstrip("/").rsplit("/", 1)[-1]))
            self.names[key] = names
        return self.names[key]

    def store(self, parts: list[str], filename: str, pdf: bytes) -> str:
        """Put the PDF into the folder unless a file of that name is there, then read it back."""
        for depth in range(1, len(parts) + 1):
            status, _, _ = http("MKCOL", self.url(parts[:depth]), self.auth)
            if status not in (201, 405):  # 405: the folder is there already
                return f"mkcol_failed_{status}"
        target = self.url(parts + [filename])
        # If-None-Match: * makes Nextcloud refuse (412) when the file exists, also when another run just wrote it.
        status, _, _ = http("PUT", target, {**self.auth, "Content-Type": "application/pdf", "If-None-Match": "*"}, pdf)
        if status == 412:
            status, _, existing = http("GET", target, self.auth)
            return "already_stored" if status == 200 and sha256(existing) == sha256(pdf) else "different_file_exists"
        if status not in (201, 204):
            return f"put_failed_{status}"
        status, _, back = http("GET", target, self.auth)
        if status != 200 or sha256(back) != sha256(pdf):
            return "readback_mismatch"
        self.names.setdefault("/".join(parts), set()).add(filename)
        return "stored"


def handle_webhook(raw_string: str, signature: str, timestamp: str, header_event_id: str) -> dict:
    refused = verify_signature(raw_string, signature, timestamp, wmill.get_variable(WEBHOOK_SECRET_VAR))
    if refused:
        return {"ok": False, "reason": refused}
    event = json.loads(raw_string)
    data = event.get("data") or {}
    result = {"ok": True, "event": event.get("event"), "event_id": event.get("event_id"), "response_id": data.get("id")}
    if header_event_id and header_event_id != event.get("event_id"):
        return {**result, "ok": False, "reason": "event_id_mismatch"}
    if (event.get("form") or {}).get("id") != FORM_ID:
        return {**result, "ok": False, "reason": "other_form"}
    if event.get("event") not in ("response.created", "response.updated"):
        return {**result, "action": "ignored"}  # response.deleted: removing the stored PDF is your choice
    pdf = event.get("pdf")
    if not pdf:
        return {**result, "action": "no_pdf"}  # the scheduled run fetches it once it is there
    if not pdf["url"].startswith(PDF_URL_PREFIXES):
        return {**result, "ok": False, "reason": "foreign_pdf_url"}  # never send the API key anywhere else
    status, _, body = http("GET", pdf["url"], {"X-FormVox-API-Key": wmill.get_variable(API_KEY_VAR)})
    if status != 200 or sha256(body) != pdf["sha256"] or len(body) != pdf["size"]:
        return {**result, "ok": False, "reason": "pdf_not_verified", "http_status": status}
    filename = pdf["filename"]
    if event["event"] == "response.updated":
        filename = changed_name(filename, pdf.get("generated_at"))
    parts = subfolder_for(data.get("applicable_answers") or {})
    outcome = Folder(wmill.get_variable(NC_PASSWORD_VAR)).store(parts, filename, body)
    return {**result, "ok": outcome in ("stored", "already_stored"), "action": outcome, "path": "/".join(parts + [filename])}


def catch_up() -> dict:
    """Every response of the form: fetch and store each PDF that is not in its folder yet."""
    key = {"X-FormVox-API-Key": wmill.get_variable(API_KEY_VAR)}
    folder = Folder(wmill.get_variable(NC_PASSWORD_VAR))
    counts: dict[str, int] = {}
    after = None
    while True:
        query = "?limit=200" + (f"&after={urllib.parse.quote(after)}" if after else "")
        status, _, body = http("GET", API + query, {**key, "Accept": "application/json"})
        if status != 200:
            return {"ok": False, "reason": "list_failed", "http_status": status, "counts": counts}
        page = json.loads(body)
        for response in page["responses"]:
            parts = subfolder_for(response.get("applicable_answers") or {})
            if any(response["id"] in name for name in folder.file_names(parts)):  # a PDF's name holds the whole id
                outcome = "present"
            else:
                status, headers, pdf = http("GET", f"{API}/{urllib.parse.quote(response['id'])}/pdf", key)
                if status == 200 and pdf.startswith(b"%PDF-"):
                    disposition = Message()
                    disposition["Content-Disposition"] = headers.get("Content-Disposition", "")
                    outcome = folder.store(parts, disposition.get_filename() or f"{response['id']}.pdf", pdf)
                elif status == 409:
                    reason = json.loads(pdf).get("reason", "")
                    if reason == "pdf_disabled":  # the form keeps no PDFs: nothing to catch up on
                        return {"ok": False, "reason": "pdf_disabled", "counts": counts}
                    outcome = f"no_pdf:{reason}"  # the API has no PDF for this response (yet); the reason says why
                elif status == 404:
                    outcome = "deleted_meanwhile"
                else:
                    outcome = f"fetch_failed_{status}"
            counts[outcome] = counts.get(outcome, 0) + 1
        after = page.get("next")
        if not after:
            return {"ok": not any(k.startswith(("fetch_failed", "put_failed", "mkcol_failed", "readback", "different"))
                                  for k in counts), "counts": counts}


def preprocessor(event: dict):
    """Windmill runs this first: for the HTTP route, hand main() the raw body and FormVox's headers.

    Windmill also runs it for a run through its API or CLI, so only kind "http" counts as a webhook.
    """
    headers = {str(name).lower(): value for name, value in (event.get("headers") or {}).items()}
    return {
        "from_route": event.get("kind") == "http",
        "raw_string": event.get("raw_string") or "",
        "x_formvox_signature": headers.get("x-formvox-signature", ""),
        "x_formvox_timestamp": headers.get("x-formvox-timestamp", ""),
        "x_formvox_event_id": headers.get("x-formvox-event-id", ""),
    }


def main(from_route: bool = False, raw_string: str = "", x_formvox_signature: str = "", x_formvox_timestamp: str = "",
         x_formvox_event_id: str = ""):
    if from_route:
        return handle_webhook(raw_string, x_formvox_signature, x_formvox_timestamp, x_formvox_event_id)
    return catch_up()
```

## 2. Receive the webhook: an HTTP route

Add an HTTP route trigger to the script:

| Setting | Value | Why |
|---|---|---|
| Method | `POST` | |
| Path | for example `formvox/registration` | The webhook URL becomes `https://<windmill>/api/r/<path>`. |
| Authentication | None | FormVox cannot send headers of your choosing, so a Windmill token would have to go in the URL, where everyone who can see the form's webhooks reads it. The signature does the authenticating: without a valid one the script does nothing. |
| Raw body | On | The signature covers the exact bytes FormVox sent. |
| Run asynchronously | On | Windmill answers at once, so FormVox does not wait for the PDF to be fetched and stored. |

Then add a webhook to the form with the route's URL and the events `response.created` and `response.updated`, and put
the secret FormVox shows once into the webhook-secret variable.

The script's `preprocessor` hands the raw body and the `X-FormVox-*` headers to `main`. Windmill runs it for the HTTP
route, but also for a run through its API or CLI; only a request through the HTTP route counts as a webhook, and any
other run catches up.

Because the route runs asynchronously, FormVox always sees the webhook delivered, also when the script fails
afterwards. The scheduled run makes up for that.

## 3. Catch up: a schedule

Add a schedule to the same script, without arguments, for example every hour:
`0 0 * * * *`. Windmill's cron starts with a field for the seconds. To catch up at once, run the script by hand.

## What a run returns

A webhook run returns `ok`, `event`, `event_id`, `response_id`, and what it did in `action`:

| `action` | Meaning |
|---|---|
| `stored` | The PDF is in the folder and was read back; `path` says where. |
| `already_stored` | A file of that name with the same content was there: nothing changed. |
| `different_file_exists` | A file of that name with other content is there. It is left alone, and `ok` is false. |
| `no_pdf` | The webhook has `"pdf": null`. The scheduled run fetches the PDF once it is there. |
| `ignored` | Another event, such as `response.deleted`. |

A refused webhook returns `ok: false` with a `reason`: `missing_signature_headers`, `bad_signature`, `stale_timestamp`
(older than five minutes), `event_id_mismatch`, `other_form` (a webhook of another form), `foreign_pdf_url` (a
`pdf.url` outside `NEXTCLOUD_URL`) or `pdf_not_verified` (the PDF could not be fetched, or its SHA-256 or size
differs).

A scheduled run returns `ok` and `counts`, the number of responses per outcome: `present` (a file with the response id
in its name is in the folder), `stored`, `already_stored`, `no_pdf:<reason>` (the API answered `409` with that reason,
such as `pdf_not_ready`), `deleted_meanwhile`, and failures such as `fetch_failed_<status>`. On a form that keeps no
PDFs it stops with `reason: pdf_disabled`.

## Good to know

- **Duplicates.** FormVox gives every event an `event_id`, so that a receiver can skip one it had. This script keeps no
  list of them: storing is idempotent, so a repeated event, or one replayed within the five minutes its signature is
  valid, ends as `already_stored`.
- **A missed `response.updated` is not caught up.** The list of responses carries no PDF checksum, so the scheduled run
  only sees that a PDF of the response is there.
- **Personal data in Windmill.** A webhook carries the respondent's answers, and Windmill keeps every run's arguments
  and result in its job history. Check how long your Windmill keeps them.
- **Testing.** Use a test form with made-up answers, and a receiver of your own; never a public request-inspection
  service.
