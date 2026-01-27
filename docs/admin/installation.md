# Installation Guide

This guide covers installing and updating FormVox on your Nextcloud server.

## Requirements

### Nextcloud Version
- **Minimum:** Nextcloud 28
- **Maximum:** Nextcloud 32
- **Recommended:** Latest stable release

### PHP Version
- **Minimum:** PHP 8.1
- **Recommended:** PHP 8.2 or higher

### Server Requirements
- No additional database requirements (file-based storage)
- Standard Nextcloud server setup
- Adequate disk space for form files

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

FormVox automatically registers the `.fvform` file type during installation. If files don't show the correct icon:

1. Run the repair step:
   ```bash
   sudo -u www-data php occ maintenance:repair
   ```

2. Clear the file cache:
   ```bash
   sudo -u www-data php occ files:scan --all
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

Form files (`.fvform`) remain in users' file storage.

### Complete Removal
To remove everything including form files:

1. Delete all `.fvform` files from user storage
2. Follow the steps above to uninstall the app

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

2. Clear caches:
   ```bash
   sudo -u www-data php occ maintenance:mimetype:update-db
   sudo -u www-data php occ maintenance:mimetype:update-js
   ```

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
