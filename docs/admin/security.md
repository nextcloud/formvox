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

FormVox works with Nextcloud's server-side encryption:
- Files encrypted at rest
- Transparent to users
- Standard file encryption settings apply

### End-to-End Encryption

FormVox is compatible with E2E encryption:
- Forms can be stored in E2E folders
- Content encrypted on client
- Server cannot read form data

**Note:** Public links don't work with E2E encrypted forms.

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
