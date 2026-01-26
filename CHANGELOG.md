# Changelog

All notable changes to FormVox will be documented in this file.

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
