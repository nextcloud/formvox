# API Reference

FormVox provides a REST API for programmatic access to forms and responses.

## Authentication

### Internal API

For authenticated Nextcloud users:
- Uses Nextcloud session cookies
- Or basic authentication with app password

```bash
# Using app password
curl -u "username:app-password" \
  https://your-nextcloud.com/apps/formvox/api/forms
```

### Public API

For public form submissions:
- No authentication required
- Uses form's public hash

```bash
curl -X POST \
  https://your-nextcloud.com/apps/formvox/api/public/HASH/submit \
  -d '{"answers": {...}}'
```

## Endpoints

### Forms

#### List Forms

```http
GET /apps/formvox/api/forms
```

**Response:**
```json
{
  "forms": [
    {
      "id": "file-id",
      "title": "Customer Survey",
      "created": "2024-01-15T10:00:00Z",
      "responseCount": 42
    }
  ]
}
```

#### Get Form

```http
GET /apps/formvox/api/forms/{fileId}
```

**Parameters:**
| Name | Type | Description |
|------|------|-------------|
| `fileId` | integer | Nextcloud file ID |

**Response:**
```json
{
  "form": {
    "id": "uuid",
    "title": "Customer Survey",
    "description": "...",
    "questions": [ ... ],
    "settings": { ... }
  }
}
```

#### Create Form

```http
POST /apps/formvox/api/forms
```

**Request Body:**
```json
{
  "title": "New Form",
  "path": "/Forms",
  "template": "blank"
}
```

**Response:**
```json
{
  "fileId": 12345,
  "path": "/Forms/New Form.fvform"
}
```

#### Update Form

```http
PUT /apps/formvox/api/forms/{fileId}
```

**Request Body:**
```json
{
  "form": {
    "title": "Updated Title",
    "settings": { ... }
  },
  "questions": [ ... ]
}
```

#### Delete Form

```http
DELETE /apps/formvox/api/forms/{fileId}
```

### Questions

#### Add Question

```http
POST /apps/formvox/api/forms/{fileId}/questions
```

**Request Body:**
```json
{
  "type": "text",
  "title": "What is your name?",
  "required": true
}
```

#### Update Question

```http
PUT /apps/formvox/api/forms/{fileId}/questions/{questionId}
```

#### Delete Question

```http
DELETE /apps/formvox/api/forms/{fileId}/questions/{questionId}
```

#### Reorder Questions

```http
PUT /apps/formvox/api/forms/{fileId}/questions/order
```

**Request Body:**
```json
{
  "order": ["q3", "q1", "q2"]
}
```

### Responses

#### Get Responses

```http
GET /apps/formvox/api/forms/{fileId}/responses
```

**Query Parameters:**
| Name | Type | Default | Description |
|------|------|---------|-------------|
| `page` | integer | 1 | Page number |
| `limit` | integer | 50 | Results per page |
| `from` | string | - | Start date (ISO 8601) |
| `to` | string | - | End date (ISO 8601) |

**Response:**
```json
{
  "responses": [ ... ],
  "total": 142,
  "page": 1,
  "pages": 3
}
```

#### Get Single Response

```http
GET /apps/formvox/api/forms/{fileId}/responses/{responseId}
```

#### Delete Response

```http
DELETE /apps/formvox/api/forms/{fileId}/responses/{responseId}
```

#### Delete All Responses

```http
DELETE /apps/formvox/api/forms/{fileId}/responses
```

### Public Submission

#### Get Public Form

```http
GET /apps/formvox/api/public/{hash}
```

Returns form structure without responses.

**Response:**
```json
{
  "form": {
    "title": "...",
    "description": "...",
    "questions": [ ... ],
    "branding": { ... }
  }
}
```

#### Submit Response

```http
POST /apps/formvox/api/public/{hash}/submit
```

**Request Body:**
```json
{
  "answers": {
    "q1": "John Doe",
    "q2": "c1",
    "q3": ["c1", "c2"]
  }
}
```

**Response:**
```json
{
  "success": true,
  "message": "Response submitted successfully"
}
```

**With Password:**
```http
POST /apps/formvox/api/public/{hash}/submit
Authorization: Bearer {password}
```

### Export

#### Export Responses

```http
GET /apps/formvox/api/forms/{fileId}/export
```

**Query Parameters:**
| Name | Type | Options | Description |
|------|------|---------|-------------|
| `format` | string | csv, json, xlsx | Export format |
| `from` | string | - | Start date |
| `to` | string | - | End date |

**Response:**
Returns file download with appropriate content type.

### Sharing

#### Get Share Settings

```http
GET /apps/formvox/api/forms/{fileId}/share
```

#### Update Share Settings

```http
PUT /apps/formvox/api/forms/{fileId}/share
```

**Request Body:**
```json
{
  "isPublic": true,
  "password": "optional-password",
  "expiresAt": "2024-12-31T23:59:59Z"
}
```

### Statistics

#### Get Form Statistics

```http
GET /apps/formvox/api/forms/{fileId}/stats
```

**Response:**
```json
{
  "totalResponses": 142,
  "responsesPerDay": [ ... ],
  "averageCompletionTime": 180,
  "questionStats": { ... }
}
```

## Error Responses

All errors return JSON with consistent format:

```json
{
  "error": true,
  "message": "Form not found",
  "code": "FORM_NOT_FOUND"
}
```

### Error Codes

| Code | HTTP Status | Description |
|------|-------------|-------------|
| `UNAUTHORIZED` | 401 | Authentication required |
| `FORBIDDEN` | 403 | No permission |
| `FORM_NOT_FOUND` | 404 | Form doesn't exist |
| `INVALID_REQUEST` | 400 | Bad request data |
| `VALIDATION_ERROR` | 422 | Form validation failed |
| `RATE_LIMITED` | 429 | Too many requests |
| `FORM_EXPIRED` | 410 | Public link expired |
| `PASSWORD_REQUIRED` | 401 | Form requires password |

## Rate Limiting

Public endpoints are rate limited:

| Endpoint | Limit |
|----------|-------|
| Public form view | 60/minute |
| Public submission | 10/minute |

Authenticated endpoints:
| Endpoint | Limit |
|----------|-------|
| All endpoints | 120/minute |

Rate limit headers:
```http
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 45
X-RateLimit-Reset: 1705329600
```

## Webhooks (Planned)

Future releases will support webhooks for:
- New submission
- Form updated
- Responses exported

## Code Examples

### Python

```python
import requests

# Authentication
session = requests.Session()
session.auth = ('username', 'app-password')

# List forms
response = session.get('https://nc.example.com/apps/formvox/api/forms')
forms = response.json()['forms']

# Get responses
response = session.get(f'https://nc.example.com/apps/formvox/api/forms/{form_id}/responses')
responses = response.json()['responses']

# Export to CSV
response = session.get(
    f'https://nc.example.com/apps/formvox/api/forms/{form_id}/export',
    params={'format': 'csv'}
)
with open('responses.csv', 'wb') as f:
    f.write(response.content)
```

### JavaScript

```javascript
// Using fetch
const BASE_URL = 'https://nc.example.com/apps/formvox/api';

async function getForms() {
  const response = await fetch(`${BASE_URL}/forms`, {
    credentials: 'include'
  });
  return response.json();
}

async function submitPublicForm(hash, answers) {
  const response = await fetch(`${BASE_URL}/public/${hash}/submit`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ answers })
  });
  return response.json();
}
```

### cURL

```bash
# List forms
curl -u "user:app-password" \
  https://nc.example.com/apps/formvox/api/forms

# Create form
curl -u "user:app-password" \
  -X POST \
  -H "Content-Type: application/json" \
  -d '{"title": "New Survey", "path": "/"}' \
  https://nc.example.com/apps/formvox/api/forms

# Submit public form
curl -X POST \
  -H "Content-Type: application/json" \
  -d '{"answers": {"q1": "Test"}}' \
  https://nc.example.com/apps/formvox/api/public/abc123/submit
```

## Next Steps

- [File Format](file-format.md) - Understanding the data structure
- [Architecture Overview](overview.md) - System design
