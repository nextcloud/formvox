# Configuration

This guide covers administrator settings and configuration options for FormVox.

## Accessing Admin Settings

1. Log in as a Nextcloud administrator
2. Go to **Settings** (click your profile → Settings)
3. Under **Administration**, click **FormVox**

## Admin Settings Tabs

### Branding Tab

Configure organization-wide branding for all forms.

#### Default Branding
- **Header image URL** - Default logo/banner for new forms
- **Background color** - Default form background color
- **Accent color** - Default button and highlight color

#### Branding Inheritance
Forms can:
- Use organization defaults
- Override with form-specific branding

### Statistics Tab

View usage statistics across your Nextcloud instance.

#### Available Statistics
- **Total forms** - Number of forms created
- **Total responses** - Sum of all submissions
- **Active users** - Users who created forms (last 30 days)

#### Statistics Refresh
Statistics are loaded asynchronously when you open the Statistics tab, so the admin settings page loads instantly without waiting for statistics to be calculated.

### Embedding Tab

Control how forms can be embedded in external websites.

#### Allowed Domains

Restrict which external domains can embed FormVox forms:

1. Go to **FormVox** admin settings
2. Click the **Embedding** tab (or **Settings** tab)
3. Add allowed domains (one per line):
   ```
   sharepoint.company.com
   intranet.company.com
   *.trusted-domain.com
   ```
4. Save settings

**Options:**
- Leave empty to allow all domains (default)
- Use `*` as wildcard for subdomains
- Specify exact domains for strict control

**Security note:** Restricting embed domains prevents your forms from being embedded on unauthorized websites, reducing the risk of phishing attacks.


#### PDF Confirmation

> The PDF confirmation was developed at the request of, and funded by, the Landesinstitut für Schulqualität und Lehrerbildung Sachsen-Anhalt (LISA). It is part of the public FormVox code and available to every FormVox user.

Whether a form has PDFs at all is the form's own choice: **Keep a PDF of every response** in the share dialog, under
the confirmation email, off by default. On, a background job makes the PDF right after every submission and keeps it
with the response; the download in the results, **Export PDFs (ZIP)**, the external API, the webhooks' `pdf` block and
the confirmation email all give that one document. Off, no PDF is made for the form anywhere: the results show no PDF
download or ZIP, the API's PDF route answers `409` with reason `pdf_disabled`, webhooks carry `"pdf": null`, and
**Attach a PDF of the response** cannot be turned on. An import never gets PDFs, whatever the switch says.

Forms can send respondents their answers as a PDF with the confirmation email. A respondent who is signed in gets the
confirmation, PDF included, at the e-mail address of their account; an account without one gets no confirmation. By
default only they get the PDF: an anonymous respondent types the address in, and it is not checked. Under **Settings → PDF
confirmation**, **Also send the PDF to anonymous respondents** lets anonymous respondents get it too. Then at most 3 such
emails go to one address a day and 200 per form an hour; above that the email goes without the PDF. Keeping these
limits needs Redis or Memcached as Nextcloud's memory cache: APCu counts in each PHP process apart. Without one the switch
is off and cannot be turned on, and Nextcloud's overview of setup checks says so.

#### PDFs and webhooks: server settings that matter

- **`overwrite.cli.url`** in `config.php`: webhooks go out from a background job, where Nextcloud knows its own address
  only from this setting. Set it to the address your integrations use, or the `pdf.url` in a webhook points elsewhere
  (`http://localhost/…`).
- **`allow_local_remote_servers`**: Nextcloud sends no webhook to an address on its own network. When the receiver runs
  there (Windmill on the same network, for example), set `'allow_local_remote_servers' => true`.
- **`default_timezone`** and **`default_language`**: a PDF shows times in the respondent's time zone, when their browser
  gave one, and its labels in the language they filled the form in. A response that has neither, such as one through the
  API or an import, gets `default_timezone` (UTC when not set), and `default_language` when its PDF is made in the
  background or through the API; a download in the app makes it in the language of the user who downloads it.
- **Scripts**: the PDF is set in DejaVu Sans. Latin, Greek and Cyrillic text shows as typed; Chinese, Japanese, Korean,
  Thai, Indic scripts and emoji show as boxes, Arabic letters without their joined forms, and Arabic and Hebrew left to
  right.

### Telemetry Tab

Configure anonymous telemetry reporting.

#### What's Collected
- Number of forms
- Number of responses
- Number of active users
- Nextcloud version
- FormVox version
- PHP version

#### What's NOT Collected
- Form content
- Response data
- User information
- Server URLs or IPs

#### Opt-Out
To disable telemetry:
1. Go to **FormVox** admin settings
2. Uncheck **Enable anonymous telemetry**
3. Save settings

## App Configuration

### occ Commands

FormVox adds these occ commands:

```bash
# Move every form still in its file into the database now, and finish what earlier moves left
sudo -u www-data php occ formvox:migrate

# Show what is left to do: forms still in their file, moved forms still to be finished, broken files
sudo -u www-data php occ formvox:migrate --status

# Move only one form, by the file id of its form file
sudo -u www-data php occ formvox:migrate --file-id=<id>

# List the forms whose file disappeared without being deleted in Nextcloud (removed automatically 30 days later)
sudo -u www-data php occ formvox:orphans

# Remove one orphan now, with its responses and files
sudo -u www-data php occ formvox:orphans --remove=<form id>

# Bind a form's responses to a file by hand, when the file got a new id elsewhere than where it was
sudo -u www-data php occ formvox:rebind <file-id> <form-id>

# Free a form whose writer was killed while saving it, before FormVox's own lock expires on its own
sudo -u www-data php occ formvox:unlock <fileId>
```

Use `formvox:migrate` to move every remaining form right after the update instead of waiting for the background job;
`formvox:orphans` when a form's file disappeared without anyone deleting it in Nextcloud (a folder removed on disk, a
restore that missed it, a deleted team folder) and you want to see or remove its responses before the
30-day grace period ends. A form deleted for good in Nextcloud, from the trash, where no trash is in use, or with the
account whose home or trash held it, loses its responses, uploads and PDFs right away and never shows up here; `formvox:rebind` when a file got
a new id somewhere other than where it was, so FormVox cannot find it on its own; `formvox:unlock` only when a form
stays reported busy because the process writing it was killed. See [Where FormVox keeps
data](#where-formvox-keeps-data) for the migration to 1.6 and [Security](security.md#nextclouds-own-file-lock) for the
difference with Nextcloud's own file lock.

### Config Values

Set configuration via config.php or occ:

```bash
# Disable telemetry
sudo -u www-data php occ config:app:set formvox telemetry_enabled --value=0

# Set default branding color
sudo -u www-data php occ config:app:set formvox default_accent_color --value=#0082c9
```

## Where FormVox Keeps Data

Since 1.6.0, FormVox splits a form across three places:

- **The `.fvform` file** holds the form definition only: questions, pages and settings. It still lives in the owner's
  or team folder's file storage and follows standard Nextcloud file permissions, sharing and versions.
- **The database** holds a form's responses, its secrets (link token, link password hash, API keys, webhook secrets)
  and the counters behind the results page, in the `formvox_forms`, `formvox_resp`, `formvox_keys`, `formvox_counts`,
  `formvox_outbox` and `formvox_secrets` tables.
- **FormVox's app data** (outside any user's files) holds respondents' uploads, branding images, the ODT template and
  kept PDFs, one folder per form id.

### Upgrading to 1.6

Forms created before 1.6.0 still have everything in their `.fvform` file. After the update, a background job
(`MigrateFormsJob`) moves them into the database and app data automatically: it runs with every run of Nextcloud's
background jobs (every 5 minutes with system cron), keeps moving forms in for up to 2 minutes per run, and takes the
largest forms first, since they are the busiest. A site with a few thousand forms is done in about an hour. Once nothing is left, it looks again
only once a day, to pick up a form restored from the trash or a backup. A form is also moved in immediately, ahead of
the job, the first time anything writes to it: a response, a settings change, an editor save, or a file put over it
through WebDAV or a version restore.

Check progress with:

```bash
sudo -u www-data php occ formvox:migrate --status
```

This shows how many forms are still fully in their file, how many were moved in but still need their files (uploads,
branding, template, PDFs) moved or their file tidied up, and which files are named as a FormVox form but hold none; a
file like that is passed over and listed, not retried until the next update looks again. Run `occ formvox:migrate`
(no options) to move everything in now rather than waiting for the background job.

### Backups

Back up the database and FormVox's app data together with the files; a backup of the files alone no longer includes a
form's responses or its secrets. Restoring only the files brings the forms back, but without their responses, unless
the matching database backup is restored with them.

## Background Jobs

FormVox uses Nextcloud's background job system.

### Telemetry Job

If telemetry is enabled:
- Runs daily
- Reports anonymous usage statistics
- Minimal server impact

### Ensuring Jobs Run

Verify cron is configured:
```bash
sudo -u www-data php occ background:cron
```

Check job status:
```bash
sudo -u www-data php occ background-job:list | grep formvox
```

## Logging

### Log Levels

FormVox logs to Nextcloud's log file:
```
/path/to/nextcloud/data/nextcloud.log
```

Log levels:
- **Error** - Critical issues
- **Warning** - Non-critical problems
- **Info** - General operations
- **Debug** - Detailed debugging (enable in Nextcloud settings)

### Debugging Issues

To enable debug logging:
1. Set `'loglevel' => 0` in config.php
2. Reproduce the issue
3. Check the log file
4. Reset log level when done

## Integration Settings

### External Systems

FormVox supports integration via:
- REST API (see [API Reference](../architecture/api-reference.md))
- File system access (`.fvform` files are JSON)
- Nextcloud's sharing API

### External API & Webhooks

FormVox supports an External API with API key authentication and webhooks for real-time notifications. See the [External API & Webhooks documentation](../architecture/external-api.md) for details on:
- API key management (per-form, configurable permissions)
- CRUD operations on responses
- Webhook events (`response.created`, `response.updated`, `response.deleted`)
- HMAC-SHA256 signed payloads

## Performance Tuning

### For Large Installations

If you have many forms or responses:

1. **Enable APCu caching** in Nextcloud
2. **Use SSDs** for storage
3. **Configure proper cron** (not AJAX cron)

### Response Limits

For forms with thousands of responses:
- Results load progressively
- Exports may take longer
- Consider archiving old responses

## Security Configuration

See the dedicated [Security Guide](security.md) for:
- Rate limiting
- Access control
- Password policies

## Next Steps

- Configure [Security settings](security.md)
- Review [Architecture](../architecture/overview.md) for technical details
- Check [API Reference](../architecture/api-reference.md) for integrations
