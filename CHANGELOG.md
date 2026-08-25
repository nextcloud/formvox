# Changelog

All notable changes to FormVox will be documented in this file.

## [1.4.3] - 2026-08-25

### Fixed
- **The "This question is required" message appeared twice.** Text, number and long-text questions printed the message next to their own field and again below it, and the two copies shared one HTML id, which broke the link screen readers follow from the input to its error.
- **A number or time question could report "required" while visibly filled.** Typing letters into a number field leaves the browser holding text it refuses to parse, so the form saw no answer at all and asked for one that appeared to be there. Such a field now says "Enter a number" (or "Enter a valid time") instead.
- **The subscription notice said "invalid or expired" whatever was actually wrong.** The licence server distinguishes four refusals — the key expired, it is unknown, it is already registered to another Nextcloud instance, or it was deactivated — but FormVox collapsed all of them into one message. Each needs a different response: a renewal, a typo check, or a call to us about a server move. Every reason now gets its own message, and an expired subscription names the date it lapsed instead of only saying that it did.
- **"View subscription options" did nothing when already on the Support tab.** The licence notice sits above the tab bar and stays visible everywhere, so on Support its button pointed at the tab you were already looking at. It is now shown only where it has somewhere to go.
- **Telemetry mis-detected Nextcloud Enterprise.** The subscription check read the *Extended Support* add-on rather than the subscription itself, so instances with a plain Enterprise subscription were reported as Community. It now uses `IRegistry::delegateHasValidSubscription()` (public API since NC 17). This only affects the usage figures reported back to VoxCloud; nothing in the app behaves differently.

### Changed
- **Interface wording now follows the Nextcloud guidelines.** Labels that used Title Case are sentence case ("Total forms" rather than "Total Forms"), and "…" in progress text is a real ellipsis. Product names keep their capitals. Existing translations were carried over, so nothing became untranslated.
- **Removed an unused dependency.** `vue-easymde` was declared but never imported — the markdown editor uses `easymde` directly. Dropping it clears two high-severity advisories that reached the app only through that unused package.

### Internal
- **Groundwork for community translations.** The translation files are no longer maintained by hand: FormVox now generates a POT template and per-language PO files, the layout the Nextcloud translation bot expects. A request to add FormVox to the community Transifex project is pending; until it is accepted nothing changes for users, and Dutch, German, French, Catalan and Ukrainian ship as before.

## [1.4.2] - 2026-08-25

### Fixed
- **Responses submitted to forms in Team/Group folders could silently disappear.** When a form lived in a shared folder, the public submission was opened through an account that could see the form but not necessarily write to it — so on read-only members or advanced-permission rules the response write was refused without any error, while the respondent still saw "Thank you!". FormVox now resolves the form through an account that can actually write, and surfaces a clear error instead of losing the response if none can. ([#90](https://github.com/nextcloud/formvox/issues/90), [#101](https://github.com/nextcloud/formvox/issues/101))

### Changed
- **Licence reporting now counts users the same way everywhere.** FormVox already counted all users correctly; it now also reports how many accounts are disabled, and identifies the instance the same way IntraVox and IntroVox do. Previously each app derived that identifier differently, so the same server looked like several — which made usage impossible to line up across apps. Instances update automatically; nothing needs to be reconfigured.
- **Dependency updates.** Bumped `odf-kit` 0.13.10 → 0.14.1, `@nextcloud/vue` 9.8.2 → 9.9.0, `vue` 3.5.38 → 3.5.41, `dompurify` 3.4.12 → 3.4.14, `axios` 1.18.1 → 1.19.0, `markdown-it` 14.2.0 → 14.3.0, `fast-xml-parser` 5.10.1 → 5.11.0, `sass` 1.97 → 1.103, `webpack` 5.104 → 5.109 and related build tooling to their latest compatible releases, picking up upstream security and bug fixes.

## [1.4.1] - 2026-08-11

### Fixed
- **Code-integrity error on the FormVox file icon.** Earlier versions copied a file-type icon into Nextcloud's signed `core/` directory, which raised an `EXTRA_FILE` integrity warning and could stop Nextcloud AIO from starting. FormVox no longer writes into core, and the upgrade now removes the leftover file automatically so existing installs self-heal. The `.fvform` icon is unchanged — it's served from the app itself. ([#128](https://github.com/nextcloud/formvox/issues/128))

## [1.4.0] - 2026-07-14

### Added
- **Excel (.xlsx) export.** Results can now be downloaded as a real Excel spreadsheet alongside CSV. Because .xlsx has no encoding or separator guesswork, umlauts and columns are always correct in every Excel language — the definitive fix for spreadsheet display issues. Built without any new dependencies. ([#114](https://github.com/nextcloud/formvox/issues/114))
- **Selection and character limits.** Multiple-choice questions can be given a minimum and maximum number of selections, and long-text questions a maximum character count, with a live counter shown to respondents. Both are enforced on the server. ([#113](https://github.com/nextcloud/formvox/issues/113))
- **Ukrainian translation.** FormVox is now available in Ukrainian. ([#115](https://github.com/nextcloud/formvox/issues/115))

### Fixed
- **German umlauts were corrupted in the CSV export when opened in Excel.** The export combined a UTF-8 marker with a separator directive that Excel treats as mutually exclusive, so accented characters like "ö" showed as garbled text. The CSV now keeps characters intact across locales; for guaranteed-correct columns everywhere, use the new Excel export. ([#114](https://github.com/nextcloud/formvox/issues/114))
- **Quiz score could exceed 100%.** For multiple-choice questions with partial points, the maximum possible score was miscalculated, so results like "15 out of 12.75" appeared. The maximum is now the sum of all correct options. ([#118](https://github.com/nextcloud/formvox/issues/118), thanks to @taigi100 for the report and fix)
- **Table and matrix answers now fill custom ODT template tables.** Using the `{#…}` loop syntax in a template table now repeats a row per answer entry, so custom templates get real tables instead of flat text. ([#112](https://github.com/nextcloud/formvox/issues/112))
- **Download and delete buttons on the results page moved to the left**, next to respondent names, and stay in view when scrolling wide forms with many questions. ([#121](https://github.com/nextcloud/formvox/issues/121))
- **Multi-page forms could become unsubmittable.** A question that ended up assigned to no page (e.g. after deleting its page, or from an auto-generated confirmation-email field) was invisible to respondents but still required by the server. Such questions are now always shown, and forms stay submittable.
- **Missing translations filled in.** Around 70 interface strings added in recent releases were not translatable; they are now translated across all supported languages.
- **Hardened performance and security across the app.** A full review of the codebase led to fixes for response-handling under concurrency, form-data write safety, public-page data exposure, and spreadsheet-formula safety in exports.

### Changed
- **Document toolkit updated** (`odf-kit` 0.9.4 → 0.13.10), which brings the table-row repetition needed for ODT template loops ([#112](https://github.com/nextcloud/formvox/issues/112)).
- **Dependency updates.** Bumped axios, dompurify, markdown-it, postcss, fast-xml-parser, fast-uri, linkify-it, immutable and form-data to their latest patch/minor releases, including security fixes.

## [1.3.1] - 2026-07-07

### Fixed
- **Forms list could take minutes to load on instances with slow external storage.** The forms overview scanned the file index of the entire instance and touched every user's storage — including slow SMB/network mounts of other users. It now only looks at storages you actually have access to, using an indexed lookup. On large instances this brings the overview from minutes back to milliseconds. The admin statistics panel got the same treatment. ([#110](https://github.com/nextcloud/formvox/issues/110))
- **Table and matrix answers now export as real tables in ODT.** The per-response ODT export rendered table/matrix questions as flat "Row 1: …" text lines; they are now proper formatted tables with a header row. Note: custom ODT templates use plain-text placeholders, so answers stay flat text there. ([#111](https://github.com/nextcloud/formvox/issues/111))

Note: forms that were copied onto external storage outside of Nextcloud may need a rescan (`occ maintenance:mimetype:update-db` or the automatic one during app upgrade) before they show up in the list.

## [1.3.0] - 2026-06-22

### Added
- **Nextcloud 34 support.** `info.xml` now declares `max-version="34"` and the app has been tested end-to-end on a clean NC 34.0.0 install.
- **Markdown alignment toolbar.** Description fields now have left / center / right alignment buttons that persist with the form and apply on the public page. ([#98](https://github.com/nextcloud/formvox/issues/98))
- **Info block question type.** New non-input question type that renders markdown content (heading, lists, links, etc.) anywhere in a form. Skipped in validation, results summary, table, and CSV export — mirrors how section headers already behave. ([#64](https://github.com/nextcloud/formvox/issues/64))
- **Consent question type.** Single-checkbox question type that requires an explicit tick when marked required — useful for GDPR consent, terms acceptance and similar. Stored as boolean, rendered as "Yes" / "No" in results and CSV. ([#94](https://github.com/nextcloud/formvox/issues/94))
- **Per-option capacity limits.** Choice / multiple-choice / dropdown options can now have an optional maximum number of selections. Reached options show a "Full" badge on the public form and the server rejects further selections. The "{n} left" badge updates after each submit. Only counts for questions with a capacity are exposed in the public payload — counts of other questions stay private. ([#104](https://github.com/nextcloud/formvox/issues/104))
- **Email confirmation to respondent.** A toggle in the share dialog automatically adds a hidden email question to the form and sends the respondent a plain-text confirmation after each submit. Subject and body can be customised; failures are logged and never block the submit. ([#103](https://github.com/nextcloud/formvox/issues/103))
- **Admin-managed form templates.** New "Templates" tab in admin settings lets administrators snapshot any existing form into an instance-wide template. Templates appear in the "New form" gallery for every user alongside the built-in ones, with shared / responses / lock state stripped from the snapshot. ([#100](https://github.com/nextcloud/formvox/issues/100))

### Changed
- **Minimum Nextcloud version raised from 28 to 30.** NC 28 and 29 have reached end-of-life. This unlocks `@nextcloud/vue` v9, `@nextcloud/files` v4 and the modern attribute-based controller API.
- **Frontend dependency bumps.** `@nextcloud/vue` 9.8, `@nextcloud/dialogs` 7.4, `@nextcloud/axios` 2.6, `@nextcloud/router` 3.1, `@nextcloud/files` v4 (migrated to the v4 plain-object `IFileAction`).

### Fixed
- **NC 34: removed `OC::$server->getX()` getter calls.** Replaced with constructor DI on every call site (`AiFormGeneratorService`, `BrandingService`, `RegisterMimeType`) — without these, the AI extraction, branding image route and `RegisterMimeType` repair step would have fataled on NC 34.
- **NC 34: `Notifier::prepare()` now throws `UnknownNotificationException`** instead of `\InvalidArgumentException`, removing the deprecation warning that NC 33/34 emit on every notifications poll.
- **NC 34: `ImportController` annotations migrated to PHP attributes** (`@NoAdminRequired` → `#[NoAdminRequired]`).

## [1.2.5] - 2026-06-22

### Fixed
- **Nextcloud Enterprise instances were never recognised in telemetry.** The telemetry instance hash was computed differently from the license `instance_url_hash` (different fallback source and/or no URL normalisation), so the license server's enterprise-claim validation could never match the two. `TelemetryService::getInstanceHash()` now delegates to `LicenseService::getInstanceUrlHash()`, guaranteeing both are byte-for-byte identical. Existing instances will report under a new (correct) instance hash on their next telemetry run.

## [1.2.4] - 2026-06-08

### Changed
- **Telemetry no longer sends organization name or contact email** — These two fields were the only PII in the FormVox telemetry payload and were inconsistent with MetaVox/IntraVox, which never sent them. The "Your organization (optional)" form on the Support tab and the underlying `/api/settings` GET/POST endpoints have been removed; previously stored values stay in `appconfig` but are no longer read or transmitted.

### Fixed
- **Telemetry reports missing host/country fields** — FormVox's telemetry payload didn't include `defaultTimezone`, `defaultLanguage`, `databaseType`, `osFamily`, `webServer`, `isDocker` or `countryCode`, so the license server's All Instances dashboard showed "—" for country and OS on almost every FormVox install. The payload now matches MetaVox/RoomVox and includes the same fields, letting the server populate country via `default_phone_region` or the timezone fallback.

## [1.2.3] - 2026-05-30

### Added
- **Catalan translation** — Full Catalan (ca) translation added, contributed by @NefixEstrada. ([#96](https://github.com/nextcloud/formvox/pull/96))

### Fixed
- **Public form submit stuck on "Submitting…" on default Nextcloud installs** — The browser blocked the anti-bot Web Worker because the default Nextcloud CSP did not allow it. Public form pages now explicitly allow it. ([#95](https://github.com/nextcloud/formvox/issues/95))
- **Form submitted "successfully" but the response disappeared** — Some storage backends silently rejected the response write while the UI still showed "Thank you!". The write result is now verified and a real error surfaces instead of a false success. ([#97](https://github.com/nextcloud/formvox/issues/97))
- **Multi-page forms skipped pages on "Next"** — Clicking Next jumped from page 1 to page 3 because the button accidentally also triggered the form submit. The button type is now correct so each click moves exactly one page. ([#99](https://github.com/nextcloud/formvox/issues/99))
- **Page-routing rules on choice questions didn't match** — A rule like "if answer = Ja → go to page 3" never fired because the routing editor saved labels while the form stored option ids. Both forms of rule now match correctly. ([#99](https://github.com/nextcloud/formvox/issues/99))

## [1.2.2] - 2026-05-18

### Fixed
- **Required questions inside a hidden section blocked submission** — When a section had a `showIf` condition that evaluated false, the section (and the questions inside it) were not shown to the respondent — but the server still enforced `required: true` on those hidden questions, rejecting the submit with "Question 'X' is required". Server-side validation now treats any question whose parent section is hidden as hidden too, matching the frontend behaviour. ([#92](https://github.com/nextcloud/formvox/issues/92))
- **CSV export opened as a single column in non-English Excel locales** — Dutch, German, French (etc.) Excel installations default to `;` as list separator and parsed our comma-separated CSV as one giant column per row. The export now prepends a `sep=,` directive so Excel honours the comma regardless of locale; RFC 4180 parsers (Pandas, R, LibreOffice) treat it as a non-data line. ([#91](https://github.com/nextcloud/formvox/issues/91))
- **Sections appeared as empty columns/rows in results** — Sections are UI grouping containers, not questions, but the summary view, the responses table and the CSV export all looped over `form.questions` indiscriminately and emitted an empty column for each section. All three code paths now skip section items.
- **AI form generation modal stayed open after completion** — Three combined regressions: the polling loop checked for status `4` while NC TaskProcessing returns `3` for "successful", `showSuccess` was never imported, and the success branch tried to navigate to a non-existent fileId. The modal now closes correctly, shows the success toast, and emits a new `ai-completed` event that the parent uses to refresh the form list so the newly generated form appears on the homepage without a manual reload.

### Changed
- **Upgraded `sass-loader` to v16** (modern Dart Sass compiler API).

## [1.2.1] - 2026-05-12

### Changed
- **Pricing removed from admin Support tab** — The Support tab no longer hardcodes subscription tiers and prices. A single "View pricing & plans" button now links to voxcloud.nl/pricing/#formvox where pricing is maintained. Reason: keeping prices in the app required a new App Store release for every price change (review time: days to weeks); the website can be updated instantly. The Support tab now focuses on installation state, organization details, and subscription-key management.
- **Telemetry transparency expanded** — The "What we collect" list in the Anonymous Usage Statistics section now accurately reflects every field actually sent in the telemetry payload, including the organization name and contact email (only sent if filled in by the admin) and the new Extended Support flag (see below). The previous list omitted these fields.

### Added
- **Extended Support / Enterprise flag in telemetry** — The telemetry payload now includes `hasExtendedSupport`, sourced from Nextcloud's public `OCP\Util::hasExtendedSupport()` API (NC 17+). Returns false on any failure so a Community instance is never reported as Enterprise. The license key is sent alongside so the license server can cross-check the claim against an active subscription — the boolean alone is unauthenticated and could otherwise be spoofed. Required for the Nextcloud ISV partnership where bundled-license customers need automatic recognition.
- **Description links open in a new tab** — Links in form, section and question descriptions now open in a new browser tab with `rel="noopener noreferrer"`, so respondents don't lose their in-progress form when they click a reference link. ([#87](https://github.com/nextcloud/formvox/issues/87))

### Removed
- "What a subscription includes" checklist with green checkmarks — content moved to voxcloud.nl/pricing/#formvox.
- Hardcoded pricing tiers (Free + €19/€59/€139/year + Contact us) — content moved to voxcloud.nl/pricing/#formvox.
- Standalone "Learn more about FormVox" contact block at the bottom of the Support tab — replaced by an inline "Questions? info@voxcloud.nl" link next to the new pricing CTA.

### Fixed
- **Submit failed on password-protected public forms** — After entering the share password the user could open the form but every submit was rejected with "Password required" because the frontend never replays the password on subsequent requests. The authenticate flow now sets a signed, HMAC-protected `formvox_pw_<fileId>` cookie (1 h validity, `SameSite=Lax`) which the share-gate accepts as proof of password possession on submit and upload. ([#82](https://github.com/nextcloud/formvox/issues/82))
- **Date picker selected the day before in non-UTC time zones** — Picking 17 May in CEST was serialised as `2026-05-16` because the previous implementation called `toISOString()` (UTC) on a `Date` constructed at local midnight. Date questions now serialise using local Y-M-D and parse `YYYY-MM-DD` strings into a local-midnight `Date` so the displayed date always matches the picked date. ([#80](https://github.com/nextcloud/formvox/issues/80), [#89](https://github.com/nextcloud/formvox/issues/89))
- **CSV export still fragmented in Excel** — `fputcsv()` used PHP's default `\n` record separator while in-cell newlines were normalised to `\r\n`, producing mixed line endings that some Excel versions interpreted as a new row inside a quoted cell. Both writes now use the explicit `eol: "\r\n"` argument so record terminators and in-cell newlines are consistent CRLF. ([#83](https://github.com/nextcloud/formvox/issues/83))
- **Question labels shoved sideways in Microsoft Edge** — The flex container holding a question label and the TTS button could grow horizontally beyond its parent in Edge when the label was long. The label is now a shrinkable flex item (`flex: 1 1 auto; min-width: 0`) with `overflow-wrap: break-word` and the row allows wrapping, matching the layout other browsers already produced. ([#84](https://github.com/nextcloud/formvox/issues/84))
- **"Move to section" did nothing when Pages were enabled** — The pages-mode draggable did not listen for the `move-to-section` event from the question overflow menu, and questions dragged under a section header did not become visually nested because the wrapper that applies the indent style was only rendered in single-page mode. Both code paths now match: dropdown moves work, drag-into-section auto-assigns the `sectionId`, and dragging a section header carries its children with it on the same page. ([#88](https://github.com/nextcloud/formvox/issues/88))

## [1.2.0] - 2026-05-05

### Added
- **Bot protection that works behind NAT** — Public form submissions are now protected by an ALTCHA-style proof-of-work challenge solved in the user's browser, replacing per-IP rate limiting as the primary anti-bot defense. Cost is paid per browser, so an organisation with hundreds of users behind a single NAT IP all submit without throttling. The challenge is invisible to legitimate users (~50–150 ms of work in a Web Worker), self-hosted (no third-party service, no external JS, no API keys, GDPR-clean), and adapts difficulty to the per-form submit rate so attackers pay more under load. The signature is bound to the form's file ID so a challenge issued for one form cannot be reused on another. Single-use replay protection via Nextcloud's distributed cache (Redis) with APCu fallback for single-server installs. ([#76](https://github.com/nextcloud/formvox/issues/76))

### Changed
- **Anonymous submit rate limit raised from 100/hour to 25 000/hour** — With ALTCHA now the primary defense, the per-IP limit becomes a wide safety net rather than the front line. The new ceiling comfortably accommodates large-organisation peaks (think 10 000 employees filling in a training evaluation in one hour) while still bounding pathological abuse if the cache backend goes down.

### Fixed
- **Form description rendered as plain text on the public form** — The form description on the public response page now renders as markdown instead of literal text with the raw `#`/`*` characters and collapsed newlines. Headings, lists, links, code, and blockquotes in the form description, section descriptions, and the in-editor markdown preview all render with proper visual styling. ([#63](https://github.com/nextcloud/formvox/issues/63))
- **"Form not found" / "Access forbidden" for logged-in respondents on restricted folders** — When a public form had `require login` enabled and was stored in a Group Folder or Team Folder the respondent was not a member of, the submission failed because the authenticated submit path used a user-context file load. Authenticated respondents now use the same admin-bypass loader as anonymous submissions, so the share link plus token (and any `allowed_users`/`allowed_groups` rules) are the only gate — no folder ACL needed. ([#77](https://github.com/nextcloud/formvox/issues/77))

## [1.1.5] - 2026-05-04

### Added
- **Markdown editor for descriptions** — Form description and per-question/section descriptions now use a native Nextcloud-style markdown editor (EasyMDE) with a toolbar for bold, italic, headings, lists, links, images, and preview. Includes a custom drag handle to resize the editor vertically.

### Changed
- **Form editor layout redesign** — Top-level form actions (Edit/Preview tabs, Share, Results, and the Pages/Branding/Settings overflow menu) now live in a sticky page header at the top of the editor, instead of a horizontal bar that visually appeared to belong to the form description. Question and section creation moved to a dedicated "+ Add question" rail below the question list — the spot where the cursor naturally lands after editing the previous question.

### Fixed
- **Conditional logic broke for multiple-choice answers** — `showIf` evaluation now correctly handles array answers from multiple-choice/checkbox questions in both the frontend evaluator and PHP backend, instead of comparing the whole array against a single value. ([#71](https://github.com/nextcloud/formvox/issues/71))
- **CSV export of table answers showed internal column ids** — Table-type answers in CSV exports now use the column labels from the form definition instead of internal column ids. ([#70](https://github.com/nextcloud/formvox/issues/70))
- **Orphaned fields persisted after question type change** — Switching a question's type (e.g. from `scale` to `text`) now strips type-specific fields (options, scale bounds, rating, matrix, table, file, validation, date bounds) so the saved question matches its new type. ([#69](https://github.com/nextcloud/formvox/issues/69))
- **Newlines in answers broke CSV row alignment** — Long-text answers containing newlines are now normalised to `\r\n` per RFC 4180 before being written to CSV, so spreadsheets parse rows correctly. ([#65](https://github.com/nextcloud/formvox/issues/65))

## [1.1.4] - 2026-04-24

### Fixed
- **Description textareas overlap question actions when resized** — The question description and section description textareas in the form editor no longer have a resize handle, preventing them from growing over the per-question action buttons (edit/delete/drag) when dragged. Matches the existing behaviour of the top-level form description. ([#62](https://github.com/nextcloud/formvox/issues/62))

## [1.1.3] - 2026-04-24

### Fixed
- **Webhook "Enabled" toggle unresponsive** — The enable/disable switch in Share → Advanced Settings → Integrations now correctly reflects its state and persists changes. Previously the switch used a deprecated Vue prop API (`:checked` / `@update:checked`) which silently sent `undefined` to the backend, disabling webhooks without feedback. ([#61](https://github.com/nextcloud/formvox/issues/61))
- **Admin statistics no longer crash on user-backend errors** — `getUserCount()` now wraps `callForAllUsers()` in a try/catch and falls back to `1` if the user backend throws (e.g., LDAP timeout), keeping the admin stats page, license usage reporter, and telemetry job running.

### Changed
- **License usage reports now include `activeUsers30d`** — The daily license sync (`/api/licenses/usage`) now carries the same active-user metric that telemetry already reports, giving the license server full visibility of active instance usage.

## [1.1.2] - 2026-04-23

### Fixed
- **CSV export garbled German/special characters** — CSV export now includes a UTF-8 BOM so Excel on Windows correctly recognises the encoding ([#57](https://github.com/nextcloud/formvox/issues/57))
- **Results chart legend shows internal option IDs** — The chart legend now uses the same label mapping as the charts themselves ([#58](https://github.com/nextcloud/formvox/issues/58))
- **Unanswered questions blank in Results** — Unanswered questions now show "Not answered" (translated) instead of a blank dash ([#58](https://github.com/nextcloud/formvox/issues/58))

## [1.1.1] - 2026-04-23

### Added
- **External storage support** — Forms stored on external storage mounts (SMB, SFTP, S3, local mounts) can now be loaded via public share links ([#55](https://github.com/nextcloud/formvox/pull/55))

### Security
- Updated `fast-xml-parser` from 5.5.7 to 5.7.1 (fixes malicious CDATA/comment sanitization and stack overflow on long tag expressions)

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
