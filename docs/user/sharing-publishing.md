# Sharing and Publishing

FormVox offers flexible options for sharing your forms with others.

## Sharing Methods

### Share with Nextcloud Users

Share forms with specific Nextcloud users or groups:

1. Open your form
2. Click the **Share** button in the toolbar
3. Search for users or groups
4. Set permissions:
   - **View** - Can view the form and submit responses
   - **Edit** - Can modify the form structure
   - **Manage** - Full access including sharing

### Public Links

Create a link that anyone can access:

1. Open your form
2. Click **Share** → **Public link**
3. Configure options (see below)
4. Copy the link

## Public Link Options

### Password Protection

Require a password to access the form:

1. Enable **Password protection**
2. Enter a password
3. Share the password separately with intended respondents

### Expiration Date

Set a deadline for form access:

1. Enable **Expiration date**
2. Choose a date and time
3. After expiration, the link returns an error

### User/Group Restrictions

Limit who can access a public form:

1. Enable **Restrict access**
2. Select Nextcloud users or groups
3. Only these users can submit responses (they must log in)

This is useful for:
- Internal surveys that need a public-style interface
- Collecting responses from specific departments

## Embedding Forms

Embed your form in external websites, SharePoint, intranets, or other platforms.

### Using the Embed Code Generator

1. Open your form
2. Click **Share** in the toolbar
3. Click the **Embed** tab
4. Configure options:
   - **Width** - Fixed pixels or responsive (100%)
   - **Height** - Frame height in pixels
5. Copy the generated embed code
6. Paste into your website's HTML

### Manual iframe Embed

```html
<iframe
  src="https://your-nextcloud.com/apps/formvox/public/FORM_HASH"
  width="100%"
  height="600"
  frameborder="0">
</iframe>
```

### Responsive Embed

For mobile-friendly embedding:

```html
<div style="position: relative; padding-bottom: 75%; height: 0; overflow: hidden;">
  <iframe
    src="https://your-nextcloud.com/apps/formvox/public/FORM_HASH"
    style="position: absolute; top: 0; left: 0; width: 100%; height: 100%;"
    frameborder="0">
  </iframe>
</div>
```

### Domain Restrictions

Administrators can restrict which domains are allowed to embed forms. If embedding doesn't work, contact your Nextcloud administrator to allow your domain.

See [Admin Configuration](../admin/configuration.md) for details.

## File-Based Sharing

Since FormVox stores forms as files, you can also share via:

### Nextcloud File Sharing

1. Go to the Files app
2. Find your `.fvform` file
3. Share it like any other file

### Copy/Move Forms

Copy a form to share a duplicate:

1. In Files app, right-click the `.fvform` file
2. Select **Copy** or **Move**
3. Choose the destination folder

This creates an independent copy with its own responses.

## Collaboration

### Multiple Editors

When sharing with edit permissions:
- Multiple users can edit the form
- Changes are saved automatically
- Conflicts are handled by Nextcloud's file locking

### View-Only Sharing

For forms with sensitive questions:
1. Share with **View** permission only
2. Users can see the form structure but not edit
3. They can still submit responses

## Best Practices

### For Internal Surveys
- Share with Nextcloud groups
- Use user restrictions on public links
- Enable "one submission per user"

### For External Surveys
- Use public links
- Add password protection
- Set expiration dates
- Enable rate limiting

### For Sensitive Data
- Share only with specific users
- Use password protection
- Review [Security settings](../admin/security.md)

## Next Steps

- View and analyze [Results](results-analysis.md)
- [Export your data](exporting-data.md)
- Configure [Security settings](../admin/security.md)
