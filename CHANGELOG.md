# Changelog

All notable changes to FormVox will be documented in this file.

## [1.1.0] - 2026-04-20

### Added
- **AI form generation** — Generate forms from a description, an uploaded document (PDF/DOCX/ODT/text), or both, using Nextcloud's built-in TaskProcessing API. Async pattern identical to nextcloud/assistant: the request returns immediately with a task id, the frontend polls, and a background `TaskSuccessfulEvent` listener materialises the form and sends a Nextcloud notification even if the user closes the browser tab. Includes heuristic truncated-JSON repair for less capable LLMs.
- **AI conditional logic** — The AI may add `showIf` conditions on generated questions when a follow-up is genuinely only relevant given a prior answer. Values are snapped to existing option values; forward references and circular dependencies are rejected by construction.
- **AI admin panel** — New "AI" tab in FormVox admin settings with provider-availability status (live-detected task type), per-instance enable/disable toggle, max-questions-per-form slider (3-20), max source-document size slider (1-25 MB), and toggles for source-document upload and conditional logic features.
- **Scheduled opening (`share_starts_at`)** — Share links can be scheduled to open in the future. Before the start time, visitors see a "This form is not yet open — opens at {date}" page; submissions/uploads are blocked server-side on all public endpoints.
- **Per-form branding logo uploads** — Logo and image blocks in per-form branding now upload to `.formvox-branding-{fileId}/` next to the `.fvform` file (mirrors the `.formvox-uploads-` pattern), travel along on form move, and are cleaned up on form delete ([#53](https://github.com/nextcloud/formvox/issues/53))
- **Result summary shows labels** — Radio/choice questions in the Results summary chart and legend now show the option label instead of the internal id ([#52](https://github.com/nextcloud/formvox/issues/52))
- **Full translations (EN, NL, DE, FR)** — 43 new user-facing strings for the AI flow, AI admin panel, scheduled opening and "not yet open" page are fully translated in all four supported languages

### Fixed
- **Share link date pickers don't persist changes** — Changing the expiration or opening date in the Share dialog now debounces and saves automatically instead of discarding the new value on reload
- **Notification icons missing on mobile/desktop clients** — All FormVox notifications now emit an absolute icon URL ([#54](https://github.com/nextcloud/formvox/issues/54))

### Changed
- **AI form generation is off by default** until the admin enables it (unless a provider was already installed when the admin first visits the AI tab, in which case it's enabled automatically for convenience)

## [1.0.2] - 2026-04-17

### Fixed
- **Telemetry error feedback**: The "Send report now" button now shows the actual server error message (e.g., rate limit, connectivity issue) instead of silently failing

### Security
- Updated `dompurify` and `follow-redirects` dependencies to fix moderate security vulnerabilities

## [1.0.1] - 2026-04-17

### Added
- **Team folder support** — Forms stored in Nextcloud Team Folders with object storage backends can now be loaded via public share links ([#49](https://github.com/nextcloud/formvox/issues/49))
- **Native date/time pickers** — Share link expiration and `datetime` question answers now use two separate native fields (date + time) side-by-side for a consistent, accessible experience ([#48](https://github.com/nextcloud/formvox/issues/48))

### Fixed
- **Share link expiration date picker not working** — The expiration picker now correctly captures selected dates and times ([#48](https://github.com/nextcloud/formvox/issues/48))
- **Forms saved in team folders cannot be displayed** — `FormService::getFileByIdPublic` now recognizes the `object::groupfolder:` storage pattern ([#49](https://github.com/nextcloud/formvox/issues/49))

### Security
- Security improvements to the public submission flow (token handling and share-link gating)

## [1.0.0] - 2026-04-07

### Added
- **Support tab in admin settings** — New "Support" tab in the FormVox admin panel with subscription pricing, installation statistics, organization contact fields, and license key management
- **License key management** — Admins can enter and activate a `FVOX-` subscription key, which is validated against the VoxCloud license server. The key is displayed masked and can be removed at any time
- **Installation statistics in Support tab** — Shows total forms, total responses, and total users directly in the admin panel
- **License banner** — An info banner appears at the top of the admin panel when the installation exceeds the free tier limits (25 forms or 50 users) and no valid subscription key is configured. A warning banner is shown when a subscription key is invalid or expired
- **Organization contact fields** — Admins can optionally save an organization name and contact email to be associated with their subscription
- **Background license sync** — A daily background job validates the license and reports usage to the VoxCloud license server, with per-instance jitter to spread server load
- **Telemetry section moved to Support tab** — The anonymous usage statistics section has been moved from the Statistics tab to the Support tab for better discoverability
- **Full translations (EN, NL, DE, FR)** — All Support tab strings are fully translated in all four supported languages

### Changed
- **Statistics tab simplified** — The "About FormVox" blurb and anonymous usage statistics section have been moved to the Support tab, keeping the Statistics tab focused on form and response counts

## [0.4.0] - 2026-04-07

### Added
- **Sections / fieldsets** — Group questions into collapsible sections with an optional title, description, and conditional display (`showIf`). Entire sections can be shown or hidden based on answers ([#38](https://github.com/nextcloud/formvox/issues/38))
- **Markdown in section descriptions** — Section descriptions support Markdown including images (`![](url)`)
- **Drag & drop into sections** — Drag questions into a section; `sectionId` is auto-assigned based on position
- **"Move to section" menu item** — Assign a question to a section via the `...` menu
- **Specify notification recipients** — Form owners can now select which users or groups receive a Nextcloud notification when a response is submitted ([#46](https://github.com/nextcloud/formvox/issues/46))

### Fixed
- **Form not submitted for logged-in users** — Forms with login requirement now correctly save responses without requiring edit-level share permissions ([#43](https://github.com/nextcloud/formvox/issues/43))
- **Scroll broken on public form** — Added `overscroll-behavior: auto` to override Nextcloud's layout scroll capture, restoring mouse wheel scroll on Windows/Chrome/Edge ([#44](https://github.com/nextcloud/formvox/issues/44))
- **CSV export shows option IDs instead of labels** — CSV export now outputs human-readable option names instead of internal IDs ([#41](https://github.com/nextcloud/formvox/issues/41))
- **Images embedded in ODT exports** — Uploaded image files are now embedded directly in the ODT document ([#45](https://github.com/nextcloud/formvox/issues/45))

### Changed
- **Toolbar layout** — Editor toolbar splits into left (content actions) and right (view/share actions); labels hide when space is tight
- **"Add page" moved to `...` menu** — Keeps the toolbar compact when pages are enabled
- **odf-kit updated to v0.9.4** — Picks up latest fixes from upstream

## [0.3.9] - 2026-03-26

### Added
- **ODT template export** — Upload a custom ODT template with placeholders like `{Q1}`, `{Q2}`, `{form_title}`, etc. Responses are automatically filled into your template when exporting as ODT ([#23](https://github.com/nextcloud/formvox/issues/23))
- **Template auto-detection** — "Export ODT" now automatically uses the uploaded template if one exists, removing the need for a separate export option
- **Template portability** — ODT templates follow the form when moved between folders and are cleaned up when the form is deleted
- **Table question type** — New "Table" question with configurable columns (text, number, date, dropdown) and dynamic rows. Ideal for expense declarations, item lists, and structured data collection ([#35](https://github.com/nextcloud/formvox/issues/35))
- **Notification on new responses** — Form owners receive a Nextcloud notification when someone submits a response. Toggle on/off per form in Share settings
- **Wider form layout** — Public form container increased from 700px to 960px for better readability

### Fixed
- **TelemetryJob crash** — Background job crashed with `fetchAssociative()` not found on Nextcloud's `ResultAdapter`. Changed to `fetch()` ([#31](https://github.com/nextcloud/formvox/issues/31))
- **External API missing pages/pageOrder** — API response now includes `pages` and `pageOrder` fields ([#27](https://github.com/nextcloud/formvox/issues/27))
- **Required matrix not validated on page navigation** — Users could skip to the next page without filling required matrix questions ([#34](https://github.com/nextcloud/formvox/issues/34))
- **Required matrix accepting one row** — Matrix questions marked as required now require all rows to be answered ([#25](https://github.com/nextcloud/formvox/issues/25))
- **Horizontal scrolling blocked for wide matrix tables** — Matrix tables now scroll horizontally within the form container ([#28](https://github.com/nextcloud/formvox/issues/28))
- **Multiple file uploads broken** — File metadata was lost for multi-file uploads, showing only filenames instead of clickable links in results ([#36](https://github.com/nextcloud/formvox/issues/36))
- **Multi-file upload count incorrect** — File count now shows total number of files, not number of responses
- **`[object Object]` in ODT export** — Multi-file answers now display filenames correctly in ODT exports
- **Webhook creation failing** — Fixed parameter binding for webhook creation endpoint
- **Form hard to read in dark mode** — Public form templates had hardcoded light background colors that overrode dark mode CSS variables, making text unreadable ([#39](https://github.com/nextcloud/formvox/issues/39))

### Changed
- **Presence endpoints moved to PresenceController** — Collaborative editing presence heartbeat and editor list moved from `ApiController` to dedicated `PresenceController` for cleaner separation
- **FormDeletedListener extended** — Now also cleans up ODT template folders when a form is deleted

## [0.3.8] - 2026-03-13

### Fixed
- **Form submits on Enter key in multi-page forms** - Pressing Enter in a text input triggered the browser's native form submit event, bypassing page navigation and submitting the form even on page 1 of a multi-page form. The submit handler now checks for remaining pages and navigates forward instead of submitting ([#21](https://github.com/nextcloud/formvox/issues/21))

## [0.3.7] - 2026-03-13

### Fixed
- **Single choice / multiple choice / dropdown options have no value** - Option values were never populated when creating questions in the editor (always empty string `""`), making all options indistinguishable. Radio buttons, checkboxes, dropdowns, and conditional rules all failed because every option had the same empty value. Now generates a unique value (based on option ID) for each option. Existing forms with empty values are automatically migrated on load ([#16](https://github.com/nextcloud/formvox/issues/16), [#18](https://github.com/nextcloud/formvox/issues/18))

## [0.3.6] - 2026-03-13

### Fixed
- **Translation .js runtime files not updated** - The v0.3.5 release fixed placeholder names in `.json` files but not in the corresponding `.js` files that Nextcloud actually loads at runtime via `OC.L10N.register()`. All broken placeholder names are now also fixed in `de.js`, `nl.js`, and `fr.js` ([#22](https://github.com/nextcloud/formvox/issues/22))
- **Single choice / checkbox questions not selectable** - Confirmed fix was present in source since v0.3.2 but may not have been correctly included in the v0.3.4 App Store tarball. This release ensures the correct compiled JavaScript is shipped ([#18](https://github.com/nextcloud/formvox/issues/18))

## [0.3.5] - 2026-03-12

### Fixed
- **Form submits instead of going to next page on multi-page forms** - Previous and Next buttons were missing `native-type="button"`, causing the browser to treat them as submit buttons inside the form element. Clicking Next triggered both page navigation and form submission simultaneously ([#21](https://github.com/nextcloud/formvox/issues/21))
- **Translation placeholders not interpolated** - Placeholder names in German, Dutch and French translations used translated names (e.g. `{aktuell}`, `{huidig}`, `{courant}`) instead of the original variable names. Vue's `t()` function only substitutes exact matches, so the raw placeholder text was shown instead of the actual value ([#22](https://github.com/nextcloud/formvox/issues/22))
- **Screenshot filenames with spaces** - Renamed all screenshot files to use hyphens instead of spaces and updated `info.xml` URLs accordingly to improve compatibility

## [0.3.4] - 2026-03-12

### Added
- **Date/time range restrictions** - Set minimum and maximum allowed values for Date, DateTime, and Time questions ([#15](https://github.com/nextcloud/formvox/issues/15))
  - Date/DateTime: NcDateTimePicker-based min/max selectors in question settings
  - Time: native time input for earliest/latest allowed time
  - Client-side and server-side validation with clear error messages
  - Date picker automatically restricts selectable dates to the allowed range
- **Markdown support in descriptions** - Question descriptions now render Markdown formatting ([#5](https://github.com/nextcloud/formvox/issues/5), [#6](https://github.com/nextcloud/formvox/issues/6))
  - Bold, italic, links, images, lists, headings, and more
  - Images in descriptions are rendered inline with responsive sizing
  - External HTTPS images allowed via Content Security Policy
  - Links are auto-linked and clickable
  - Answer piping (`{{Q1}}`) still works alongside Markdown
  - TTS (text-to-speech) strips Markdown syntax for natural speech output
- **Condition editor date/time picker** - Condition value inputs now auto-detect the question type and show the appropriate picker (date picker, datetime picker, or time input) instead of a plain text field

### Fixed
- **Date comparison in conditions not working** - "Greater than" and "Less than" operators on date questions always evaluated to false because date strings (e.g. `2026-03-12`) were converted with `Number()` resulting in `NaN`. Now correctly compares date strings lexicographically ([#19](https://github.com/nextcloud/formvox/issues/19))
- **Question reordering not visible to respondents** - Dragging questions to a new position in the editor was saved correctly, but the public form still displayed questions in their original creation order. Fixed by using the page's question ID order instead of the form's question array order ([#20](https://github.com/nextcloud/formvox/issues/20))

## [0.3.3] - 2026-03-11

### Fixed
- **Public form scrolling broken on some Nextcloud setups** - Contradictory CSS `overflow: visible` combined with `overflow-x: hidden` / `overflow-y: auto` caused scroll to break per CSS spec. Removed `overflow: visible` from all public templates ([#17](https://github.com/nextcloud/formvox/issues/17))

## [0.3.2] - 2026-03-10

### Fixed
- **Single choice questions broken** - All radio buttons were pre-selected and user selection didn't work. Fixed incorrect NcCheckboxRadioSwitch API usage: `model-value` now receives the selected value string instead of a boolean ([#16](https://github.com/nextcloud/formvox/issues/16))
- **Multiple choice questions broken** - Clicking one checkbox selected all options. Fixed by passing an array as `model-value` with proper `:name` and `:value` props for NcCheckboxRadioSwitch's array management ([#16](https://github.com/nextcloud/formvox/issues/16))
- **Dropdown selection not registering** - Dropdown questions showed validation errors even when answered. Root cause was the same NcCheckboxRadioSwitch misuse in the underlying component ([#16](https://github.com/nextcloud/formvox/issues/16))
- **Matrix radio buttons broken** - Matrix question type had the same boolean vs string model-value issue ([#16](https://github.com/nextcloud/formvox/issues/16))

## [0.3.1] - 2026-03-06

### Fixed
- Added missing translations for all 0.3.0 features (page routing, collaborative presence, draft autosave, QR code, accessibility) in NL, DE, FR

## [0.3.0] - 2026-03-06

### Added
- **Accessibility (a11y) improvements** for public form response pages
  - **Text-to-Speech (TTS)** - Speaker icon per question to read question text, description, and answer options aloud using the Web Speech API
  - Toggle behavior: click to start reading, click again to stop
  - Language automatically detected from Nextcloud user locale
  - Visual feedback on speaker button while reading (color change)
- **ARIA attributes** on all question types for screen reader support
  - `role="group"` with `aria-labelledby` on every question
  - `aria-required`, `aria-invalid`, `aria-describedby` on all input fields
  - `role="radiogroup"` for single choice, scale, and rating questions
  - `role="alert"` on validation error messages
  - `aria-live="polite"` on page indicator and submission status
  - `aria-live="assertive"` on form error messages
  - `aria-label` on file upload zone, remove buttons, and matrix radio buttons
  - `scope="col"` / `scope="row"` on matrix table headers
- **Keyboard navigation** for custom controls
  - Arrow keys (left/right/up/down) to navigate scale and star rating buttons
  - Home/End keys for first/last option
  - Enter/Space to activate file upload zone
  - Roving tabindex (WAI-ARIA radiogroup pattern) on scale and rating
- **Focus management**
  - On validation error: scroll to and focus first question with error
  - On page navigation: focus first question on new page
  - After submission: focus thank-you page for screen reader announcement
  - TTS automatically stops on page navigation and form submission
- **Skip link** - "Skip to form questions" link (visible on Tab focus) to bypass headers
- **Per-question inline validation errors** alongside global error banner
- Screen reader-only status announcements for submission progress
- **Conditional page routing** - Skip to specific pages based on answers in multi-page forms
  - Configure routing rules per page in the editor (If question → operator → value → go to page)
  - Operators: equals, not equals, contains, is empty, is not empty, greater than, less than
  - Falls back to linear navigation when no rule matches
  - Back button navigates through the routed path (not just previous page number)
- **QR code generation** - QR code in the Share dialog for form links
  - Auto-generated when a share link is created
  - Download as PNG with form title in filename
- **Draft autosave** - Automatically saves form responses in the browser (localStorage)
  - Respondents can resume where they left off after closing the browser
  - "Welcome back" banner with Continue / Start over options
  - Drafts expire after 7 days and are cleared on successful submission
- **Collaborative editing presence** - See who else is editing a form
  - Avatar indicators in the editor toolbar showing active editors
  - Heartbeat-based presence detection (30-second polling)

### Changed
- TTS language now uses browser language instead of Nextcloud instance language
- **Nextcloud 33 support** - App now supports Nextcloud 28 through 33
- Replaced deprecated `IResult::fetch()` with `fetchAssociative()` in StatisticsService

### Fixed
- **Mimetype registration breaking all file types** - FormVox's MIME type registration in `Application::boot()` populated `MimeTypeDetector::$mimeTypes` before core defaults were loaded, causing Nextcloud to lose mimetype detection for images, PDFs, and all other file types ([#12](https://github.com/nextcloud/formvox/issues/12))
  - After updating, run `occ maintenance:mimetype:update-db` and `occ maintenance:mimetype:update-js` to restore mimetypes

## [0.2.9] - 2026-02-05

### Added
- **Question color coding** - Assign colors to individual questions for visual organization
  - 7 color options (blue, green, orange, red, purple, cyan, brown)
  - Color indicator in question header with dropdown picker
  - Colored left border on questions in editor and public forms
- **Custom regex validation** per question with custom error messages
  - Define validation patterns (e.g., postal codes, license plates, phone numbers)
  - Custom error messages when validation fails
  - Real-time validation feedback on form submission
- **Response limits** - Set maximum number of responses per form
  - Custom "form closed" message when limit is reached
  - Live counter showing current vs max responses

### Changed
- **Share dialog reorganization**
  - Response settings and Link settings are now always visible (not collapsed)
  - Embed code, API & Webhooks, and Responses moved to collapsible "Advanced" section
  - Cleaner, more intuitive settings layout

### Fixed
- **Scroll issues on public forms** caused by password manager browser extensions
  - Fixed for LastPass, Bitwarden, 1Password, and similar extensions
  - Added CSS workarounds for extension-injected elements
- Improved scroll compatibility for Nextcloud 28+ public page layout
- **Nextcloud 33 compatibility** - Fixed deprecated `OC_App::getAppPath()` call

## [0.2.8] - 2026-02-02

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

### Fixed
- PreviewProvider regex pattern fix (preg_match delimiter error)

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
