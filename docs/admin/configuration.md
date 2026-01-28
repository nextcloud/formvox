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
Statistics are calculated in real-time when viewing the admin panel.

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

FormVox supports these occ commands:

```bash
# List all forms
sudo -u www-data php occ formvox:list

# Show form statistics
sudo -u www-data php occ formvox:stats

# Repair MIME types
sudo -u www-data php occ formvox:repair
```

### Config Values

Set configuration via config.php or occ:

```bash
# Disable telemetry
sudo -u www-data php occ config:app:set formvox telemetry_enabled --value=0

# Set default branding color
sudo -u www-data php occ config:app:set formvox default_accent_color --value=#0082c9
```

## File Storage

### Where Forms Are Stored

Forms are stored as `.fvform` files in users' Nextcloud file storage:
- Default location: User's root folder
- Users choose location when creating forms
- Forms follow standard Nextcloud file permissions

### Storage Considerations

Each form file contains:
- Form definition (questions, settings)
- All responses

File sizes:
- Empty form: ~2-5 KB
- Form with 100 responses: ~50-200 KB
- Form with 1000 responses: ~500 KB - 2 MB

### Quotas

Forms count toward user storage quotas. Consider:
- Forms with many responses grow over time
- File upload questions increase size significantly
- Monitor heavy users if quotas are limited

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

### Webhooks (Future)

Webhook support for external notifications is planned for future releases.

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
