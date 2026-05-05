// Web Worker that solves an ALTCHA proof-of-work challenge off the main
// thread. Implemented inline (no imports) so webpack emits a self-contained
// classic worker — Nextcloud's CSP only allows `worker-src blob:`, and
// imports inside a module worker break when loaded from a Blob URL.

const encoder = new TextEncoder();

async function sha256Hex(input) {
  const buf = await crypto.subtle.digest('SHA-256', encoder.encode(input));
  return [...new Uint8Array(buf)].map((b) => b.toString(16).padStart(2, '0')).join('');
}

let aborted = false;

self.onmessage = async (message) => {
  const { type, payload } = message.data || {};
  if (type === 'abort') {
    aborted = true;
    return;
  }
  if (type !== 'work') return;

  aborted = false;
  const { challenge, salt, max = 1_000_000, start = 0 } = payload || {};
  const startedAt = Date.now();

  // Linear search: hash(salt + n) === challenge
  for (let n = start; n <= max; n++) {
    if (aborted) {
      self.postMessage(null);
      return;
    }
    // Yield to the event loop every 5000 iterations so abort messages get through.
    if (n % 5000 === 0 && n > start) {
      await new Promise((r) => setTimeout(r, 0));
    }
    // eslint-disable-next-line no-await-in-loop
    const hex = await sha256Hex(salt + n);
    if (hex === challenge) {
      self.postMessage({
        number: n,
        took: Date.now() - startedAt,
        worker: true,
      });
      return;
    }
  }
  self.postMessage(null);
};
