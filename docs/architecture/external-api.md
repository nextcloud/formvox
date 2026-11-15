# External API & Webhooks

FormVox provides a secure External API for programmatic access to forms and responses from external systems. This API uses API keys for authentication instead of Nextcloud session cookies, making it ideal for integrations with third-party systems.

## Overview

The External API allows you to:
- Read form definitions and question schemas
- List, create, update, and delete responses
- Receive real-time webhook notifications when responses change

## Authentication

### API Keys

API keys are generated per-form. Each key's bcrypt hash is kept sealed in the database (`formvox_secrets`), not in
the `.fvform` file; only the form definition itself still lives in the file. Each key has configurable permissions.

**API Key Format:**
```
fvx_<32 random alphanumeric characters>
```

Example: `fvx_v3eaAuWwIvgUe2NQMcy826smmlFdX0Jd`

### Using API Keys

Include the API key in the `X-FormVox-API-Key` header:

```bash
curl -X GET \
  -H "X-FormVox-API-Key: fvx_your_api_key_here" \
  https://your-nextcloud.com/apps/formvox/api/v1/external/forms/{fileId}
```

### Permissions

Each API key can have one or more of these permissions:

| Permission | Description |
|------------|-------------|
| `read_form` | Read form title, description, and settings |
| `read_responses` | List and read individual responses |
| `write_responses` | Create and update responses |
| `delete_responses` | Delete responses |

A key works only while the person who created it may still do this with the form in Nextcloud: reading the form needs
their read right; reading responses, also as a PDF, their download right, as an export in the app does; creating,
changing and deleting responses their edit right. When that right is gone, the key gets `401`.

### Limits

Each client address may make 100 requests a minute to the routes that read (the form, its schema, the responses, one
response, its PDF) and 50 a minute to those that write (create, update, delete). Above that, Nextcloud answers
`429 Too Many Requests`. Requests with a wrong key are also slowed down by Nextcloud's brute-force protection.

## API Endpoints

Base URL: `/apps/formvox/api/v1/external`

### Get Form

```http
GET /forms/{fileId}
```

**Required Permission:** `read_form`

**Response:**
```json
{
  "id": 12345,
  "title": "Customer Feedback Survey",
  "description": "Help us improve our service",
  "settings": {
    "allow_multiple": false
  }
}
```

### Get Form Schema

```http
GET /forms/{fileId}/schema
```

**Required Permission:** `read_form`

Returns the full question structure for building integrations.

**Response:**
```json
{
  "questions": [
    {
      "id": "q1",
      "type": "text",
      "question": "What is your name?",
      "required": true
    },
    {
      "id": "q2",
      "type": "single",
      "question": "How satisfied are you?",
      "required": true,
      "options": [
        {"label": "Very satisfied", "value": "5"},
        {"label": "Satisfied", "value": "4"},
        {"label": "Neutral", "value": "3"},
        {"label": "Dissatisfied", "value": "2"},
        {"label": "Very dissatisfied", "value": "1"}
      ]
    }
  ]
}
```

### List Responses

```http
GET /forms/{fileId}/responses
GET /forms/{fileId}/responses?limit=500&after={next}
```

**Required Permission:** `read_responses`

Without parameters every response comes back at once, oldest first. For large forms, ask for pages: `limit` (at most
1000) responses after `after`. A paged answer carries `next`, the `after` for the following page, or `null` on the last
page; `count` is always the form's total. Treat `next` as an opaque value: pass it back as it is. A response removed
meanwhile does not end the pages early.

**Response:**
```json
{
  "responses": [
    {
      "id": "abc123-def456",
      "submitted_at": "2026-01-27T10:30:00+00:00",
      "respondent": {
        "type": "anonymous",
        "fingerprint": "sha256:..."
      },
      "answers": {
        "q1": "John Doe",
        "q2": "5"
      },
      "applicable_answers": {
        "q1": "John Doe",
        "q2": "5"
      },
      "not_applicable": ["q3"]
    }
  ],
  "count": 1
}
```

`answers` holds every answer as stored. `applicable_answers` holds the answers to the questions the respondent was
shown, a subset of `answers` in the same shape, and `not_applicable` lists the ids of the questions that did not apply
to them: hidden by a condition, in a hidden section, or on a page that routing skipped. A single response (below) and
a changed one (`PUT`) carry the same two fields.

### Get Single Response

```http
GET /forms/{fileId}/responses/{responseId}
```

**Required Permission:** `read_responses`

### Get a Response's PDF

```http
GET /forms/{fileId}/responses/{responseId}/pdf
```

**Required Permission:** `read_responses`

Returns `application/pdf`: the response as its respondent saw the form, with only the questions they were shown. It is
made once and kept, so it is the same document the confirmation email carried and the one a webhook names (`sha256`).
A changed response gets a new one.

The route works only on a form whose owner turned on **Keep a PDF of every response**; any other form answers `409`
with reason `pdf_disabled` (`PDF generation is disabled for this form`). It gives only the PDF the background job
kept: asked before that job ran, it answers `409` with reason `pdf_not_ready` (`PDF is not ready yet`). Ask again in a
moment, or wait for the webhook, which names the PDF once it is there.

The PDF has the same name wherever it goes (this route, a download in the app, the ZIP, the confirmation email, the
webhook's `filename`): `{title}_{date}_{response id}.pdf`. The title is in plain ASCII — ä, ö, ü and ß become ae, oe, ue
and ss, other accents are dropped, every other character becomes `-`, at most 60 characters, and `response` when no
letter is left. The date is the day the response was sent, as the PDF shows it. The response id is always whole.

### Create Response

```http
POST /forms/{fileId}/responses
```

**Required Permission:** `write_responses`

**Request Body:**
```json
{
  "answers": {
    "q1": "Jane Smith",
    "q2": "4"
  }
}
```

**Response:**
```json
{
  "id": "new-response-id",
  "submitted_at": "2026-01-27T11:00:00+00:00"
}
```

### Update Response

```http
PUT /forms/{fileId}/responses/{responseId}
```

**Required Permission:** `write_responses`

**Request Body:**
```json
{
  "answers": {
    "q1": "Jane Smith (Updated)",
    "q2": "5"
  }
}
```

The answers you send replace all of the response's answers: an answer you leave out is removed, so send every answer
the response should keep. The response gets a new PDF, which the webhook `response.updated` names.

### Delete Response

```http
DELETE /forms/{fileId}/responses/{responseId}
```

**Required Permission:** `delete_responses`

## Error Responses

Every error carries a `reason` code, so an integration can act on it without parsing the (translated) `error` text:

```json
{
  "error": "Invalid API key",
  "reason": "invalid_api_key"
}
```

`error` is a message for people; `reason` is a fixed code for your integration to act on.

| HTTP Status | `reason` | Meaning |
|-------------|----------|---------|
| 400 | `invalid_input` | The request is not what the route takes |
| 400 | `invalid_answer` | An answer the form does not take; `question` names its question |
| 401 | `missing_api_key` | No `X-FormVox-API-Key` header |
| 401 | `invalid_api_key` | The key is wrong, or its creator may no longer do this with the form in Nextcloud |
| 403 | `key_permission` | The key lacks the permission; `permission` names it |
| 404 | `not_found` | No such form or response |
| 422 | `broken_form` | The file is not a FormVox form |
| 429 | | Too many requests (see Limits) |
| 500 | `internal_error` | The server failed; `requestId` helps your administrator find it in the Nextcloud log |
| 503 | `busy` | Another save holds the form for a moment: try again after the seconds in `Retry-After` |

---

## Webhooks

Webhooks send HTTP POST requests to your server when events occur in FormVox.

### Webhook Configuration

Each webhook has:
- **URL**: Where to send the webhook
- **Secret**: Used to sign requests (format: `whsec_<32 chars>`)
- **Events**: Which events trigger the webhook
- **Enabled**: Toggle webhook on/off

### Available Events

| Event | Description |
|-------|-------------|
| `response.created` | A new response was submitted |
| `response.updated` | An existing response was modified |
| `response.deleted` | A response was deleted; `data` holds only its `id` |

### Webhook Payload

```json
{
  "id": "abc123-def456:response.created",
  "event_id": "abc123-def456:response.created",
  "event": "response.created",
  "timestamp": "2026-01-27T10:30:00+00:00",
  "form": {
    "id": 12345,
    "fileId": 12345,
    "title": "Customer Feedback Survey"
  },
  "data": {
    "id": "abc123-def456",
    "submitted_at": "2026-01-27T10:30:00+00:00",
    "respondent": {
      "type": "anonymous"
    },
    "answers": {
      "q1": "John Doe",
      "q2": "5"
    },
    "applicable_answers": {
      "q1": "John Doe",
      "q2": "5"
    },
    "not_applicable": ["q3"]
  },
  "pdf": {
    "url": "https://cloud.example.com/apps/formvox/api/v1/external/forms/12345/responses/abc123-def456/pdf",
    "sha256": "9f2c…",
    "size": 25731,
    "filename": "Registration_2026-11-10_abc123-def456.pdf",
    "status": "stored",
    "generated_at": "2026-01-27T10:30:02+00:00"
  }
}
```

| Field | Description |
|-------|-------------|
| `id`, `event_id` | The event's id, the same under both names and in `X-FormVox-Event-Id`: the same on every try of one event |
| `event` | The event (see above) |
| `timestamp` | When this delivery was made, ISO 8601 with offset |
| `form.id`, `form.fileId` | The form's id, the same under both names: the id the external API knows it by |
| `form.title` | The form's title |
| `data` | The response as the API returns it: `id`, `submitted_at`, `respondent`, `answers`, `applicable_answers`, `not_applicable`; for `response.deleted` only `id` |
| `data.applicable_answers` | The answers to the questions the respondent was shown: a subset of `answers`, same shape |
| `data.not_applicable` | The ids of the questions that did not apply: hidden by a condition, in a hidden section, or on a page routing skipped |
| `pdf` | Where the response's PDF is (below), or `null` |
| `pdf.status` | `stored`: the PDF the webhook names is kept and is what the API gives |
| `pdf.generated_at` | When the kept PDF was made, ISO 8601 with offset |

`form` names the form by `fileId`, the id the external API knows it by, and its `title`; `id` is the same number
under the name integrations expect.

`response.created` and `response.updated` name the response's PDF in `pdf`: fetch it from `url` with an API key that
may read responses, and compare its SHA-256 with `sha256`; `filename` is the name the PDF goes by everywhere. The PDF
itself is never sent along. A webhook whose PDF cannot be made yet is held and tried again; if the PDF still cannot
be kept after three tries, the webhook is sent with `"pdf": null`, so the response itself always arrives, and the PDF
can be fetched through the API once it is there. `response.deleted` carries `"pdf": null` too, and so does every event
of a form that does not keep PDFs (**Keep a PDF of every response** off): such a webhook is sent at once, and the PDF
route answers `409` for that form.

A deleted response is gone, also from the webhook: `response.deleted` sends `"data": {"id": "abc123-def456"}` and nothing
more. Webhooks are sent shortly after the event by a background job, in order per response, and again (for about an
hour) when the receiver cannot be reached. A response deleted before its `response.created` went out sends only the
deletion: treat a deletion of an id you do not know as done, and use `X-FormVox-Event-Id` to skip an event you had.

### Request Headers

| Header | Description |
|--------|-------------|
| `Content-Type` | `application/json` |
| `User-Agent` | `FormVox-Webhook/1.0` |
| `X-FormVox-Event` | Event type (e.g., `response.created`) |
| `X-FormVox-Signature` | HMAC signature for verification |
| `X-FormVox-Timestamp` | Unix timestamp of the request |
| `X-FormVox-Event-Id` | The same on every try of one event, different for every update of a response: use it to recognise a repeat. It is also the payload's signed `id` |

### Signature Verification

Webhooks are signed using HMAC-SHA256. Always verify the signature to ensure the request came from FormVox.

**Signature Format:**
```
v1=<HMAC-SHA256 hash>
```

**Verification Algorithm:**

1. Get the `X-FormVox-Timestamp` and `X-FormVox-Signature` headers
2. Create the signed payload: `{timestamp}.{request_body}`
3. Compute HMAC-SHA256 of the signed payload using your webhook secret
4. Compare with the signature (use constant-time comparison)

**PHP Example:**

```php
function verifyWebhook(string $payload, string $signature, string $secret, int $timestamp): bool
{
    // Check timestamp is recent (prevent replay attacks)
    if (abs(time() - $timestamp) > 300) {
        return false; // Reject if older than 5 minutes
    }

    $signedPayload = $timestamp . '.' . $payload;
    $expectedSignature = 'v1=' . hash_hmac('sha256', $signedPayload, $secret);

    return hash_equals($expectedSignature, $signature);
}

// Usage
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_FORMVOX_SIGNATURE'];
$timestamp = (int) $_SERVER['HTTP_X_FORMVOX_TIMESTAMP'];
$secret = 'whsec_your_secret_here';

if (!verifyWebhook($payload, $signature, $secret, $timestamp)) {
    http_response_code(401);
    exit('Invalid signature');
}

$data = json_decode($payload, true);
// Process webhook...
```

**Node.js Example:**

```javascript
const crypto = require('crypto');

function verifyWebhook(payload, signature, secret, timestamp) {
    // Check timestamp is recent
    if (Math.abs(Date.now() / 1000 - timestamp) > 300) {
        return false;
    }

    const signedPayload = `${timestamp}.${payload}`;
    const expectedSignature = 'v1=' + crypto
        .createHmac('sha256', secret)
        .update(signedPayload)
        .digest('hex');

    return crypto.timingSafeEqual(
        Buffer.from(expectedSignature),
        Buffer.from(signature)
    );
}

// Express.js example
app.post('/webhook', express.raw({type: 'application/json'}), (req, res) => {
    const payload = req.body.toString();
    const signature = req.headers['x-formvox-signature'];
    const timestamp = parseInt(req.headers['x-formvox-timestamp']);
    const secret = 'whsec_your_secret_here';

    if (!verifyWebhook(payload, signature, secret, timestamp)) {
        return res.status(401).send('Invalid signature');
    }

    const data = JSON.parse(payload);
    console.log('Webhook received:', data.event);

    res.status(200).send('OK');
});
```

**Python Example:**

```python
import hmac
import hashlib
import time

def verify_webhook(payload: str, signature: str, secret: str, timestamp: int) -> bool:
    # Check timestamp is recent
    if abs(time.time() - timestamp) > 300:
        return False

    signed_payload = f"{timestamp}.{payload}"
    expected_signature = 'v1=' + hmac.new(
        secret.encode(),
        signed_payload.encode(),
        hashlib.sha256
    ).hexdigest()

    return hmac.compare_digest(expected_signature, signature)

# Flask example
from flask import Flask, request

app = Flask(__name__)

@app.route('/webhook', methods=['POST'])
def webhook():
    payload = request.get_data(as_text=True)
    signature = request.headers.get('X-FormVox-Signature')
    timestamp = int(request.headers.get('X-FormVox-Timestamp'))
    secret = 'whsec_your_secret_here'

    if not verify_webhook(payload, signature, secret, timestamp):
        return 'Invalid signature', 401

    data = request.json
    print(f"Webhook received: {data['event']}")

    return 'OK', 200
```

### Testing Webhooks

Test with a receiver you run yourself and a test form with made-up answers. A webhook carries the respondent's
answers, so do not send it to a public request-inspection service.

1. Run a receiver of your own, such as the Flask example above, or use your automation server: see
   [Example: PDFs into a Nextcloud folder with Windmill](windmill-example.md)
2. In FormVox, add a webhook with its address. For an address on Nextcloud's own network, set
   `allow_local_remote_servers` first (see [Configuration](../admin/configuration.md))
3. Submit a response; the webhook goes out from a background job shortly after
4. Check what your receiver got, and that it accepts the signature

### Best Practices

1. **Always verify signatures** - Don't process webhooks without verification
2. **Check timestamps** - Reject requests older than 5 minutes
3. **Respond quickly** - Return 200 OK within 10 seconds
4. **Handle retries** - The same event can arrive more than once: skip an `event_id` you already processed, or make processing idempotent
5. **Use HTTPS** - Always use secure URLs for webhook endpoints

---

## Managing API Keys & Webhooks

### Via the UI

1. Open your form in FormVox
2. Click the **Share** button
3. Expand the **API & Integrations** section
4. Use the interface to:
   - Create/delete API keys with specific permissions
   - Add/edit/delete webhooks
   - Copy API keys (only visible once!)
   - Test webhook URLs

### Security Notes

- **API keys are shown only once** when created. Store them securely.
- API key hashes (bcrypt) and webhook secrets are kept sealed in the database, not in the `.fvform` file. The file
  holds only the form definition, so no file route (download, WebDAV, sync, versions, a ZIP) can expose them.
- A form that still predates the 1.6.0 migration (see [Where FormVox keeps
  data](../admin/configuration.md#where-formvox-keeps-data)) keeps `api_keys` and `webhooks` in its file until it is
  moved into the database. Downloading a `.fvform` file via WebDAV always strips them from the body regardless, as a
  second line of defense.

## Next Steps

- [API Reference](api-reference.md) - Internal API documentation
- [File Format](file-format.md) - Understanding the data structure
- [Example: PDFs into a Nextcloud folder with Windmill](windmill-example.md) - Webhook, PDF check and catch-up in one script
