# FormVox 1.5.1-dev — local fix for issue #144

Based on upstream v1.5.0, commit 06598c80c60eb2516fe87df45c8a6abdfe38de9d.
This is a local, unsigned development build, not an official FormVox release.
Issue: https://github.com/nextcloud/formvox/issues/144

## Install with AppDrop

1. Close open FormVox editor tabs, so an old editor cannot save stale form data.
2. Download a backup copy of your `.fvform` file from Nextcloud Files.
3. In AppDrop, upload `formvox-1.5.1-dev.zip` and review the validation results.
   It should identify app ID `formvox`, version `1.5.1-dev`.
4. Choose Update. AppDrop documents an automatic backup of the existing app;
   verify that this backup is listed before relying on it for rollback.
5. Reopen FormVox and hard-refresh the editor and public page (Brave/macOS:
   Command+Shift+R). Check that Nextcloud lists version `1.5.1-dev`.

AppDrop accepts ZIP packages with the app directory at the archive root. This
ZIP contains `formvox/appinfo/info.xml`, compiled browser assets and the PHP
backend; no server-side npm/composer build is required. It has no upstream
signature. If AppDrop or Nextcloud refuses an unsigned package, stop and report
the exact validation message; do not disable Nextcloud integrity checks.

Version `1.5.1-dev` compares newer than `1.5.0` and older than stable `1.5.1`.
AppDrop's documented default upload limit is 20 MB.

## Test the affected form

1. With confirmations enabled, open the existing public form in a new private
   window. It should show exactly one generated email field, even if the form
   previously showed three. No deletion of submissions is needed.
2. Disable confirmations, wait for the saved message, and reload the public
   form. No generated email field should be visible or required.
3. Submit a test response with confirmations disabled. It should succeed without
   an email address. Fill all ordinary required fields as usual.
4. Enable confirmations, wait for saving, and reload the public form. Exactly
   one generated email field should appear.
5. Repeat disable/enable three times. It should still show only one field.
6. Submit a response with a valid email address. It should succeed; verify the
   existing confirmation email delivery separately on your Nextcloud instance.
7. Check the results/export: previous responses and their historical email
   columns must remain available. Old duplicate columns are intentionally kept
   when responses exist; only their respondent-facing fields are hidden.
8. Optional, using a disposable form with no submissions: disable confirmations.
   All generated duplicate questions and their page references should be removed.

This patch addresses duplicate email fields and their submission validation.
It does not change notification delivery or German wording.

## Behaviour and compatibility

- Reuses a retained generated question when enabling confirmations.
- Keeps old question IDs and answers so historical results/export remain intact.
- Hides inactive generated questions and excludes them from progress and required
  field validation in both browser and server.
- Cleans all generated fields on disabling if there are no responses.
- Preserves user-created fields and their normal validation.
- Prevents overlapping toggle saves and restores local state after failed saves.
- Uses existing schema flags; no database migration or answer rewrite.

## Validation performed

- 112 JavaScript tests passed across ShareDialog, Respond and Results.
- All seven new frontend regression cases fail against upstream v1.5.0 and
  pass with this patch.
- 136 ResponseService PHP tests passed, 227 assertions, using PHP 8.3.32.
- Production webpack build passed, with three upstream performance warnings.
- PHP syntax check and git diff whitespace check passed.
- Installation, SMTP delivery and end-to-end execution inside a running Nextcloud
  instance have not been tested here; use the procedure above after installation.

## Source and rebuilding

`formvox-issue-144.patch` is the source/test/version patch against v1.5.0.
Compiled JavaScript is included in the ZIP, not in the patch.

```sh
git clone --branch v1.5.0 --depth 1 https://github.com/nextcloud/formvox.git
cd formvox
git apply ../formvox-issue-144.patch
npm ci
npm run test:js -- tests-js/components/ShareDialog.spec.js tests-js/views/Respond.spec.js tests-js/views/Results.spec.js --maxWorkers=1 --minWorkers=1
composer install
php vendor/bin/phpunit tests/Unit/ResponseServiceTest.php
npm run build
```

## Rollback

Use AppDrop's Backups section to restore the saved original FormVox app. This
returns the original code, including the original bug. If needed, restore your
separately saved `.fvform` copy through Nextcloud Files; this discards any test
submissions or edits made after that backup. No rollback is performed automatically.
