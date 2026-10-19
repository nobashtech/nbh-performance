# NBH Performance

**A lightweight, open-source WordPress performance plugin that helps websites load faster, run more efficiently, and deliver a better user experience.**

Built and maintained by [Nobash](https://nobash.com/) · [Official website](https://nbhperformance.com/) · [GPL-2.0-or-later](LICENSE)

NBH Performance brings practical performance tools together in one WordPress dashboard. Manage caching, optimize assets and images, clean up your database, and understand your site's performance—without juggling multiple interfaces or signing up for a vendor account.

All functionality included in this repository is free to use. Optional third-party infrastructure, such as a CDN, may have separate charges.

## What you can do

| Area | Capabilities |
| --- | --- |
| **Page caching** | Cache eligible public pages, refresh cached content, and manage preloading. |
| **CSS & JavaScript** | Minify supported local assets; selectively defer or delay scripts with exclusions. |
| **Image & media optimization** | Generate supported WebP/AVIF alternatives, preserve originals, lazy-load eligible media, and use click-to-load YouTube embeds. |
| **Database maintenance** | Preview supported cleanup tasks and manage scheduled maintenance. |
| **Performance insights** | Run local homepage checks, review optional locally stored visitor measurements, or request Google PageSpeed Insights tests. |
| **CDN & edge tools** | Configure supported CDN providers, URL rewriting, and cache-purge integrations. |
| **Advanced controls** | Manage background jobs, configuration snapshots, recovery options, and more granular optimization rules. |

Features depend on your environment and configuration. No plugin can promise a specific PageSpeed score or work identically on every host.

## Designed for control

- **Start safely.** Fresh installations begin in Safe Mode so you can review settings before activating optimizations.
- **Choose what matters.** Enable features individually and add exclusions when a script or page needs special treatment.
- **Keep private pages private.** Page-caching rules account for logged-in sessions and common shopping-cart cookies.
- **Stay in control of changes.** Diagnostics, configuration snapshots, and recovery tools help with troubleshooting. They are **not** a substitute for a full site backup.
- **No license key required.** No mandatory Nobash account or bundled licensing SDK.

## Getting started

1. **Back up your website.** Test on staging first if possible.
2. Install the **plugin release ZIP** using **Plugins → Add New → Upload Plugin**, or install the plugin when it becomes available in the WordPress.org directory.
3. Activate **NBH Performance** and go to **Settings → NBH Performance**.
4. Follow guided setup, inspect diagnostics, and review the settings while Safe Mode is active.
5. Enable only the optimizations your website needs. Leave Safe Mode when ready to test their behavior.
6. Check public pages, mobile layouts, contact forms, search, login, cart, checkout, and other interactive features. Clear the cache when necessary.

**Important:** Use a designated plugin release archive for installation—not GitHub's automatically generated *Source code* archive, which also contains development files.

## Compatibility and requirements

| Requirement | Details |
| --- | --- |
| WordPress | 6.4 or later |
| PHP | 7.4 or later |
| Licensing | GPL v2 or later |
| Multisite | Page caching is disabled on multisite |
| Object caching | Redis/Memcached integrations require a compatible service and PHP extension |
| Image conversion | Depends on available server image-processing libraries |
| Background work | Some operations require working WordPress cron and site loopbacks |

**Fresh-install defaults:** Safe Mode is enabled; automatic update rollback and modifications to root `.htaccess` rules are disabled. Those advanced operations are opt-in.

The included PHP CSS-generation mode is deliberately conservative. It needs PHP DOM, supported local stylesheets, same-site loopbacks, and cron; it is **not** a browser-rendered, viewport-measured Critical CSS extractor.

See the [WordPress.org readme](readme.txt) for detailed feature descriptions, limitations, troubleshooting guidance, and external-service/privacy disclosures.

## Performance reporting and privacy

NBH Performance distinguishes between different kinds of measurement:

- **Local homepage check:** an on-demand request to your own site's homepage, reporting response and page-asset information. This is not a Lighthouse score.
- **Real User Monitoring (optional):** sampled visitor measurements stored on your own WordPress site when enabled and subject to configured retention and consent settings.
- **Google PageSpeed Insights (optional):** external lab requests when explicitly requested or configured, subject to Google's service terms and availability.

Local optimization does not require sending telemetry to Nobash. Optional integrations, including CDN provider APIs, PageSpeed Insights, and external media embeds, can contact third parties. Read [External Services and Privacy](readme.txt) before enabling them.

## Developers

This repository contains readable production PHP, JavaScript, and CSS sources alongside development tests and automation. Bundled namespaced PHP dependencies are included; no Composer install is required to run the plugin.

### Local checks

```bash
composer test
php tests/uninstall-detach.php
find . -name '*.php' -print0 | xargs -0 -n1 php -l
find assets/js -name '*.js' -print0 | xargs -0 -n1 node --check
```

These are source-level checks, **not** a replacement for live WordPress integration tests or the official [Plugin Check](https://wordpress.org/plugins/plugin-check/). Before distributing a release, test activation, deactivation, uninstall, caching exclusions, cron, permissions, WooCommerce flows, and representative hosting environments.

### Repository structure

| Path | Purpose |
| --- | --- |
| `nbh-performance.php` | Plugin entry point |
| `includes/`, `Modules/` | Core functionality and feature modules |
| `assets/` | Dashboard and frontend assets |
| `dropins/`, `advanced-cache.php` | Cache and integration code |
| `languages/` | Localization resources |
| `vendor/` | Bundled dependencies and third-party notices |
| `tests/` | Lightweight regression checks |
| `.github/workflows/` | Automated source checks |
| `readme.txt` | WordPress.org plugin-directory metadata and detailed documentation |

For dependency attribution, see [third-party notices](vendor/THIRD-PARTY-NOTICES.md).

## Contributing

Bug reports, compatibility findings, documentation improvements, and focused code contributions are welcome.

1. Search [existing issues](https://github.com/nobashtech/nbh-performance/issues) before opening a new one.
2. Include reproducible steps, expected and actual behavior, WordPress/PHP versions, and relevant settings—with personal information removed.
3. Keep proposed changes focused and test them against a clean WordPress installation.
4. Submit a pull request that explains the change and how it was tested.

Please do **not** publish API keys, credentials, private diagnostics, or user information in an issue.

## Security

If you believe you have discovered a security vulnerability, **do not post exploit details in a public issue**. Follow the guidance in [SECURITY.md](SECURITY.md) for private reporting.

## License

NBH Performance is distributed under the [GNU General Public License, version 2 or later](LICENSE). Third-party components retain their respective copyrights and license notices.

---

Developed by **[Nobash](https://nobash.com/)** · [Project website](https://nbhperformance.com/) · [Report an issue](https://github.com/nobashtech/nbh-performance/issues)
