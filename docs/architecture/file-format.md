# File Format Specification

FormVox stores all form data in `.fvform` files using JSON format. This document describes the complete schema.

## Overview

A `.fvform` file contains:
1. **Form metadata** - Title, settings, timestamps
2. **Questions** - All form questions with their settings
3. **Responses** - All submitted responses

```json
{
  "version": "1.0",
  "form": { ... },
  "questions": [ ... ],
  "responses": [ ... ]
}
```

## Complete Schema

### Root Object

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `version` | string | Yes | Schema version (currently "1.0") |
| `form` | object | Yes | Form metadata |
| `questions` | array | Yes | Array of question objects |
| `responses` | array | Yes | Array of response objects |

### Form Object

```json
{
  "form": {
    "id": "550e8400-e29b-41d4-a716-446655440000",
    "title": "Customer Feedback Survey",
    "description": "Help us improve our service",
    "created": "2024-01-15T10:00:00Z",
    "modified": "2024-01-15T14:30:00Z",
    "owner": "admin",
    "settings": { ... },
    "branding": { ... }
  }
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `id` | string | Yes | UUID of the form |
| `title` | string | Yes | Form title |
| `description` | string | No | Optional description |
| `created` | string | Yes | ISO 8601 timestamp |
| `modified` | string | Yes | ISO 8601 timestamp |
| `owner` | string | Yes | Nextcloud user ID |
| `settings` | object | No | Form settings |
| `branding` | object | No | Visual customization |

### Settings Object

```json
{
  "settings": {
    "isPublic": true,
    "publicHash": "abc123def456",
    "password": null,
    "expiresAt": "2024-02-15T23:59:59Z",
    "allowMultiple": false,
    "showProgress": true,
    "confirmationMessage": "Thank you for your response!",
    "quizMode": false,
    "restrictedUsers": [],
    "restrictedGroups": []
  }
}
```

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `isPublic` | boolean | false | Enable public access |
| `publicHash` | string | null | Unique hash for public URL |
| `password` | string | null | Hashed password (bcrypt) |
| `expiresAt` | string | null | ISO 8601 expiration |
| `allowMultiple` | boolean | true | Allow multiple submissions |
| `showProgress` | boolean | true | Show progress bar |
| `confirmationMessage` | string | null | Custom confirmation |
| `quizMode` | boolean | false | Enable quiz scoring |
| `restrictedUsers` | array | [] | Allowed user IDs |
| `restrictedGroups` | array | [] | Allowed group IDs |

### Branding Object

```json
{
  "branding": {
    "headerImage": "https://example.com/logo.png",
    "backgroundColor": "#ffffff",
    "accentColor": "#0082c9",
    "customCss": null
  }
}
```

### Question Object

```json
{
  "questions": [
    {
      "id": "q1",
      "type": "text",
      "title": "What is your name?",
      "description": "Please enter your full name",
      "required": true,
      "order": 1,
      "page": 0,
      "options": { ... },
      "conditions": [ ... ],
      "quiz": { ... }
    }
  ]
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `id` | string | Yes | Unique question ID |
| `type` | string | Yes | Question type |
| `title` | string | Yes | Question text |
| `description` | string | No | Helper text |
| `required` | boolean | No | Is answer required |
| `order` | number | Yes | Display order |
| `page` | number | No | Page index (default 0) |
| `options` | object | No | Type-specific options |
| `conditions` | array | No | Conditional logic rules |
| `quiz` | object | No | Quiz mode settings |

### Question Types

#### Text Types

```json
// text
{ "type": "text", "options": { "placeholder": "Enter text...", "maxLength": 100 } }

// email
{ "type": "email", "options": { "placeholder": "email@example.com" } }

// textarea
{ "type": "textarea", "options": { "rows": 4, "maxLength": 1000 } }
```

#### Choice Types

```json
// single_choice
{
  "type": "single_choice",
  "options": {
    "choices": [
      { "id": "c1", "label": "Option A", "value": "a" },
      { "id": "c2", "label": "Option B", "value": "b" }
    ],
    "allowOther": true,
    "randomize": false
  }
}

// multiple_choice
{
  "type": "multiple_choice",
  "options": {
    "choices": [ ... ],
    "minSelections": 1,
    "maxSelections": 3
  }
}

// dropdown
{
  "type": "dropdown",
  "options": {
    "choices": [ ... ],
    "placeholder": "Select an option..."
  }
}
```

#### Date/Time Types

```json
// date
{ "type": "date", "options": { "minDate": "2024-01-01", "maxDate": "2024-12-31" } }

// time
{ "type": "time", "options": { "format": "24h" } }

// datetime
{ "type": "datetime", "options": { ... } }
```

#### Rating Types

```json
// linear_scale
{
  "type": "linear_scale",
  "options": {
    "min": 1,
    "max": 10,
    "minLabel": "Not satisfied",
    "maxLabel": "Very satisfied"
  }
}

// rating
{
  "type": "rating",
  "options": {
    "maxStars": 5
  }
}
```

#### Matrix Type

```json
{
  "type": "matrix",
  "options": {
    "rows": [
      { "id": "r1", "label": "Quality" },
      { "id": "r2", "label": "Price" }
    ],
    "columns": [
      { "id": "c1", "label": "Poor" },
      { "id": "c2", "label": "Average" },
      { "id": "c3", "label": "Excellent" }
    ],
    "multiplePerRow": false
  }
}
```

### Conditions Array

```json
{
  "conditions": [
    {
      "questionId": "q1",
      "operator": "equals",
      "value": "yes",
      "logic": "and"
    }
  ]
}
```

| Operator | Description |
|----------|-------------|
| `equals` | Exact match |
| `not_equals` | Not equal |
| `contains` | Contains substring |
| `greater_than` | Numeric comparison |
| `less_than` | Numeric comparison |
| `is_empty` | Field is empty |
| `is_not_empty` | Field has value |

### Quiz Object

```json
{
  "quiz": {
    "correctAnswer": "c1",
    "points": 10,
    "feedback": {
      "correct": "Well done!",
      "incorrect": "The correct answer is Option A"
    }
  }
}
```

### Response Object

```json
{
  "responses": [
    {
      "id": "r1",
      "submitted": "2024-01-15T11:30:00Z",
      "ip": null,
      "userAgent": "Mozilla/5.0...",
      "userId": null,
      "answers": {
        "q1": "John Doe",
        "q2": ["c1", "c2"],
        "q3": { "r1": "c2", "r2": "c3" }
      },
      "metadata": {
        "duration": 120,
        "score": 85
      }
    }
  ]
}
```

| Field | Type | Description |
|-------|------|-------------|
| `id` | string | Unique response ID |
| `submitted` | string | ISO 8601 timestamp |
| `ip` | string | IP address (if stored) |
| `userAgent` | string | Browser user agent |
| `userId` | string | Nextcloud user (if logged in) |
| `answers` | object | Question ID → answer mapping |
| `metadata` | object | Additional data |

### Answer Formats

| Question Type | Answer Format |
|---------------|---------------|
| text, email, textarea | `"string value"` |
| single_choice, dropdown | `"choice_id"` |
| multiple_choice | `["c1", "c2"]` |
| date | `"2024-01-15"` |
| time | `"14:30"` |
| datetime | `"2024-01-15T14:30:00"` |
| linear_scale, rating | `5` (number) |
| matrix | `{ "row_id": "column_id" }` |

## Example Complete File

```json
{
  "version": "1.0",
  "form": {
    "id": "550e8400-e29b-41d4-a716-446655440000",
    "title": "Customer Feedback",
    "description": "Please share your experience",
    "created": "2024-01-15T10:00:00Z",
    "modified": "2024-01-15T14:30:00Z",
    "owner": "admin",
    "settings": {
      "isPublic": true,
      "publicHash": "abc123",
      "quizMode": false
    }
  },
  "questions": [
    {
      "id": "q1",
      "type": "text",
      "title": "Your name",
      "required": true,
      "order": 1
    },
    {
      "id": "q2",
      "type": "linear_scale",
      "title": "How satisfied are you?",
      "required": true,
      "order": 2,
      "options": {
        "min": 1,
        "max": 5,
        "minLabel": "Not at all",
        "maxLabel": "Very satisfied"
      }
    }
  ],
  "responses": [
    {
      "id": "r1",
      "submitted": "2024-01-15T11:30:00Z",
      "answers": {
        "q1": "Jane Smith",
        "q2": 4
      }
    }
  ]
}
```

## Versioning

The `version` field indicates the schema version:

| Version | Changes |
|---------|---------|
| 1.0 | Initial release |

Future versions will maintain backwards compatibility where possible.

## Next Steps

- [API Reference](api-reference.md) - Working with forms programmatically
- [Architecture Overview](overview.md) - System design
