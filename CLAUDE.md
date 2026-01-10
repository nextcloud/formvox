# FormVox - Nextcloud Forms & Polls App

## Project Overview
FormVox is a Nextcloud app for creating and managing forms and polls. All data is stored as files in the Nextcloud filesystem - no database tables required.

## Key Principles
- **100% file-based** - No database tables, all data in `.fvform` JSON files
- **Single file = everything** - Form definition + responses in one file
- **Native Nextcloud** - Uses existing share mechanisms, rate limiting, file locking
- **Portable** - Copy the file and you have everything

## Tech Stack
- **Backend**: PHP 8.1+ (Nextcloud app framework)
- **Frontend**: Vue.js 3 with @nextcloud/vue components
- **Storage**: JSON files with `.fvform` extension
- **Build**: Webpack for frontend bundling

## Project Structure
```
formvox/
├── appinfo/
│   ├── info.xml          # App metadata
│   └── routes.php        # Route definitions
├── lib/
│   ├── AppInfo/
│   │   └── Application.php   # App bootstrap
│   ├── Controller/
│   │   ├── PageController.php    # Main views
│   │   ├── ApiController.php     # Authenticated API
│   │   └── PublicController.php  # Anonymous routes
│   ├── Service/
│   │   ├── FormService.php       # Form CRUD
│   │   ├── ResponseService.php   # Response handling
│   │   ├── PermissionService.php # Access control
│   │   └── IndexService.php      # Index maintenance
│   └── Preview/
│       └── FormPreview.php       # Thumbnail provider
├── src/
│   ├── main.js           # Vue entry point
│   ├── views/            # Page views
│   └── components/       # Reusable components
├── templates/
│   ├── index.php         # Main template
│   └── public/           # Public templates
└── css/                  # Styles
```

## File Format (.fvform)
```json
{
  "version": "1.0",
  "id": "uuid",
  "title": "Form title",
  "settings": { ... },
  "permissions": { ... },
  "questions": [ ... ],
  "_index": { ... },
  "responses": [ ... ]
}
```

## API Routes

### Public (Anonymous)
- `GET /public/{token}` - Show form
- `POST /public/{token}/submit` - Submit response
- `GET /public/{token}/results` - Show results

### Authenticated
- `GET /api/forms` - List forms
- `GET /api/form/{id}` - Get form
- `POST /api/form/{id}/respond` - Submit response
- `PUT /api/form/{id}` - Update form
- `DELETE /api/form/{id}/responses/{rid}` - Delete response

## Question Types
- text, textarea
- choice (radio), multiple (checkbox), dropdown
- date, datetime, time
- number, scale, rating
- file, matrix

## Features
- Conditional logic (branching with showIf)
- Pages/sections
- Piping (variables in questions)
- Quiz mode with scoring
- Export (CSV, JSON, Excel)
- Role-based permissions (respondent, viewer, editor, admin, owner)

## Development Commands
```bash
# Install dependencies
npm install

# Build for development
npm run dev

# Build for production
npm run build

# Watch mode
npm run watch
```

## Nextcloud Integration
- Custom mime type: `application/x-fvform`
- File handler for `.fvform` files
- Thumbnail preview in Files app
- Standard share dialog with extra options
- Built-in rate limiting (@AnonRateLimit)
- Brute force protection
