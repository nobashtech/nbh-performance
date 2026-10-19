=== NBH Performance ===
Contributors: nobash
Tags: cache, performance, optimization, core web vitals, woocommerce
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Speed up WordPress with page caching, image optimization and simple performance controls. Free and open source, with Safe Mode and recovery tools.

== Description ==

Improve your WordPress site's speed and performance with reliable page caching, image optimization, and practical optimization tools. Built with simplicity, stability, and control in mind.

NBH Performance by [Nobash](https://nobash.com/) brings caching, image optimization and everyday performance controls into one dashboard. Start with guided setup, review the recommended settings and turn on the tools your site needs. Safe Mode, diagnostics and recovery controls help you test changes with more confidence.

All features included in the plugin are free and open source. There are no Pro locks, license keys, paid activation steps or required vendor accounts. Optional hosting infrastructure and third-party services may have their own costs.

= Start simple =

* Follow guided setup to review your site's environment.
* Begin in Safe Mode while you explore the settings.
* Enable a small set of optimizations, then check your important pages.
* Clear saved pages after changing content or design.
* Use diagnostics and configuration recovery tools when troubleshooting.

= Tools for a faster site =

* Page caching: save ready-to-serve copies of eligible public pages and refresh them with preload queues.
* CSS and JavaScript controls: minify local assets, defer scripts and delay selected third-party scripts, with exclusions for files your site needs immediately.
* Image optimization: create WebP or AVIF alternatives when supported by your server, while preserving original images.
* Media delivery: lazy-load eligible media and replace YouTube embeds with lightweight, click-to-load facades.
* Database maintenance: preview supported cleanup operations and manage scheduled maintenance.
* Performance insights: run a local homepage check, see visitor-monitoring status, review local visitor measurements when enabled, and request optional Google PageSpeed Insights tests.
* CDN and edge integrations: connect your own supported provider for URL rewriting and cache purges.
* Advanced controls: use per-file script rules, configuration snapshots, background jobs and Agency tools as needed.

= Extra capabilities when your server supports them =

Redis or Memcached object caching requires a compatible service and PHP extension. Image conversion depends on the formats and processors available on your server.

Conservative PHP Critical CSS and Used CSS generation is included. Jobs run in the background without an open admin tab, Node.js or an external generator. PHP DOM, readable local stylesheets, same-site loopbacks and working WordPress cron are required. Original styles remain available when generation is unsupported or fails.

Page caching is supported on single-site WordPress installations. It remains disabled on multisite; do not assume every feature has the same multisite support.

= Built for careful changes =

Fresh installations start in Safe Mode. Automatic update rollback and automatic server configuration edits are disabled by default. Enable either explicitly in Advanced Settings only after reviewing the implications. Private requests such as logged-in sessions and shopping-cart cookies bypass page caching. Configuration snapshots and recovery controls help you manage optimization changes; they do not replace a complete website backup.

Test changes on staging whenever possible, especially on stores, membership sites or sites with complex interactive features. No performance plugin can guarantee a particular speed score or compatibility with every theme, host and plugin combination.

Visit [nbhperformance.com](https://nbhperformance.com/) for the official product website.

== Installation ==

1. Back up your site. Use a staging copy for your first setup when available.
2. In WordPress, open Plugins > Add New > Upload Plugin and select the release ZIP. You can also upload the nbh-performance folder to /wp-content/plugins/.
3. Activate NBH Performance, then open Settings > NBH Performance.
4. Follow guided setup and review diagnostics while Safe Mode is enabled.
5. Resolve any reported conflict with another cache plugin or an existing advanced-cache.php or object-cache.php file before enabling the related feature.
6. Choose the features your site needs. Leave Safe Mode when you are ready to test them, then check your homepage, menus, forms and any cart or checkout pages.

== Frequently Asked Questions ==

= Is NBH Performance completely free? =
Yes. All features bundled with this plugin are available without payment, a license key or an account. The code is licensed under GPLv2 or later. Services you choose to connect, such as a CDN, may charge separately.

= Do I need technical experience? =
You can begin with guided setup and the main feature controls. Advanced options are available for users who need them. Enable changes gradually and check your site after each step.

= What does Safe Mode do? =
Safe Mode pauses the plugin's optimization behavior so you can review settings and troubleshoot. Leaving Safe Mode allows enabled features to operate; check your site after doing so.

= Should I enable every feature? =
No. Use only the features that suit your site and hosting. Combining multiple tools that do the same optimization can cause conflicts. Review diagnostics before enabling overlapping features.

= Can I use it with WooCommerce? =
The plugin includes WooCommerce-aware controls and private-request cache bypasses. Test product variations, customer accounts, cart and checkout on your own store before using optimization changes in production.

= Does object caching work on every host? =
No. Redis or Memcached object caching requires a supported service and PHP extension. Ask your host which option is available. Page caching does not require Redis or Memcached.

= Are image originals preserved? =
The image conversion tools preserve original files. WebP, AVIF and other processing capabilities depend on your server. Keep a separate backup of your uploads.

= How does PHP background CSS generation work? =
The bundled PHP engine analyzes anonymous page HTML and one supported local stylesheet per page. Used CSS preserves dynamic and uncertain selectors. Conservative Critical CSS contains the retained stylesheet, not a browser-measured first-screen subset. PHP mode does not perform screenshot comparisons or enable unverified asynchronous stylesheet loading. Unsupported sources remain unchanged. WordPress cron processes the queue; low traffic or blocked loopbacks can delay results.

= Does it contact a licensing server or load dashboard fonts remotely? =
No. There is no licensing SDK or required account connection. The dashboard uses a bundled local copy of Outfit with its SIL Open Font License included.

= How do Performance Insights work on a local site? =
Run Local Check makes one anonymous request to your configured homepage and reports response duration, HTML size, script/stylesheet counts, page-cache header and visitor-beacon presence. It uses your own hosting and does not need a vendor account, API key, Node.js or a browser engine. It does not measure rendered-page speed or generate a Lighthouse score.

Visitor monitoring is optional and stores sampled browser measurements on your WordPress site. Enable it in Performance Insights and turn Safe Mode off when ready. Logged-in users are excluded. To test, use a private window, load a public page, interact with it, then switch tabs or leave the page and refresh Insights. Only the configured percentage of visits is sampled. INP needs an interaction and browser support. Small samples are preliminary; this is not Chrome field data or Search Console data. Missing measurements are shown as unavailable, never as a perfect health score.

Google Lighthouse tests are optional and need a publicly accessible site. Google cannot visit .local or private development sites. Provider quotas and API-key requirements may apply. Background lab jobs continue through WordPress cron; site traffic or a hosting cron trigger is needed. Local homepage checks run only when requested and do not call Google.

= Does it send data to external services? =
Local caching and optimization do not require a vendor account. Optional integrations make requests when configured, enabled or explicitly requested. Read the disclosures below before connecting a service or enabling visitor measurements.

= How do I get updates? =
After WordPress.org approval and publication, updates use the standard WordPress updater. Before publication, install a newer release ZIP manually. No private updater is bundled.

= Where can I find the official website? =
Visit [nbhperformance.com](https://nbhperformance.com/). NBH Performance is developed by [Nobash](https://nobash.com/).

== External Services and Privacy ==

* Google PageSpeed Insights: When you request a lab test or enable scheduled tests, the tested public URL, mobile/desktop strategy, site-identifying User-Agent and optional API key are sent to Google. Terms: https://developers.google.com/terms ; Privacy: https://policies.google.com/privacy . Scheduled testing is disabled by default.
* Cloudflare, Bunny CDN and KeyCDN: When you configure these integrations and request connection checks, zone discovery or cache purges, the plugin sends authentication credentials and the relevant zone identifiers, hostnames or purge URLs to your selected provider. Cloudflare terms/privacy: https://www.cloudflare.com/terms/ and https://www.cloudflare.com/privacypolicy/ ; Bunny terms/privacy: https://bunny.net/tos/ and https://bunny.net/privacy/ ; KeyCDN terms/privacy: https://www.keycdn.com/terms and https://www.keycdn.com/privacy . Custom CDN and edge webhooks send purge data to the URL you configure; consult that provider's policies.
* YouTube facade: When enabled, replaces existing embeds with a locally rendered button. Clicking it loads YouTube; optional autoloading requires external-media consent. Google terms/privacy: https://www.youtube.com/t/terms and https://policies.google.com/privacy .
* Real User Monitoring: Disabled by default. When enabled, browser measurements are sent to this WordPress site's REST endpoint and stored locally, subject to the configured retention and consent settings. Review your site's privacy notice before enabling visitor measurements.

The plugin also makes same-site HTTP requests for preloading, health checks and frontend validation. It does not send usage telemetry to Nobash or a licensing provider.

A conservative PHP background CSS generator is bundled. Custom local generators can still use the `nbh_performance_local_headless_generate` filter. A headless browser is not required by the built-in PHP engine. The plugin does not fetch its own PHP, JavaScript or dependencies from external code-delivery services. Script-delay and consent controls can still manage existing third-party scripts supplied by your site. Optional CDN/edge and PageSpeed integrations exchange API data when explicitly configured or requested; they are not software update or code delivery mechanisms.

== Source and Licenses ==

Human-readable PHP, JavaScript and CSS source is included in the plugin. No JavaScript build step is required. Scoped third-party PHP source and MIT license texts are included under vendor/nbh-performance/. See vendor/THIRD-PARTY-NOTICES.md for upstream sources and versions. Original plugin code is GPLv2 or later; dependency copyrights and license terms remain intact.

== Changelog ==

= 1.0.0 =
* Initial free and open-source release by Nobash.
* All bundled optimization and Agency features available without activation or payment.
* Removed Freemius, paid feature locks and required account connections.
* Removed remote analytics/font downloads, remote CSS generation and vendor callbacks; bundled the dashboard font locally.
* Improved cache replacement, generation locks, private-request bypasses and compressed cache validation.
* Added strict settings validation and predictable feature dependencies.
* Moved retired download-cache cleanup into bounded background batches.
* Improved frontend file writes, RUM database upgrades and optional image-processor exit handling.
* Added repeatable regression checks, source/license notices and external-service disclosures.
* Kept WordPress core updates non-blocking if an optional rollback snapshot fails.
* Turned off automatic update rollback and root server-rule modification by default for new installations.
* Tested locally with WordPress 7.1.3 and PHP 8.2.29.

== Upgrade Notice ==

= 1.0.0 =
Free and open-source release. Back up your site, review Safe Mode and test selected optimizations before enabling them on a live site.
