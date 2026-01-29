# External API & Webhooks

FormVox provides a secure External API for programmatic access to forms and responses from external systems. This API uses API keys for authentication instead of Nextcloud session cookies, making it ideal for integrations with third-party systems.

## Overview

The External API allows you to:
- Read form definitions and question schemas
- List, create, update, and delete responses
- Receive real-time webhook notifications when responses change

## Authentication

### API Keys

API keys are generated per-form and stored securely as bcrypt hashes in the `.fvform` file. Each key has configurable permissions.

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
    "expires_at": null,
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
```

**Required Permission:** `read_responses`

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
      }
    }
  ],
  "count": 1
}
```

### Get Single Response

```http
GET /forms/{fileId}/responses/{responseId}
```

**Required Permission:** `read_responses`

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

### Delete Response

```http
DELETE /forms/{fileId}/responses/{responseId}
```

**Required Permission:** `delete_responses`

## Error Responses

```json
{
  "error": "Unauthorized"
}
```

| HTTP Status | Description |
|-------------|-------------|
| 401 | Missing or invalid API key |
| 403 | API key lacks required permission |
| 404 | Form or response not found |
| 400 | Invalid request data |

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
| `response.deleted` | A response was deleted |

### Webhook Payload

```json
{
  "event": "response.created",
  "timestamp": "2026-01-27T10:30:00+00:00",
  "form": {
    "title": "Customer Feedback Survey"
  },
  "data": {
    "id": "abc123-def456",
    "submitted_at": "2026-01-27T10:30:00+00:00",
    "respondent": {
      "type": "anonymous",
      "fingerprint": "sha256:..."
    },
    "answers": {
      "q1": "John Doe",
      "q2": "5"
    }
  }
}
```

### Request Headers

| Header | Description |
|--------|-------------|
| `Content-Type` | `application/json` |
| `User-Agent` | `FormVox-Webhook/1.0` |
| `X-FormVox-Event` | Event type (e.g., `response.created`) |
| `X-FormVox-Signature` | HMAC signature for verification |
| `X-FormVox-Timestamp` | Unix timestamp of the request |

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

Use services like [webhook.site](https://webhook.site) to test your webhook configuration:

1. Go to webhook.site and copy your unique URL
2. In FormVox, add a new webhook with this URL
3. Submit a response to your form
4. Check webhook.site to see the incoming request

### Best Practices

1. **Always verify signatures** - Don't process webhooks without verification
2. **Check timestamps** - Reject requests older than 5 minutes
3. **Respond quickly** - Return 200 OK within 10 seconds
4. **Handle retries** - Webhooks may be sent multiple times
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
- API key hashes (bcrypt) are stored in the `.fvform` file
- Webhook secrets are stored in plaintext in the `.fvform` file
- When downloading a `.fvform` file via WebDAV, `api_keys` and `webhooks` are automatically stripped for security

## Next Steps

- [API Reference](api-reference.md) - Internal API documentation
- [File Format](file-format.md) - Understanding the data structure
