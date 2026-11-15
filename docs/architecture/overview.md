# Architecture Overview

This document describes the technical architecture of FormVox.

## System Design

### Hybrid Architecture

FormVox splits a form across the file it is created in and Nextcloud's own storage:

```
┌───────────────────────────────────────────────────────────────┐
│                           Nextcloud                            │
├───────────────────────────────────────────────────────────────┤
│  ┌──────────────┐  ┌───────────────────┐  ┌─────────────────┐ │
│  │   FormVox    │  │  Nextcloud Files  │  │    Database      │ │
│  │     App      │◄►│  (.fvform: the    │  │  (responses,     │ │
│  │              │  │  form definition) │  │  secrets)        │ │
│  └──────────────┘  └───────────────────┘  └─────────────────┘ │
│         │          ┌───────────────────┐                      │
│         │          │ FormVox app data  │                      │
│         └─────────►│ (uploads, PDFs,   │                      │
│                    │ branding, ODT)    │                      │
│                    └───────────────────┘                      │
│         │                                                      │
│         ▼                                                      │
│  ┌──────────────┐                                             │
│  │   Vue.js     │                                             │
│  │   Frontend   │                                             │
│  └──────────────┘                                             │
└───────────────────────────────────────────────────────────────┘
```

### Key Principles

1. **The file holds only the form** - Questions, pages and settings live in `.fvform`; its responses, secrets and
   respondent files do not
2. **Native Integration** - The form file still uses Nextcloud's file system, with standard permissions and sharing
3. **Portability** - A form's definition can be copied, moved, shared like any file; a copy of a `.fvform` file starts
   with no responses of its own
4. **Encryption Compatible** - The `.fvform` file works with server-side and E2E encryption; the database and FormVox's
   app data are not covered by Nextcloud's server-side file encryption (see [Security](../admin/security.md#encryption))
5. **Converted on touch** - A form created before 1.6.0 still has everything in its file; it is moved into the database
   and app data by a background job, or immediately the first time anything writes to it (see [Where FormVox keeps
   data](../admin/configuration.md#where-formvox-keeps-data))

## Components

### Backend (PHP)

Located in `lib/`:

```
lib/
├── AppInfo/
│   └── Application.php       # App bootstrap
├── Controller/
│   ├── PageController.php    # Main page routes
│   ├── FormController.php    # Form CRUD operations
│   ├── PublicController.php  # Public form access
│   └── ExternalApiController.php # External API (API keys, webhooks)
├── Service/
│   └── FormService.php       # Business logic
├── Store/
│   ├── FormStore.php         # Binds a form's database row to its file
│   ├── ResponseStore.php     # Responses, keys, counts, outbox
│   ├── SecretStore.php       # Link token, password hash, API keys, webhooks
│   ├── FormFolders.php       # App data: uploads, branding, template, PDFs
│   └── FormMigration.php, FormConverter.php # Moving a v1 form into the database
├── Command/
│   ├── Migrate.php, Orphans.php, Rebind.php, Unlock.php # occ formvox:*
├── Migration/
│   └── RegisterMimeType.php  # MIME type registration
├── Settings/
│   ├── AdminSettings.php     # Admin panel
│   └── AdminSection.php      # Admin navigation
└── BackgroundJob/
    ├── TelemetryJob.php      # Telemetry reporting
    ├── MigrateFormsJob.php   # Moves v1 forms into the database
    └── OrphanSweepJob.php    # Removes forms whose file disappeared, after 30 days
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
┌─────────────────────────────┐
│ ResponseStore                │
│ - A v1 form is moved into    │
│   the database first         │
│ - Takes the form's row       │
│ - Checks limits/capacity     │
│   against the stored counts  │
│ - Writes the response, its   │
│   keys and outbox row        │
└─────────────────────────────┘
```

## Concurrent Access Handling

Responses, secrets and the counts behind the results page are written to the database, one form at a time: a
submission takes the form's own row (held until the transaction commits), so changes to one form go one after the
other, and a deadlock or lock timeout retries the whole transaction. A concurrency test runs many respondents in
separate processes at the same moment to check that a form's limit and any per-option capacity hold exactly, with no
response lost or duplicated.

FormVox also keeps a lock of its own per form (independent of this, see
[Security](../admin/security.md#nextclouds-own-file-lock)) so only one writer edits a form, or sends its outbox, at a
time:

### Lock Types
- **FormVox's own form lock** - One writer at a time (editing, settings, migrating a form into the database)
- **Nextcloud's file lock** - Taken whenever FormVox writes to the `.fvform` file itself

### Conflict Resolution
- A busy form answers `503` with `reason: busy` and a `Retry-After` header; clients may simply retry
- FormVox's own lock retries with jittered backoff until its deadline, and is taken over once its holder is gone
- `occ formvox:unlock` frees FormVox's own stuck lock by hand; it does not touch Nextcloud's file lock

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

- External API with API-key authentication, for programmatic access (see [External API](external-api.md))
- JSON export for data analysis
- Webhooks on response created/updated/deleted, HMAC-signed (see [External API](external-api.md))

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
