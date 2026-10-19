# NBH Performance

Free and open-source WordPress performance tools by [Nobash](https://nobash.com/). Website: [nbhperformance.com](https://nbhperformance.com/). Licensed under GPLv2 or later. All features bundled in this repository are free and do not require a license key or a vendor account.

**Requirements:** WordPress 6.4 or later; PHP 7.4 or later. The plugin header and WordPress.org readme currently target release 1.0.0. No compatibility claim is made for untested hosts.

## Install and configure

Use the WordPress.org release archive, not GitHub's automatic **Source code** ZIP (which includes development files). In WordPress, go to **Plugins → Add New → Upload Plugin**; activate and open **Settings → NBH Performance**. Keep Safe Mode enabled while reviewing settings, then enable features gradually and test public pages, login, carts, checkout, forms, and other interactive features on staging.

Fresh installations deliberately do **not** modify the root `.htaccess` automatically and do **not** enable post-update automatic rollback. These are opt-in advanced settings. A failed optional snapshot will not block WordPress plugin updates. The normal WordPress.org updater handles future published releases.

Important limits: Page cache is not supported on multisite. Image conversion requires supported host libraries. The conservative PHP CSS generator requires PHP DOM, readable same-origin stylesheets, and functioning WordPress cron; it does not use viewport screenshots and must not be interpreted as a measured above-the-fold CSS extractor. See [readme.txt](readme.txt) for feature limitations and all optional external service / privacy disclosures.

## Development

The PHP, JS and CSS here are human-readable production sources. Bundled namespaced PHP dependencies and their MIT license notices are in [`vendor/`](vendor/THIRD-PARTY-NOTICES.md). Outfit is licensed under SIL OFL 1.1 and bundled locally. No dependency installation is required to run the plugin.

Run local checks:

```bash
composer test  # Or: php tests/stability.php
php tests/uninstall-detach.php
find . -name '*.php' -print0 | xargs -0 -n1 php -l
find assets/js -name '*.js' -print0 | xargs -0 -n1 node --check
```

GitHub Actions runs source lint and the lightweight tests. These checks are not a substitute for [WordPress Plugin Check](https://wordpress.org/plugins/plugin-check/) or real WordPress integration tests. Prior to a WordPress.org submission, install the release ZIP on a **clean** WordPress 7.1 environment and run Plugin Check with `WPORG` and `PLUGIN_REPO` checks, plus activation/deactivation/uninstall, multisite, WooCommerce checkout, page cache, CSS, image-processing, cron, and permission tests. WordPress.org review decisions are made by its Plugin Review Team, not by automation.

## Repository and release layout

This repository is the **development source**. The separately produced WordPress.org ZIP has only a single `nbh-performance/` plugin root and production code/assets. The development ZIP adds `.github`, tests and project documents. The plugin may be submitted at <https://wordpress.org/plugins/developers/add/> after independent real-site checks; GitHub publication is a separate action.

## Contributions and security

Open reproducible, minimal issue reports and propose narrowly scoped changes. Do not include passwords, user information, private diagnostics bundles or API keys in issues. For vulnerabilities, contact the maintainer privately rather than posting proof-of-concept exploits publicly.
