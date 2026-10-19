# NBH Performance 1.0.0 — WordPress.org-style review report

**Review date:** 18 October 2026  
**Input:** `nbh-performance-1.0.0(1).zip`  
**Target:** WordPress.org Plugin Directory / GitHub  
**Reviewer perspective:** source inspection against [official plugin guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/) and [common issues](https://developer.wordpress.org/plugins/wordpress-org/common-issues/). This is independent technical review, **not** a WordPress.org Plugin Review Team decision.

## Disposition

**Submission candidate — requires real-site acceptance tests before actual submission.** Source-level review and the bundled isolated regression suite pass in this environment. A true WordPress.org Plugin Check scan and end-to-end tests against live WordPress were not possible in this session. Consequently acceptance and absence of further issues cannot be guaranteed. Treat the WordPress.org ZIP as a release candidate rather than a proven store-approved plugin.

## Reproducible checks actually performed

| Check | Result | Evidence / limitation |
| --- | --- | --- |
| Archive integrity | PASS | Original input archive passed `unzip -t` |
| PHP lint | PASS | 335 PHP files, PHP CLI 8.4.24; none failed |
| JavaScript parse | PASS | 14 JS files with Node 22.16.0; none failed |
| Lightweight regression | PASS | 13 assertions from `php tests/stability.php` (cache-key normalization, origin/variants, path containment, regex matching, no auto-update blocker by default, local feature licensing, plugin headers) |
| Plugin metadata/readme | PASS — static | 1.0.0 / `Stable tag: 1.0.0`, slug/text domain, WordPress 6.4+, PHP 7.4+, Tested up to 7.1; five tags |
| Vendor licenses | PASS — documentary | GPLv2-or-later plugin, MIT third-party PHP notices/licenses, local Outfit OFL text |
| Bundled source | PASS — inspection | Human-readable PHP, JS, CSS, scoped PHP dependencies; no npm runtime required |
| WordPress Plugin Check | NOT RUN | Requires a WordPress installation with the official Plugin Check plugin |
| PHP 7.4 runtime compatibility | NOT RUN | PHP 8.4 is available locally; CI matrix includes 7.4, 8.2 and 8.4 but GitHub CI has not executed |
| WordPress runtime/DB/cron | NOT RUN | No live WordPress / MySQL / WP-CLI test site available |
| WooCommerce / multisite / checkout | NOT RUN | Requires live storefront and network testing |
| GitHub remote upload | NOT DONE | No GitHub repository write operation was exposed in this session; source package is ready for upload |

## Findings and applied corrections

### High — WordPress updater interference — **fixed for fresh installs**

`Modules/Licensing/UpdateRollbackManager.php`: The updater hook originally returned a `WP_Error` on a failed/missing rollback ZIP, potentially halting otherwise-valid WordPress updates. It now wraps snapshots in `snapshot_before_update_without_blocking()`: automatic snapshots are disabled unless explicitly opted in, and a snapshot error is recorded without blocking the official upgrader. The standalone manual rollback routine remains separately available. Automatic post-update rollback now defaults off, without overriding existing administrator choices. Updated default paths in `Modules/Core/Lifecycle.php`, `Modules/Jobs/PostUpdateValidationJobHandler.php`, `Modules/Compatibility/CompatibilityTestMatrix.php`, and `Modules/Diagnostics/OperationsHealthMonitor.php`.

### High — active cache runtime could survive uninstall — **fixed**

`uninstall.php`: The original default policy returned without cleaning active drop-in code. The changed uninstall always detaches only ownership-verified page-cache/object-cache drop-ins, clears registered cron tasks for the current site, removes the owned object-cache credential config when applicable, and removes the marker-scoped `.htaccess` block. It continues to retain database/user data unless explicit purge policy is configured. Verify the behavior on single-site **and multisite** installations before publication.

### Medium — automatic root `.htaccess` edits on new installs — **fixed default**

`Modules/Core/Lifecycle.php`, `Modules/ServerOptimization/ServerRuleManager.php`, `Modules/Hub/AdvancedSettingsManager.php`: Apache/LiteSpeed root rules were on by default. This is now opt-in for new sites. Existing administrator-configured choices remain untouched. Server snippets and advanced settings remain available. Readme updated to match.

### Medium — broken developer test command — **fixed**

`composer.json` referenced nonexistent `tests/stability.php`, and the original README listed missing release scripts. Added a real zero-dependency PHP regression test, documented exact working commands, and added GitHub Actions for PHP 7.4/8.2/8.4 and JavaScript syntax checks.

### Reviewer-sensitive areas inspected, not fully proven

- **Permissions and public endpoints:** sampled controller registration, `permission_callback` usage, signed RUM and Priority Elements token endpoints, support-bundle download token/expiry checks, and administrator-only update actions. Several routes use `__return_true` intentionally with their own token/HMAC validation. Full penetration testing remains required.
- **Page cache and checkout:** reviewed origin-isolated `advanced-cache.php`, cache key generation and bypass architecture. Complex permutations including WooCommerce HPOS, logged-in users, currencies and language cookies must be validated dynamically.
- **External services:** readme discloses PageSpeed Insights, CDN providers, YouTube, local RUM. Verify optional service calls in network traces to establish that no provider traffic occurs without enabling/requesting it.
- **Filesystem ownership:** inspected atomic cache/drop-in writes, cache ownership markers, data retention, object-cache config and destructive cleanup. Real-site permission and symlink tests remain necessary.
- **Asset/source licensing:** vendor PHP libraries are namespaced and their license texts are bundled. No evidence of packed/obfuscated shipped JavaScript was found in the inspected assets. A dependency/license-provenance audit is still advised before first publication.

## Required acceptance checklist before submitting

1. Install **only** `nbh-performance-wporg-1.0.0.zip` on a disposable clean WordPress 7.1.3 site (and separately WordPress 6.4 and PHP 7.4).
2. Run official **Plugin Check** with `WPORG` and `PLUGIN_REPO` categories and resolve its results. Do not submit merely because PHP lint passed.
3. Test activation/deactivation/uninstall with data-retain and purge choices, root `.htaccess` and both cache drop-ins, writable/nonwritable directories and competing cache plugins.
4. Test public cache HIT/MISS, logged-in bypass, cart/checkout/account/cart-cookies, POST/query rules, language/currency variants, multisite fail-closed behavior, and CDN conflicts.
5. Test enabled/disabled page optimizations, image conversions and restoration, PHP critical/used CSS fallback, enqueue compatibility, scheduled jobs, and browser JS console errors.
6. Test all privileged REST routes with unauthenticated, subscriber, editor and administrator identities; test public token endpoint rate limits and expired signatures.
7. Check external network traffic with every optional service **disabled** and then individually opted in; confirm disclosures and prevent key/token leakage.
8. Re-run release regression, compare ZIP contents/manifest to the GitHub source, then submit to <https://wordpress.org/plugins/developers/add/>. GitHub publishing is separate from WordPress.org publication.

## Suggested GitHub commit message

`Prepare NBH Performance 1.0.0 for WordPress.org review: safer defaults, uninstall cleanup, CI and regression tests`

## Interpretation

The changes reduce credible **review and operational risks**, but a WordPress.org reviewer may flag additional issues that only become visible in Plugin Check, WordPress runtime behavior, or manual review. Do not describe this build as officially approved until the directory approves it.
