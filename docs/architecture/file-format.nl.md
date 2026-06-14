# Bestandsformaat-specificatie

FormVox slaat alle formulier-data op in `.fvform`-bestanden in JSON-formaat. Dit document beschrijft het complete schema.

## Overzicht

Een `.fvform`-bestand bevat:

1. **Formulier-metadata** — titel, instellingen, tijdstempels
2. **Vragen** — alle formulier-vragen met instellingen
3. **Antwoorden** — alle ingediende antwoorden

```json
{
  "version": "1.0",
  "form": { ... },
  "questions": [ ... ],
  "responses": [ ... ]
}
```

## Compleet schema

### Root-object

| Veld | Type | Verplicht | Beschrijving |
|------|------|-----------|--------------|
| `version` | string | Ja | Schema-versie (momenteel "1.0") |
| `form` | object | Ja | Formulier-metadata |
| `questions` | array | Ja | Array van vraag-objecten |
| `responses` | array | Ja | Array van antwoord-objecten |

### Form-object

```json
{
  "form": {
    "id": "550e8400-e29b-41d4-a716-446655440000",
    "title": "Klant-feedback-enquête",
    "description": "Help ons onze service te verbeteren",
    "created": "2024-01-15T10:00:00Z",
    "modified": "2024-01-15T14:30:00Z",
    "owner": "admin",
    "settings": { ... },
    "branding": { ... }
  }
}
```

| Veld | Type | Verplicht | Beschrijving |
|------|------|-----------|--------------|
| `id` | string | Ja | UUID van het formulier |
| `title` | string | Ja | Formulier-titel |
| `description` | string | Nee | Optionele beschrijving |
| `created` | string | Ja | ISO-8601-tijdstempel |
| `modified` | string | Ja | ISO-8601-tijdstempel |
| `owner` | string | Ja | Nextcloud-gebruiker-ID |
| `settings` | object | Nee | Formulier-instellingen |
| `branding` | object | Nee | Visuele customization |

### Settings-object

```json
{
  "settings": {
    "isPublic": true,
    "publicHash": "abc123def456",
    "password": null,
    "expiresAt": "2024-02-15T23:59:59Z",
    "allowMultiple": false,
    "showProgress": true,
    "confirmationMessage": "Bedankt voor je antwoord!",
    "quizMode": false,
    "restrictedUsers": [],
    "restrictedGroups": []
  }
}
```

| Veld | Type | Default | Beschrijving |
|------|------|---------|--------------|
| `isPublic` | boolean | false | Publieke toegang inschakelen |
| `publicHash` | string | null | Unieke hash voor publieke URL |
| `password` | string | null | Gehashed wachtwoord (bcrypt) |
| `expiresAt` | string | null | ISO-8601-vervaldatum |
| `allowMultiple` | boolean | true | Sta meerdere inzendingen toe |
| `showProgress` | boolean | true | Toon voortgangsbalk |
| `confirmationMessage` | string | null | Custom bevestiging |
| `quizMode` | boolean | false | Quiz-scoring inschakelen |
| `restrictedUsers` | array | [] | Toegestane user-IDs |
| `restrictedGroups` | array | [] | Toegestane group-IDs |

### Branding-object

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

### Question-object

```json
{
  "questions": [
    {
      "id": "q1",
      "type": "text",
      "title": "Wat is je naam?",
      "description": "Voer je volledige naam in",
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

| Veld | Type | Verplicht | Beschrijving |
|------|------|-----------|--------------|
| `id` | string | Ja | Uniek vraag-ID |
| `type` | string | Ja | Vraagtype |
| `title` | string | Ja | Vraag-tekst |
| `description` | string | Nee | Helper-tekst |
| `required` | boolean | Nee | Is antwoord verplicht |
| `order` | number | Ja | Weergave-volgorde |
| `page` | number | Nee | Pagina-index (default 0) |
| `options` | object | Nee | Type-specifieke opties |
| `conditions` | array | Nee | Conditionele-logica-regels |
| `quiz` | object | Nee | Quiz-modus-instellingen |

### Vraagtypen

#### Tekst-types

```json
// text
{ "type": "text", "options": { "placeholder": "Voer tekst in...", "maxLength": 100 } }

// email
{ "type": "email", "options": { "placeholder": "email@example.com" } }

// textarea
{ "type": "textarea", "options": { "rows": 4, "maxLength": 1000 } }
```

#### Keuze-types

```json
// single_choice
{
  "type": "single_choice",
  "options": {
    "choices": [
      { "id": "c1", "label": "Optie A", "value": "a" },
      { "id": "c2", "label": "Optie B", "value": "b" }
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
    "placeholder": "Selecteer een optie..."
  }
}
```

#### Datum/tijd-types

```json
// date
{ "type": "date", "options": { "minDate": "2024-01-01", "maxDate": "2024-12-31" } }

// time
{ "type": "time", "options": { "format": "24h" } }

// datetime
{ "type": "datetime", "options": { ... } }
```

#### Rating-types

```json
// linear_scale
{
  "type": "linear_scale",
  "options": {
    "min": 1,
    "max": 10,
    "minLabel": "Niet tevreden",
    "maxLabel": "Zeer tevreden"
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

#### Matrix-type

```json
{
  "type": "matrix",
  "options": {
    "rows": [
      { "id": "r1", "label": "Kwaliteit" },
      { "id": "r2", "label": "Prijs" }
    ],
    "columns": [
      { "id": "c1", "label": "Slecht" },
      { "id": "c2", "label": "Gemiddeld" },
      { "id": "c3", "label": "Uitstekend" }
    ],
    "multiplePerRow": false
  }
}
```

### Conditions-array

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

| Operator | Beschrijving |
|----------|--------------|
| `equals` | Exacte match |
| `not_equals` | Niet gelijk |
| `contains` | Bevat substring |
| `greater_than` | Numerieke vergelijking |
| `less_than` | Numerieke vergelijking |
| `is_empty` | Veld is leeg |
| `is_not_empty` | Veld heeft een waarde |

### Quiz-object

```json
{
  "quiz": {
    "correctAnswer": "c1",
    "points": 10,
    "feedback": {
      "correct": "Goed gedaan!",
      "incorrect": "Het correcte antwoord is optie A"
    }
  }
}
```

### Response-object

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
        "q1": "Jan Jansen",
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

| Veld | Type | Beschrijving |
|------|------|--------------|
| `id` | string | Uniek antwoord-ID |
| `submitted` | string | ISO-8601-tijdstempel |
| `ip` | string | IP-adres (indien opgeslagen) |
| `userAgent` | string | Browser-user-agent |
| `userId` | string | Nextcloud-gebruiker (indien ingelogd) |
| `answers` | object | Vraag-ID → antwoord-mapping |
| `metadata` | object | Aanvullende data |

### Antwoord-formaten

| Vraagtype | Antwoord-formaat |
|-----------|------------------|
| text, email, textarea | `"string-waarde"` |
| single_choice, dropdown | `"choice_id"` |
| multiple_choice | `["c1", "c2"]` |
| date | `"2024-01-15"` |
| time | `"14:30"` |
| datetime | `"2024-01-15T14:30:00"` |
| linear_scale, rating | `5` (number) |
| matrix | `{ "row_id": "column_id" }` |

## Voorbeeld compleet bestand

```json
{
  "version": "1.0",
  "form": {
    "id": "550e8400-e29b-41d4-a716-446655440000",
    "title": "Klant-feedback",
    "description": "Deel je ervaring",
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
      "title": "Je naam",
      "required": true,
      "order": 1
    },
    {
      "id": "q2",
      "type": "linear_scale",
      "title": "Hoe tevreden ben je?",
      "required": true,
      "order": 2,
      "options": {
        "min": 1,
        "max": 5,
        "minLabel": "Helemaal niet",
        "maxLabel": "Zeer tevreden"
      }
    }
  ],
  "responses": [
    {
      "id": "r1",
      "submitted": "2024-01-15T11:30:00Z",
      "answers": {
        "q1": "Janneke Smits",
        "q2": 4
      }
    }
  ]
}
```

## Versioning

Het `version`-veld geeft de schema-versie aan:

| Versie | Wijzigingen |
|--------|-------------|
| 1.0 | Initiële release |

Toekomstige versies behouden backwards-compatibility waar mogelijk.

## Volgende stappen

- [API-referentie](api-reference.md) — programmatic werken met formulieren
- [Architectuur-overzicht](overview.md) — systeem-design
