# FormVox App Store Release Checklist

Follow this checklist for every release to the Nextcloud App Store.

---

## 0. Certificate Verification (CRITICAL!)

**Before every release**, verify that your signing key matches the App Store certificate!

- [ ] Verify signing key matches App Store certificate:
  ```bash
  # Hash of local signing key
  openssl rsa -in formvox.key -pubout 2>/dev/null | openssl md5

  # Hash of App Store certificate (must be IDENTICAL!)
  curl -s "https://apps.nextcloud.com/api/v1/apps.json" | \
    python3 -c "import json,sys; [print(a['certificate']) for a in json.load(sys.stdin) if a['id']=='formvox']" | \
    openssl x509 -pubkey -noout 2>/dev/null | openssl md5
  ```
- [ ] Both MD5 hashes are **IDENTICAL**
- [ ] Check certificate serial number and validity

### Certificate Warnings:
- **NEVER request a new certificate unnecessarily** - this automatically revokes the old one!
- Only request a new certificate if the private key is compromised or lost
- Keep your `.key` file safe (backup in secure location, NOT in git!)
- After certificate change: download the new certificate and store with the key

---

## 1. Code Quality & Security

- [ ] Remove all `console.log()` and debug statements from JavaScript (`src/`)
- [ ] Remove all `error_log()` and debug code from PHP (`lib/`)
- [ ] Check for hardcoded credentials, API keys, or passwords
- [ ] Ensure `.gitignore` is up-to-date (keys, certificates, .env files)
- [ ] Verify that sensitive files are NOT in the repository
- [ ] Run `npm audit` and fix critical vulnerabilities
- [ ] Check for XSS, SQL injection, and other OWASP vulnerabilities
- [ ] Review all new code for security issues
- [ ] **Check tarball for sensitive data** (see Section 9.1)

---

## 2. Translations (l10n/)

- [ ] Check that all new strings are translated in all supported languages
- [ ] Validate JSON syntax in all translation files (`l10n/*.json`)
- [ ] Test the application in each language for missing or truncated text
- [ ] Verify that `.js` translation files are generated

---

## 3. Version Management

- [ ] Determine new version number (semantic versioning: MAJOR.MINOR.PATCH)
- [ ] Update version in `package.json`
- [ ] Update version in `appinfo/info.xml`
- [ ] Verify versions match in both files
- [ ] Update `CHANGELOG.md` with all changes for this release:
  - [ ] New features
  - [ ] Bug fixes
  - [ ] Breaking changes
  - [ ] Known issues

---

## 4. Build & Testing

- [ ] Remove `node_modules/` and run `npm ci` (clean install)
- [ ] Run `npm run build` without errors or warnings
- [ ] Test all core functionalities manually:
  - [ ] Create, edit, delete forms
  - [ ] All question types work correctly
  - [ ] Conditional logic (branching)
  - [ ] Quiz mode with scoring
  - [ ] Export to CSV, JSON, Excel
  - [ ] Public form links
  - [ ] Form branding
  - [ ] Response management
- [ ] Test on a clean Nextcloud installation
- [ ] Check browser console for JavaScript errors
- [ ] Test with different browsers (Chrome, Firefox, Safari, Edge)
- [ ] Test with GroupFolders extension installed

---

## 5. Nextcloud Compatibility

- [ ] Check min/max Nextcloud version in `appinfo/info.xml`
- [ ] Test on the minimum supported Nextcloud version (28)
- [ ] Test on the latest Nextcloud version (32)
- [ ] Verify PHP version requirement in `appinfo/info.xml` (8.1+)
- [ ] Check that all Nextcloud API calls still work
- [ ] Test with the latest version of @nextcloud/vue

---

## 6. Assets & Files

- [ ] Verify all required files are in the tarball:
  - [ ] `appinfo/` (info.xml, routes.php)
  - [ ] `lib/` (PHP backend)
  - [ ] `js/` (compiled JavaScript)
  - [ ] `css/` (stylesheets)
  - [ ] `img/` (icons)
  - [ ] `l10n/` (translations)
  - [ ] `templates/` (PHP templates)
  - [ ] `README.md`
  - [ ] `CHANGELOG.md`
  - [ ] `LICENSE`
- [ ] Verify that `src/` is NOT in the tarball (only compiled code)
- [ ] Check app icon (512x512) for App Store
- [ ] Update screenshots if UI has changed

---

## 7. Git & Repository

- [ ] All changes are committed
- [ ] No uncommitted changes present
- [ ] Branch is up-to-date with main/master
- [ ] Merge conflicts are resolved
- [ ] Check that sensitive files are not in git history

---

## 8. Release Package

- [ ] Create the tarball
- [ ] Verify tarball contents (`tar -tzf formvox-x.x.x.tar.gz`)
- [ ] **IMPORTANT:** Verify root folder is lowercase app name (NOT `formvox-x.x.x`)
  - App Store requires lowercase folder name without version number
- [ ] Push to remote(s)
- [ ] Upload tarball to GitHub release
- [ ] Generate signature with the correct key:
  ```bash
  openssl dgst -sha512 -sign formvox.key formvox-x.x.x.tar.gz | openssl base64 -A
  ```
- [ ] Upload to Nextcloud App Store:
  - [ ] Download URL (lowercase!)
  - [ ] Signature (regenerate after any tarball change!)
  - [ ] Release notes

### 8.1 Tarball Security Check (CRITICAL!)

**ALWAYS check** the tarball for sensitive data before uploading!

```bash
# Check for sensitive files
tar -tzf formvox-x.x.x.tar.gz | grep -iE '(internal|credential|\.key|\.env)'

# Search for IP addresses, usernames, passwords
tar -xzf formvox-x.x.x.tar.gz -O 2>/dev/null | \
  grep -iE '(password\s*=|api_key\s*=|secret\s*=|private.*key)' | head -20
```

**Do NOT include in tarball:**
- `src/` - Source code (only compiled js/)
- `node_modules/` - Dependencies
- `.git/` - Git history
- `*.key`, `*.crt`, `*.pem` - Certificates and keys
- Any files containing server IPs, credentials, or usernames

---

## 9. Post-Release Verification

- [ ] Install the app from the App Store on a test server
- [ ] Verify the app works correctly after installation
- [ ] Check that the version is displayed correctly
- [ ] Test the upgrade path from the previous version
- [ ] Sync all remotes
- [ ] Make a release announcement if major release
- [ ] Update external documentation if needed

---

## 10. Rollback Plan

- [ ] Backup of the previous release is available
- [ ] Rollback procedure is tested
- [ ] Test server available for emergencies

---

## Quick Commands

```bash
# Production build
npm run build

# Security audit
npm audit
```

---

## Quick Release Flow

### 1. Preparation
```bash
npm run build
```

### 2. Commit & Push
```bash
git add -A
git commit -m "Release vX.Y.Z - [Label]"
git push origin main
```

### 3. Create Tag
```bash
git tag -a vX.Y.Z -m "Release vX.Y.Z - [Label]"
git push origin vX.Y.Z
```

### 4. Create Tarball
**IMPORTANT:** Root folder must be lowercase app name without version number

```bash
TEMP_DIR=$(mktemp -d) && \
mkdir -p "$TEMP_DIR/formvox" && \
cp -r appinfo lib l10n templates css img js "$TEMP_DIR/formvox/" && \
cp CHANGELOG.md LICENSE README.md "$TEMP_DIR/formvox/" && \
cd "$TEMP_DIR" && \
tar -czf formvox-X.Y.Z.tar.gz formvox && \
mv formvox-X.Y.Z.tar.gz /path/to/project/ && \
rm -rf "$TEMP_DIR"
```

**Exclude:** src/, node_modules/, screenshots/, .git/, *.key

### 5. GitHub Release
```bash
gh release create vX.Y.Z --title "vX.Y.Z - [Label]" --notes "[notes]"
gh release upload vX.Y.Z formvox-X.Y.Z.tar.gz
```

### 6. Generate Signature (for App Store)
```bash
openssl dgst -sha512 -sign formvox.key formvox-X.Y.Z.tar.gz | openssl base64 -A
```

### 7. App Store Upload
- **URL:** GitHub release download URL (lowercase!)
- **Signature:** Output from step 6

**Note:** Regenerate signature after any tarball change!

---

## Notes

- **GitHub Repository:** https://github.com/nextcloud/formvox
- **Minimum Nextcloud version:** 28 (check `appinfo/info.xml`)
- **Maximum Nextcloud version:** 32 (check `appinfo/info.xml`)
- **PHP version:** 8.1+ (check `appinfo/info.xml`)
- **App Store:** https://apps.nextcloud.com

---

*Last updated: January 2026*
