# FormVox

A file-based forms and polls app for Nextcloud. All form data is stored as `.fvform` files in your Nextcloud file system - no database tables required.

![FormVox Homepage](screenshots/Start.png)

## Screenshots

| Create Form | Edit Form | View Results |
|-------------|-----------|--------------|
| ![New form](screenshots/New%20form.png) | ![Edit form](screenshots/Edit%20form.png) | ![Results](screenshots/Result.png) |

## Languages

FormVox is available in:
- 🇬🇧 English
- 🇳🇱 Nederlands (Dutch)
- 🇩🇪 Deutsch (German)
- 🇫🇷 Français (French)

## Features

### Question Types

| Type | Description | Preview |
|------|-------------|---------|
| **Text** | Single line text input | ![Text](screenshots/Question%20-%20Text.png) |
| **Email** | Email input with validation | ![Email](screenshots/Question%20-%20Email.png) |
| **Textarea** | Multi-line text | ![Multi-line](screenshots/Question%20-%20Multi-line.png) |
| **Single Choice** | Radio buttons | ![Single choice](screenshots/Question%20-%20Single%20choice.png) |
| **Multiple Choice** | Checkboxes | ![Multiple choice](screenshots/Question%20-%20Multiple%20choice.png) |
| **Dropdown** | Dropdown menu | ![Dropdown](screenshots/Question%20-%20Dropdown%20select.png) |
| **Date** | Date picker | ![Date](screenshots/Question%20-%20Date%20picker.png) |
| **DateTime** | Date and time picker | ![DateTime](screenshots/Question%20-%20Datetime%20picker.png) |
| **Time** | Time picker | ![Time](screenshots/Question%20-%20Time%20picker.png) |
| **Linear Scale** | Scale (e.g., 1-10) | ![Scale](screenshots/Question%20-%20Linear%20scale.png) |
| **Star Rating** | 1-5 stars | ![Rating](screenshots/Question%20-%20Star%20rating.png) |
| **Matrix** | Grid/table questions | ![Matrix](screenshots/Question%20-%20Matrix.png) |

### Advanced Features

| Feature | Description | Preview |
|---------|-------------|---------|
| **Conditional Logic** | Show/hide questions based on answers | ![Conditional](screenshots/Question%20-%20Conditional.png) |
| **Quiz Mode** | Assign scores and show results | ![Quiz](screenshots/Question%20-%20Quiz.png) |
| **Multi-page Forms** | Split long forms into pages | |
| **Piping** | Reference answers using `{{question_id}}` | |
| **Public Links** | Share with anonymous users | |
| **Login Required** | Require Nextcloud login | |
| **Duplicate Prevention** | Fingerprint-based detection | |
| **Export** | CSV, JSON, Excel export | |

### Homepage
- **Template Gallery** - Quick access to create forms from colorful template cards
- **Tabs Navigation** - Switch between "Recent" and "My forms" views
- **Form Cards** - Visual cards with colored headers based on template type
- **Collapsible Templates** - Hide/show template gallery with state persistence

### Templates
- Blank Form
- Poll
- Survey
- Registration
- Demo Form (showcases all features)

### Admin Settings
- **Branding** - Customize app appearance
- **Statistics** - View total forms, responses, and active users
- **Telemetry** - Optional anonymous usage statistics (opt-out available)

## Requirements

- Nextcloud 28 - 32
- PHP 8.1+

## Installation

### From Nextcloud App Store (Recommended)

1. Go to **Apps** in your Nextcloud instance
2. Search for "FormVox"
3. Click **Download and enable**

### Manual Installation

1. Download the latest release from the [releases page](https://github.com/nextcloud/formvox/releases)
2. Extract to your Nextcloud apps directory:
   ```bash
   cd /var/www/nextcloud/apps
   tar -xzf formvox-x.y.z.tar.gz
   ```
3. Set correct permissions:
   ```bash
   sudo chown -R www-data:www-data formvox
   ```
4. Enable the app:
   ```bash
   sudo -u www-data php /var/www/nextcloud/occ app:enable formvox
   ```

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

## Technical Details

### Concurrent Response Handling

FormVox is designed to handle multiple simultaneous form submissions without data loss. When multiple users submit responses at the same time, a database-based locking mechanism ensures all responses are saved correctly.

**How it works:**
- Uses database locks via the `preferences` table with unique constraints
- Implements retry mechanism with exponential backoff (30 retries, 100ms base delay)
- Uses Nextcloud's File API (`putContent`) to ensure proper cache synchronization

**Performance:**
| Concurrent Requests | Success Rate |
|---------------------|--------------|
| 20 simultaneous | 100% |
| 50 simultaneous | 80% |

For typical usage scenarios (users submitting forms seconds apart), all responses are guaranteed to be saved.

### Rate Limiting

Public form submissions are rate-limited to 100 requests per hour per IP address to prevent abuse.

## Privacy

FormVox includes optional anonymous telemetry to help improve the app. This can be disabled in Admin Settings.

**What we collect (when enabled):**
- Number of forms and responses
- Number of active users
- FormVox, Nextcloud, and PHP version numbers
- A unique hash of your instance URL (privacy-friendly identifier)

**What we never collect:**
- Form content or titles
- Response data or answers
- User names or email addresses
- Your actual server URL

## License

AGPL-3.0 - See [LICENSE](LICENSE) for details.

## Links

- [Website](https://formvox.voxcloud.nl/)
- [Documentation](docs/user-guide.md)
- [Issue Tracker](https://github.com/nextcloud/formvox/issues)
- [Nextcloud App Store](https://apps.nextcloud.com/apps/formvox)

## Author

Developed by Sam Ditmeijer
