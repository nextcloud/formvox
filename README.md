# FormVox

A file-based forms and polls app for Nextcloud. All form data is stored as `.fvform` files in your Nextcloud file system - no database tables required.

## Features

### Question Types
- **Text** - Single line text input with optional validation (email, URL, etc.)
- **Textarea** - Multi-line text for longer responses
- **Choice** - Single selection (radio buttons)
- **Multiple** - Multiple selection (checkboxes)
- **Dropdown** - Single selection from a dropdown menu
- **Date** - Date picker
- **DateTime** - Date and time picker
- **Time** - Time picker only
- **Number** - Numeric input with min/max validation
- **Scale** - Linear scale (e.g., 1-10) with labels
- **Rating** - Star rating (1-5 stars)
- **Matrix** - Grid/table questions with rows and columns

### Advanced Features
- **Conditional Logic (Branching)** - Show/hide questions based on previous answers
- **Quiz Mode** - Assign scores to answers and show results
- **Multi-page Forms** - Split long forms into multiple pages
- **Piping** - Reference previous answers in question text using `{{question_id}}`
- **Public Links** - Share forms with anonymous users
- **Login Required** - Optionally require Nextcloud login to respond
- **Duplicate Prevention** - Fingerprint-based detection to prevent multiple submissions
- **Export** - Export responses to CSV or JSON

### Templates
- Blank Form
- Poll
- Survey
- Registration
- Demo Form (showcases all features)

## Installation

1. Download or clone this repository to your Nextcloud apps directory:
   ```bash
   cd /var/www/nextcloud/apps
   git clone https://gitea.rikdekker.nl/sam/FormVox.git formvox
   ```

2. Set correct permissions:
   ```bash
   sudo chown -R www-data:www-data formvox
   ```

3. Enable the app in Nextcloud:
   ```bash
   sudo -u www-data php /var/www/nextcloud/occ app:enable formvox
   ```

   Or enable it via the Nextcloud web interface under Apps.

## Development

### Requirements
- PHP 8.1+
- Node.js 18+
- npm

### Setup
```bash
cd formvox
npm install
npm run build
```

### Build Commands
- `npm run build` - Production build
- `npm run dev` - Development build with watch mode

## File Format

FormVox stores all data in `.fvform` files (JSON format):

```json
{
  "version": "1.0",
  "id": "uuid",
  "title": "Form Title",
  "description": "Form description",
  "created_at": "2024-01-01T00:00:00+00:00",
  "modified_at": "2024-01-01T00:00:00+00:00",
  "settings": {
    "anonymous": true,
    "allow_multiple": false,
    "expires_at": null,
    "show_results": "after_submit",
    "require_login": false
  },
  "questions": [...],
  "pages": null,
  "responses": [...]
}
```

## API Routes

### Authenticated Routes
| Method | Route | Description |
|--------|-------|-------------|
| GET | `/api/forms` | List all forms |
| POST | `/api/forms` | Create new form |
| GET | `/api/form/{fileId}` | Get form details |
| PUT | `/api/form/{fileId}` | Update form |
| DELETE | `/api/form/{fileId}` | Delete form |
| POST | `/api/form/{fileId}/respond` | Submit response |
| GET | `/api/form/{fileId}/export/csv` | Export to CSV |
| GET | `/api/form/{fileId}/export/json` | Export to JSON |

### Public Routes
| Method | Route | Description |
|--------|-------|-------------|
| GET | `/public/{token}` | View public form |
| POST | `/public/{token}/submit` | Submit response |
| GET | `/public/{token}/results` | View results (if enabled) |

## License

AGPL-3.0 - See [LICENSE](LICENSE) for details.

## Author

Developed by Sam Ditmeijer
