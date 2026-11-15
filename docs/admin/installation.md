# Installation Guide

This guide covers installing and updating FormVox on your Nextcloud server.

## Requirements

### Nextcloud Version
- **Minimum:** Nextcloud 32
- **Maximum:** Nextcloud 35
- **Recommended:** Latest stable release

### PHP Version
- **Minimum:** PHP 8.2
- **Recommended:** PHP 8.2 or higher

### Server Requirements
- Standard Nextcloud server setup
- Adequate disk space for form files, uploads and kept PDFs
- MySQL, MariaDB or PostgreSQL recommended: FormVox stores responses and secrets in Nextcloud's database, and SQLite
  handles only one write at a time, so many respondents at once can get errors on it. Nextcloud's own setup checks
  (**Settings → Administration → Overview**) warn about this on SQLite and link to `occ db:convert-type` to move.

## Installation Methods

### From the App Store (Recommended)

1. Log in to Nextcloud as an administrator
2. Go to **Apps** (click your profile → Apps)
3. Search for "FormVox"
4. Click **Download and enable**

### Manual Installation

1. Download the latest release from [GitHub](https://github.com/nextcloud/formvox/releases)

2. Extract to your Nextcloud apps directory:
   ```bash
   cd /path/to/nextcloud/apps
   tar -xzf formvox-x.x.x.tar.gz
   ```

3. Set correct permissions:
   ```bash
   chown -R www-data:www-data formvox
   ```

4. Enable the app:
   ```bash
   sudo -u www-data php occ app:enable formvox
   ```

   Or enable via the web interface in **Apps** → **Disabled apps**.

## Post-Installation

### Verify Installation

1. Check the app is listed in **Apps** as enabled
2. Look for the FormVox icon in the navigation bar
3. Create a test form to verify functionality

### MIME Type Registration

FormVox automatically registers the `.fvform` file type during installation. A form shows the FormVox icon (a purple clipboard) through Nextcloud's previews, so it appears in the Files list and grid, search, activity and shares. With previews turned off (`enable_previews` set to `false`) forms show Nextcloud's plain file icon instead. In search results on Nextcloud 32 to 34, that grey icon shows faintly behind the form icon.

After an update that changes the icon, a background job replaces the stored thumbnails of existing forms once. Until the job has run, and for up to a day in a browser that still has the old image, a form can show the old one. If files don't show the correct icon:

1. Run the repair step:
   ```bash
   sudo -u www-data php occ maintenance:repair
   ```

2. Clear the file cache:
   ```bash
   sudo -u www-data php occ files:scan --all
   ```

3. If the old thumbnail stays, remove all stored previews; Nextcloud draws them again as they are needed:
   ```bash
   sudo -u www-data php occ preview:cleanup
   ```

## Updating FormVox

### Via App Store

1. Go to **Apps**
2. Check for updates
3. Click **Update** next to FormVox

### Manual Update

1. Download the new version
2. Disable the app:
   ```bash
   sudo -u www-data php occ app:disable formvox
   ```

3. Replace the app folder:
   ```bash
   rm -rf /path/to/nextcloud/apps/formvox
   tar -xzf formvox-x.x.x.tar.gz -C /path/to/nextcloud/apps/
   ```

4. Re-enable the app:
   ```bash
   sudo -u www-data php occ app:enable formvox
   ```

5. Run upgrades:
   ```bash
   sudo -u www-data php occ upgrade
   ```

## Uninstallation

### Keep Data
To uninstall but keep form files:

1. Disable the app:
   ```bash
   sudo -u www-data php occ app:disable formvox
   ```

2. Remove the app folder:
   ```bash
   rm -rf /path/to/nextcloud/apps/formvox
   ```

Form files (`.fvform`) remain in users' file storage; their responses, secrets and respondents' files remain in the
database and FormVox's app data, since both are app data that disabling the app does not touch.

### Complete Removal
To remove everything, including responses and uploaded files:

1. Delete all `.fvform` files from user storage
2. Follow the steps above to uninstall the app
3. Removing the app's data entirely (database tables, app data) is for Nextcloud's own app removal tooling, not
   covered here; disabling and removing the app folder as above is normally enough.

## Troubleshooting

### App Not Showing

If FormVox doesn't appear after installation:

1. Check PHP version meets requirements:
   ```bash
   php -v
   ```

2. Check Nextcloud version:
   ```bash
   sudo -u www-data php occ status
   ```

3. Check app is enabled:
   ```bash
   sudo -u www-data php occ app:list | grep formvox
   ```

4. Check logs for errors:
   ```bash
   tail -f /path/to/nextcloud/data/nextcloud.log
   ```

### File Type Not Recognized

If `.fvform` files don't open correctly:

1. Run maintenance repair:
   ```bash
   sudo -u www-data php occ maintenance:repair
   ```

2. Update the file types in the database:
   ```bash
   sudo -u www-data php occ maintenance:mimetype:update-db
   ```

   FormVox does not need `occ maintenance:mimetype:update-js`; that command rewrites a signed core file.

### Permission Issues

If you see permission errors:

1. Check app folder permissions:
   ```bash
   ls -la /path/to/nextcloud/apps/formvox
   ```

2. Fix ownership:
   ```bash
   chown -R www-data:www-data /path/to/nextcloud/apps/formvox
   ```

## Next Steps

- [Configure FormVox](configuration.md) settings
- Set up [Security](security.md) options
- Create your [first form](../getting-started.md)
