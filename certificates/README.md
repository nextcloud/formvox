# FormVox Certificates

This folder contains the signing certificates for the Nextcloud App Store.

**IMPORTANT:** This folder should NOT be pushed to GitHub (public). Only push to private Gitea repository.

## Files

- `formvox.key` - Private key (NEVER share this!)
- `formvox.csr` - Certificate Signing Request (submit to Nextcloud)
- `formvox.crt` - Certificate from Nextcloud (after approval)

## Certificate Request

Submit the CSR to: https://github.com/nextcloud/app-certificate-requests/issues/new

## Signing a Release

```bash
openssl dgst -sha512 -sign certificates/formvox.key formvox-X.Y.Z.tar.gz | openssl base64 -A
```
