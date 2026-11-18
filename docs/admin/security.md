# Security Guide

This guide covers security features and best practices for FormVox administrators.

## Access Control

### Nextcloud Permissions

FormVox respects Nextcloud's permission system:

| Permission | Can View | Can Edit | Can Share | Can Delete |
|------------|----------|----------|-----------|------------|
| Read | Yes | No | No | No |
| Edit | Yes | Yes | No | No |
| Share | Yes | Yes | Yes | No |
| Delete | Yes | Yes | Yes | Yes |

### File-Based Security

Since forms are files:
- Standard Nextcloud file permissions apply
- Encryption (including E2E) is supported
- Sharing follows file sharing rules

### Form-Level Permissions

Within FormVox:
- **View results** - Who can see responses
- **Edit form** - Who can modify questions
- **Manage sharing** - Who can change access

## Public Form Security

### Password Protection

Add a password to public forms:

1. Open form settings → Sharing
2. Enable **Password protection**
3. Set a strong password
4. Share password separately from the link

**Best practices:**
- Use unique passwords per form
- Change passwords periodically
- Don't include password in the same message as the link

### Link passwords required by Nextcloud

Since 1.6.0, FormVox follows Nextcloud's rules for passwords on public links, the
same rules as for a public share in Files:

- **Enforce password protection** under **Administration settings → Sharing**: when it
  is on, a new response link gets a generated password, shown once to the person who
  creates the link, and the password cannot be removed. Links created before the
  setting was switched on keep working as they are, without a password, until someone
  sets one.
- **Password policy:** a password someone sets must pass Nextcloud's password policy
  for shares (the Password policy app, `password_policy`), and a generated one comes
  from that app when it is enabled.
- **Always ask for a password** is not used by FormVox: without enforcement, the
  password on a form's link stays optional.

This also applies to forms that ask respondents to log in, or that restrict the link
to users or groups. Respondents then log in and enter the password as well.

#### On by default with a configuration preset

On Nextcloud 32 and later, the presets School, University, Hosting company, Small
organization, Big organization and Large organization (`config_preset` 4 to 9 in
`config.php`) switch **Enforce password protection** on, unless an administrator has
set it explicitly.
**Always ask for a password** stays off, so the settings page shows **Enforce password
protection** as on but greyed out.

To switch enforcement off:

1. Go to **Administration settings → Sharing**
2. Switch on **Always ask for a password**; the switch below it can only be changed then
3. Switch off **Enforce password protection**
4. Switch **Always ask for a password** off again if you do not want it for Files

This changes the rule for every public link on your Nextcloud, not only for FormVox.

#### Excluding groups

To keep enforcement for most users but not for the people who manage forms, add their
groups under **Exclude groups from password requirements**. FormVox looks at the groups
of the person who creates or changes the link, not at the respondents. The field
appears only when `config.php` contains:

```php
'sharing.allow_disabled_password_enforcement_groups' => true,
```

and, like the switch above it, can only be changed while **Always ask for a password**
is on.

### Expiration Dates

Set automatic expiration:

1. Open form settings → Sharing
2. Enable **Expiration date**
3. Choose date and time

After expiration:
- Link returns an error
- Existing responses are preserved
- Reactivate by removing or extending the date

### Access Restrictions

Limit who can access public forms:

1. Enable **Restrict access**
2. Select allowed users/groups
3. Users must log in to submit

Use cases:
- Internal surveys with public-style URL
- Department-specific forms

## Rate Limiting

Protect against spam and abuse.

### Submission Rate Limits

For public forms:
- Maximum submissions per minute
- Per-IP tracking
- Automatic blocking of rapid submissions

### Configuration

Rate limits are configured per-form:
1. Open form settings → Security
2. Set **Max submissions per minute**
3. Default: 10 per minute

### Blocked Requests

When rate limited:
- User sees a friendly error message
- Must wait before retrying
- Legitimate users rarely affected

## Duplicate Prevention

### Methods

Prevent multiple submissions from the same person:

| Method | How It Works | Limitations |
|--------|--------------|-------------|
| Browser fingerprint | Tracks browser/device | Can be bypassed |
| Nextcloud user | One per logged-in user | Requires login |
| Cookie-based | Stores submission cookie | Cleared by user |

### Configuration

1. Open form settings → Submission
2. Enable **Prevent duplicates**
3. Choose method

## Data Protection

### Response Data

Form responses contain potentially sensitive data:

**Recommendations:**
- Collect only necessary information
- Inform respondents about data usage
- Set appropriate access permissions
- Delete old responses regularly

### GDPR Compliance

For EU compliance:

1. **Privacy notice** - Add description explaining data usage
2. **Consent** - Include a consent checkbox if required
3. **Data export** - Users can request their data
4. **Deletion** - Delete responses when no longer needed

### Data Retention

Implement a retention policy:
1. Export and archive old responses
2. Delete responses from active forms
3. Document your retention period

## Encryption

### Server-Side Encryption

FormVox's `.fvform` file works with Nextcloud's server-side encryption: it is encrypted at rest like any other file,
transparently to users, under the standard file encryption settings.

Since 1.6.0, a form's responses and secrets (link token, link password hash, API keys, webhook secrets) live in the
database, and respondents' uploads, branding images, the ODT template and kept PDFs live in FormVox's app data,
outside any user's files. **Neither is covered by Nextcloud's server-side file encryption.** The `.fvform` file itself
still only holds the form definition, so there is little left in it for file encryption to protect.

The secrets kept in the database are sealed with Nextcloud's own encryption key (`FormSecrets`, using `ICrypto`)
before they are written, so reading the database directly does not expose them in plain text; that protection does not
extend to responses or to the files in app data.

### End-to-End Encryption

FormVox is compatible with E2E encryption:
- Forms can be stored in E2E folders
- Content encrypted on client
- Server cannot read form data

**Note:** Public links don't work with E2E encrypted forms. Since the form's responses and secrets no longer travel
with the file, this applies to the form definition only.

## Nextcloud's Own File Lock

FormVox keeps one lock of its own per form (a database row or cache key, with a two-minute hold) so only one writer
touches a form at a time; `occ formvox:unlock <fileId>` frees that lock by hand when its holder was killed.

Separately, whenever FormVox writes to the `.fvform` file itself, Nextcloud takes its own file lock, in Redis or in
the database. If PHP is killed in the middle of that file write, Nextcloud's lock can stay in place until it expires,
which depends on the `filelocking.ttl` setting in `config.php` (default: 3600 seconds, i.e. one hour). Until then, the
editor reports the form as busy, and `occ formvox:unlock` does not help: it only frees FormVox's own lock, not
Nextcloud's file lock.

If this happens, an administrator can either wait for Nextcloud's lock to expire on its own, or lower
`filelocking.ttl` in `config.php` (it applies to file locks across all of Nextcloud, not just FormVox) and restart
PHP-FPM/the web server for the new value to take effect.

## Audit Logging

### What's Logged

FormVox logs security-relevant events:
- Form creation/deletion
- Permission changes
- Public link creation
- Failed authentication attempts

### Viewing Logs

Check Nextcloud's log file:
```bash
tail -f /path/to/nextcloud/data/nextcloud.log | grep formvox
```

## Security Best Practices

### For Administrators

1. **Keep updated** - Install FormVox updates promptly
2. **Review permissions** - Audit form access regularly
3. **Monitor usage** - Check for unusual activity
4. **Enable HTTPS** - Always use encrypted connections
5. **Strong passwords** - Enforce password policies

### For Form Creators

1. **Minimal data** - Only collect what you need
2. **Appropriate sharing** - Don't over-share forms
3. **Password protection** - Use for sensitive forms
4. **Expiration dates** - Set for temporary forms
5. **Review responses** - Delete when no longer needed

### For Public Forms

1. **Rate limiting** - Always enable
2. **Expiration** - Set reasonable timeframes
3. **Passwords** - Use for sensitive content
4. **CAPTCHA** - Consider for high-traffic forms (if available)

## Incident Response

### Suspected Data Breach

If you suspect unauthorized access:

1. **Disable sharing** - Remove public links immediately
2. **Review logs** - Check for suspicious activity
3. **Export data** - Save a copy for investigation
4. **Notify** - Inform affected users if required
5. **Reset** - Change passwords, review permissions

### Spam/Abuse

If a form is being abused:

1. **Enable rate limiting** - Reduce submissions per minute
2. **Add password** - Require authentication
3. **Restrict access** - Limit to known users
4. **Delete spam** - Remove unwanted responses
5. **Disable temporarily** - If needed

## Next Steps

- Review [Configuration](configuration.md) options
- Check [API security](../architecture/api-reference.md)
- Read [Architecture overview](../architecture/overview.md)
