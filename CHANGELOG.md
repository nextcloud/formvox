# Changelog

All notable changes to FormVox will be documented in this file.

## [0.2.8] - 2026-01-30

### Added
- **Microsoft Forms Import** - Import forms directly from Microsoft Forms
  - OAuth integration with Microsoft Entra ID (Azure AD)
  - Import form structure including all question types
  - Import existing responses
  - Support for multi-page forms (sections)
  - Support for Likert/Matrix questions
  - Admin settings for Azure app registration configuration
  - Per-user Microsoft account connection
  - Question type mapping: Choice, Text, Rating, Date, Likert, Ranking, NPS, File upload
- New "Import" card in template gallery (visible when MS Forms is configured)
- Import wizard with form selection, preview, and progress tracking

### Changed
- Admin settings now include "Integrations" tab for Microsoft Forms configuration
- Improved documentation for Azure app registration setup with required API permissions

## [0.2.7] - 2026-01-30

### Added
- **External API** for programmatic access from third-party systems
  - API key authentication with bcrypt-hashed storage
  - Configurable permissions per key (read_form, read_responses, write_responses, delete_responses)
  - CRUD operations on form responses
  - API keys automatically stripped when downloading .fvform via WebDAV
- **Webhooks** for real-time notifications
  - Events: response.created, response.updated, response.deleted
  - HMAC-SHA256 signed payloads for security
  - Configurable per-form with enable/disable toggle
- New "API & Integrations" section in Share dialog for managing API keys and webhooks
- Comprehensive External API & Webhooks documentation in `docs/architecture/external-api.md`

### Fixed
- Admin settings page no longer blocks on statistics loading (statistics are now fetched async)
- Consistent app icon (`app-dark.svg` now matches `app.svg` structure)

## [0.2.6] - 2026-01-28

### Added
- File upload question type with configurable allowed file types and size limits
- Download all uploads as ZIP from Results view
- DAV plugin to strip sensitive data from .fvform files on download (responses, settings, tokens)
- Form embedding: embed forms in external websites (SharePoint, intranets, etc.) via iframe
- Embed code generator in Share dialog with responsive width and height options
- Admin setting to restrict embedding to specific domains (Settings tab)

### Changed
- Editor toolbar redesign: Preview, Share, Results buttons now prominent; less-used options in dropdown
- Editor header redesign: cleaner look with underline focus states
- Question cards redesign: hover effects, focus states, modernere look
- Description fields now use multi-line textarea instead of single-line input
- Improved spacing and visual hierarchy throughout editor
- Required questions now clearly marked with red asterisk and "(required)" label on public forms

### Fixed
- Hide .fvform files now properly hidden from sync clients while remaining visible in web interface
- Uploaded files are now deleted when their response is deleted
- Icon vertical alignment in dropdown menus
- Suppress @nextcloud/vue appName/appVersion warnings in console

## [0.2.5] - 2026-01-27

### Added
- Comprehensive documentation structure in `docs/` folder
  - User guides (creating forms, question types, advanced features, sharing, results, exporting)
  - Admin guides (installation, configuration, security)
  - Architecture docs (overview, file format, API reference, comparison with Nextcloud Forms)
- Pre-commit hook to prevent accidental commit of private keys
- Security: Added `*.key` and `*.pem` to `.gitignore`

### Changed
- Updated README with correct build commands and documentation links
- Updated authors in README (Sam Ditmeijer & Rik Dekker)

### Removed
- Deprecated monolithic `docs/user-guide.md` (replaced by structured docs)
- Deprecated `docs/comparison-with-nextcloud-forms.md` (moved to `docs/architecture/`)

## [0.2.4] - 2026-01-26

### Changed
- Added website and documentation links to App Store listing

## [0.2.3] - 2026-01-26

### Changed
- Updated authors in App Store metadata (Sam Ditmeijer & Rik Dekker)
- Added screenshots to App Store listing

## [0.2.2] - 2026-01-25

### Added
- Total users count in telemetry data (consistent with other VoxCloud apps)
- Complete translations for Dutch (NL), German (DE), and French (FR)
- 380 translation strings per language
- Template gallery on homepage with colored template cards (Survey, Poll, Registration, Demo, Blank)
- Collapsible template section with state persistence in localStorage
- Form cards with colored headers based on template type
- Tabs navigation (Recent, My forms) with counts
- Delete confirmation dialog using Nextcloud Vue NcDialog
- File-based permission system using Nextcloud's native file permissions
- New FilePermissionController for permission checks via `/api/permissions/{fileId}`
- User/group access restrictions for public forms (restrict response access to specific Nextcloud users and/or groups)

### Changed
- Telemetry now includes `totalUsers` field alongside `activeUsers30d`
- Demo form templates (survey, poll, registration, demo) are now fully translatable via IL10N
- Removed sidebar navigation, moved "New form" button to header
- Template gallery title changed from "Explore templates" to "New form"
- Clicking template card now opens modal with pre-selected template and location picker
- Modal no longer shows template selection (template already chosen by card click)
- Permission system now respects Nextcloud share permissions (read-only shares can't edit forms)
- Form filename now automatically updates when form title is changed

### Fixed
- Public form mobile responsiveness (horizontal scroll issues)
- Dark mode support on public forms (inputs now readable)
- Date picker and DateTime picker popup visibility on public forms
- Time input alignment on public forms

### Removed
- Favorites functionality (temporarily removed due to API issues)

## [0.2.1] - 2026-01-24

### Added
- Admin settings with tabs (Branding, Statistics)
- Form statistics overview (total forms, responses, active users)
- Anonymous telemetry with opt-out option
- Background job for telemetry reporting

### Fixed
- App icons now follow Nextcloud standards (navigation bar, app store, dark theme)

### Changed
- Simplified "About FormVox" section in admin settings

## [0.2.0] - 2026-01-24

### Added
- File-based forms storage (.fvform files)
- Multiple question types: text, textarea, single choice, multiple choice, dropdown, date, time, number, scale, rating, file upload, matrix
- Conditional logic (branching)
- Quiz mode with scoring
- Export to CSV, JSON, Excel
- Native Nextcloud sharing integration
- Public form links with password protection and expiration dates
- Per-form branding with visual page builder
- Answer piping (use previous answers in later questions)
- Charts and visualizations in Results view
- Pagination in Individual responses view
- Files app integration with filetype icons
- Folder picker for creating new forms
- Comprehensive user guide documentation
- End-to-end encryption compatible
