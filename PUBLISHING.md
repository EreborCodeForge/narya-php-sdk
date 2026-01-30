# Publishing to Packagist

Steps to publish and sync this package with your Packagist account.

## 1. Update repository URLs (if needed)

In `composer.json`, adjust `homepage` and `support` if your repo is not at `https://github.com/EreborCodeForge/narya-php-sdk`.

## 2. Initialize Git and push to GitHub/GitLab

```bash
cd /path/to/narya-php-sdk

# Initialize repo (if not already)
git init

# Add all files
git add .
git commit -m "Initial release: Narya PHP SDK"

# Create first version tag (required for Composer)
git tag -a v1.0.0 -m "Release 1.0.0"

# Add your remote (replace with your repo URL)
git remote add origin https://github.com/YOUR_USER_OR_ORG/narya-php-sdk.git

# Push branch and tags
git push -u origin main
git push origin v1.0.0
```

If your default branch is `master`:
```bash
git push -u origin master
git push origin v1.0.0
```

## 3. Submit to Packagist

1. Log in at [packagist.org](https://packagist.org).
2. Click **Submit** (or **Submit package**).
3. Enter your repository URL, e.g. `https://github.com/EreborCodeForge/narya-php-sdk`.
4. Click **Check** then **Submit**.

Packagist will import the package and show it on your account.

## 4. Keep it in sync

- **Auto-update (GitHub/GitLab):** In Packagist, open your package → **Settings** → enable **Auto-update** and connect your GitHub/GitLab so Packagist updates on push.
- **Manual:** After each release, open the package on Packagist and click **Update** to refresh the list of versions.

## 5. New releases

For each release:

```bash
git tag -a v1.1.0 -m "Release 1.1.0"
git push origin v1.1.0
```

Then trigger an update on Packagist (auto or manual).

## Install via Composer (after publish)

```bash
composer require narya/php-sdk
```
