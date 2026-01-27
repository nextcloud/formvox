# Architecture Overview

This document describes the technical architecture of FormVox.

## System Design

### File-Based Architecture

FormVox uses a unique file-based approach:

```
┌─────────────────────────────────────────────────┐
│                   Nextcloud                      │
├─────────────────────────────────────────────────┤
│  ┌──────────────┐    ┌──────────────────────┐   │
│  │   FormVox    │    │    Nextcloud Files    │   │
│  │     App      │◄──►│    (.fvform files)    │   │
│  └──────────────┘    └──────────────────────┘   │
│         │                                        │
│         ▼                                        │
│  ┌──────────────┐                               │
│  │   Vue.js     │                               │
│  │   Frontend   │                               │
│  └──────────────┘                               │
└─────────────────────────────────────────────────┘
```

### Key Principles

1. **No Database Tables** - All data stored in files
2. **Native Integration** - Uses Nextcloud's file system
3. **Portability** - Forms can be copied, moved, shared like any file
4. **Encryption Compatible** - Works with server-side and E2E encryption

## Components

### Backend (PHP)

Located in `lib/`:

```
lib/
├── AppInfo/
│   └── Application.php      # App bootstrap
├── Controller/
│   ├── PageController.php   # Main page routes
│   ├── FormController.php   # Form CRUD operations
│   └── PublicController.php # Public form access
├── Service/
│   └── FormService.php      # Business logic
├── Migration/
│   └── RegisterMimeType.php # MIME type registration
├── Settings/
│   ├── AdminSettings.php    # Admin panel
│   └── AdminSection.php     # Admin navigation
└── BackgroundJob/
    └── TelemetryJob.php     # Telemetry reporting
```

### Frontend (Vue.js)

Located in `src/`:

```
src/
├── main.js                  # App entry point
├── files.js                 # Files app integration
├── App.vue                  # Main component
├── views/
│   ├── FormEditor.vue       # Form editing
│   ├── FormResults.vue      # Results dashboard
│   └── PublicForm.vue       # Public submission
├── components/
│   ├── QuestionTypes/       # Question components
│   ├── Settings/            # Settings panels
│   └── Results/             # Results components
└── store/
    └── index.js             # Vuex store
```

### Build Output

Located in `js/`:

```
js/
├── formvox-main.js          # Main app bundle
└── formvox-files.js         # Files integration
```

## Data Flow

### Form Creation

```
User clicks "New Form"
        │
        ▼
┌───────────────────┐
│ Frontend creates  │
│ form structure    │
└───────────────────┘
        │
        ▼
┌───────────────────┐
│ API POST request  │
│ to /api/forms     │
└───────────────────┘
        │
        ▼
┌───────────────────┐
│ FormService       │
│ creates .fvform   │
└───────────────────┘
        │
        ▼
┌───────────────────┐
│ File saved via    │
│ Nextcloud Files   │
└───────────────────┘
```

### Response Submission

```
User submits form
        │
        ▼
┌───────────────────┐
│ Frontend validates│
│ and sends data    │
└───────────────────┘
        │
        ▼
┌───────────────────┐
│ API POST request  │
│ to /api/submit    │
└───────────────────┘
        │
        ▼
┌───────────────────┐
│ FormService       │
│ - Acquires lock   │
│ - Reads file      │
│ - Appends response│
│ - Writes file     │
│ - Releases lock   │
└───────────────────┘
```

## Concurrent Access Handling

FormVox handles multiple simultaneous submissions using file locking:

```php
// Simplified locking mechanism
$lock = $this->lockManager->acquireLock($fileId);
try {
    $content = $this->readFile($fileId);
    $content['responses'][] = $newResponse;
    $this->writeFile($fileId, $content);
} finally {
    $lock->release();
}
```

### Lock Types
- **Exclusive lock** - For writes (form editing, response submission)
- **Shared lock** - For reads (viewing results)

### Conflict Resolution
- Locks have timeout to prevent deadlocks
- Failed locks retry with exponential backoff
- Users see error message if lock cannot be acquired

## File Format

See [File Format](file-format.md) for the complete `.fvform` JSON schema.

## API Architecture

### REST Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/forms` | List user's forms |
| POST | `/api/forms` | Create new form |
| GET | `/api/forms/{id}` | Get form details |
| PUT | `/api/forms/{id}` | Update form |
| DELETE | `/api/forms/{id}` | Delete form |
| GET | `/api/forms/{id}/responses` | Get responses |
| POST | `/api/submit/{hash}` | Submit response |

See [API Reference](api-reference.md) for complete documentation.

## Security Architecture

### Authentication

- Internal API: Nextcloud session authentication
- Public forms: Hash-based access with optional password

### Authorization

```
Request → Middleware → Permission Check → Controller
                            │
                            ▼
                    ┌───────────────┐
                    │ File system   │
                    │ permissions   │
                    └───────────────┘
```

### Data Validation

- Frontend: Vue.js validation
- Backend: PHP type checking and sanitization
- File: JSON schema validation

## Performance Considerations

### Caching

- Form structure: Cached in browser
- File content: Nextcloud file cache
- Results: Calculated on-demand

### Scalability

| Scenario | Performance |
|----------|-------------|
| Small forms (<100 responses) | Instant |
| Medium forms (100-1000) | Fast |
| Large forms (1000+) | Pagination recommended |

### Optimization Tips

1. Use pagination for large result sets
2. Archive old responses periodically
3. Enable APCu caching in Nextcloud

## Integration Points

### Nextcloud Integration

- **Files app** - Custom file handler for `.fvform`
- **Sharing** - Uses Nextcloud sharing API
- **Search** - Forms searchable in Unified Search
- **Activity** - Form events in activity stream

### External Integration

- REST API for programmatic access
- JSON export for data analysis
- Webhook support (planned)

## Development Architecture

### Build System

```bash
# Development build with watch
npm run watch

# Production build
npm run build
```

### Technologies

- **Backend**: PHP 8.1+, Nextcloud App Framework
- **Frontend**: Vue 3, Vuex, Nextcloud Vue components
- **Build**: Webpack
- **Styling**: SCSS, Nextcloud styles

## Next Steps

- [File Format](file-format.md) - Complete JSON schema
- [API Reference](api-reference.md) - REST API documentation
- [Comparison](comparison.md) - vs Nextcloud Forms
