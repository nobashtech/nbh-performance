(function () {
	'use strict';
	window.NBHPerformanceHubCatalog = {
		navItems: [
		{ id: 'overview', label: 'Dashboard', icon: 'home', group: 'Overview' },
		{ id: 'insights', label: 'Performance Insights', icon: 'chart', group: 'Overview' },
		{ id: 'caching', label: 'Page Cache', icon: 'layers', group: 'Performance' },
		{ id: 'object_cache', label: 'Object Cache', icon: 'database', group: 'Performance' },
		{ id: 'optimization', label: 'CSS & JavaScript', icon: 'sliders', group: 'Performance' },
		{ id: 'media', label: 'Media Delivery', icon: 'image', group: 'Performance' },
		{ id: 'images', label: 'Image Conversion', icon: 'convert', group: 'Performance' },
		{ id: 'preload', label: 'Preload & Navigation', icon: 'rocket', group: 'Performance' },
		{ id: 'cdn', label: 'CDN & Edge Cache', icon: 'globe', group: 'Delivery' },
		{ id: 'enterprise', label: 'Agency', icon: 'building', group: 'Agency' },
		{ id: 'database', label: 'Database Cleanup', icon: 'database', group: 'Maintenance' },
		{ id: 'script_manager', label: 'Script Manager', icon: 'sliders', group: 'Maintenance' },
		{ id: 'tools', label: 'Tools', icon: 'wrench', group: 'Maintenance' },
		{ id: 'license', label: 'About', icon: 'info', group: 'Plugin' },
		{ id: 'help', label: 'Help', icon: 'info', group: 'Support' }
	],
		sectionMeta: {
		overview: 'A simple view of what is working and what to do next.',
		insights: 'Track Core Web Vitals and spot performance changes.',
		caching: 'Serve saved HTML for anonymous visitors — features, live status, purge/repair/warm, and advanced TTL or exclusions.',
		object_cache: 'Connect Redis or Memcached, install a safe drop-in, flush this site’s namespace, and monitor latency — without fighting hosting-owned caches.',
		optimization: 'Minify, defer, delay, Critical CSS — with flush, queue and homepage generation. Engine tools stay under Advanced.',
		media: 'Local lazy loading, responsive images, YouTube facades and consent-aware chat delay.',
		images: 'Generate WebP and AVIF safely — optimize next batch, finish the library, or run durable background jobs.',
		preload: 'Warm sitemap pages, Instant Page, Speculation Rules and Priority Elements — with run controls and learning cleanup.',
		cdn: 'Connect Cloudflare, Bunny, KeyCDN or a custom webhook — with auto zone discovery and stable purge.',
		enterprise: 'Manage Agency profiles and advanced delivery controls.',
		database: 'Preview safe clutter, verify a private SQL backup, then clean in batches — posts, users, orders and media stay untouched.',
		script_manager: 'Unload, defer, delay or preload scripts and styles with URL, device, role and Testing Mode safety controls.',
		tools: 'Smart Optimize, health checks, settings backup and recovery tools.',
		license: 'Meet NBH Performance, built by Nobash.',
		help: 'Built-in guides and clear answers for setting up and troubleshooting NBH Performance.'
	},
		fieldGuidance: {
		nbh_performance_priority_preload_urls: { placeholder: 'Add a critical image, font, or stylesheet URL…', hint: 'Use complete same-origin URLs. Add only resources needed immediately above the fold.', examples: ['https://example.com/wp-content/uploads/hero.webp', 'https://example.com/wp-content/themes/theme/assets/app.css'] },
		nbh_performance_font_preload_urls: { placeholder: 'Add a local WOFF2 font URL…', hint: 'Use local, same-origin WOFF2 files only. Preload just the fonts visible in the first screen.', examples: ['https://example.com/wp-content/uploads/fonts/inter-regular.woff2', '/wp-content/themes/theme/fonts/inter-bold.woff2'] },
		nbh_performance_preload_excluded_urls: { placeholder: 'Add a path or URL fragment to keep out of preload…', hint: 'Use paths or distinctive URL fragments. Matching URLs will never enter the preload queue.', examples: ['/checkout/', '/my-account/', '?add-to-cart=', '/private-area/'] },
		nbh_performance_speculation_exclusions: { placeholder: 'Add a path that must not be prefetched or prerendered…', hint: 'Exclude transactional, account, login, cart, and other state-changing destinations.', examples: ['/cart/', '/checkout/', '/wp-login.php', '?wc-ajax='] },
		nbh_performance_css_exclusions: { placeholder: 'Add a stylesheet handle, filename, or URL fragment…', hint: 'Use one identifiable pattern for each stylesheet that NBH Performance should leave untouched.', examples: ['elementor-frontend', '/plugins/slider-revolution/', 'checkout.css'] },
		nbh_performance_js_exclusions: { placeholder: 'Add a script handle, filename, or URL fragment…', hint: 'Use patterns that uniquely identify scripts which must not be minified or transformed.', examples: ['jquery-core', 'payment-gateway.js', '/plugins/contact-form-7/'] },
		nbh_performance_delay_js_exclusions: { placeholder: 'Add a script keyword or URL fragment that must run immediately…', hint: 'Use this for checkout, consent, menus, forms, or interactive scripts that cannot wait for user interaction.', examples: ['wc-checkout', 'stripe', 'recaptcha', 'cookie-consent'] },
		nbh_performance_unused_css_safelist: { placeholder: 'Add a CSS selector or class pattern that must always be preserved…', hint: 'Safelist dynamic classes added by JavaScript, popups, menus, sliders, forms, or page builders.', examples: ['.is-active', '.elementor-popup-modal', '[aria-expanded="true"]', '.swiper-slide-active'] },
		nbh_performance_media_excluded_urls: { placeholder: 'Add an image or media URL fragment to skip optimization…', hint: 'Exclude logos, animated media, protected files, or assets already optimized elsewhere.', examples: ['/uploads/logo.svg', '/protected-media/', '.gif'] },
		nbh_performance_lazyload_excluded_classes: { placeholder: 'Add an image class that must load immediately…', hint: 'Enter class names without a leading dot. Useful for hero, logo, slider, or above-the-fold media.', examples: ['site-logo', 'hero-image', 'skip-lazy'] },
		nbh_performance_lcp_image_exclusions: { placeholder: 'Add an image URL or class NBH Performance must not promote as LCP…', hint: 'Use this when automatic LCP detection selects the wrong image.', examples: ['decorative-shape', '/uploads/background-pattern.webp'] },
		nbh_performance_image_conversion_exclusions: { placeholder: 'Add an image URL or file-path fragment to skip…', hint: 'Matching JPEG or PNG files will remain untouched and will not receive WebP or AVIF variants.', examples: ['/uploads/private/', 'do-not-convert.jpg', '/product-mockups/'] },
		nbh_performance_cdn_excluded_urls: { placeholder: 'Add a path or file fragment that must stay on the origin…', hint: 'Exclude signed URLs, dynamic files, private assets, or resources incompatible with your CDN.', examples: ['/wp-admin/', '/private-downloads/', 'signed-url='] },
		nbh_performance_edge_excluded_paths: { placeholder: 'Add a dynamic path that edge cache must bypass…', hint: 'Always exclude personalized, transactional, authenticated, and session-sensitive routes.', examples: ['/cart/', '/checkout/', '/my-account/', '/wp-json/'] },
		nbh_performance_font_excluded_families: { placeholder: 'Add a font family NBH Performance should not localize…', hint: 'Enter the family name exactly as declared in CSS.', examples: ['Font Awesome 6 Free', 'Material Icons'] },
		nbh_performance_google_font_exclusions: { placeholder: 'Add a Google Fonts family or stylesheet fragment to leave remote…', hint: 'Use family names or unique URL fragments for fonts managed by another service.', examples: ['family=Roboto', 'family=Material+Symbols'] },
		nbh_performance_lab_test_urls: { placeholder: 'Add a same-origin HTTPS page to monitor…', hint: 'Use complete same-origin URLs. Leave empty to monitor the homepage only.', examples: ['https://example.com/', 'https://example.com/shop/', 'https://example.com/pricing/'] },
		nbh_performance_validation_urls: { placeholder: 'Add a page to smoke-test after setting changes…', hint: 'Use same-origin URLs that represent key public templates.', examples: ['https://example.com/', 'https://example.com/contact/', 'https://example.com/product/sample/'] },
		nbh_performance_diagnostic_exclusions: { placeholder: 'Add a check or integration id to suppress…', hint: 'Use stable identifiers from support reports that should not raise noise.', examples: ['object-cache-dropin', 'custom-cdn-header'] },
		nbh_performance_cache_excluded_urls: { placeholder: 'Add a page path or URL fragment that must never be cached…', hint: 'Exclude personalized, dynamic, form, account, and transactional pages.', examples: ['/checkout/', '/my-account/', '/members/', '?preview=true'] },
		nbh_performance_cache_excluded_cookies: { placeholder: 'Add a cookie name or stable prefix that bypasses cache…', hint: 'Visitors carrying a matching cookie will receive uncached content.', examples: ['woocommerce_items_in_cart', 'wordpress_logged_in_', 'member_session'] },
		nbh_performance_ignored_query_args: { placeholder: 'Add a harmless query parameter to ignore in cache keys…', hint: 'Only add tracking parameters that do not change page content.', examples: ['utm_source', 'utm_campaign', 'gclid', 'fbclid'] }
	},
		featureCopy: {
		nbh_performance_edge_html_cache_enabled: 'Add safe shared-cache headers and cache tags for provider-neutral full-page edge caching.',
		nbh_performance_ai_gateway_write_enabled: 'Allow approved AI operations to make guarded changes after an administrator authorizes each write.',
		nbh_performance_agency_reports_enabled: 'Enable multisite portfolio summaries and branded client performance reports.',
		nbh_performance_cache_enabled: 'Serve cached HTML before WordPress fully boots. Fail-closed on multisite.',
		nbh_performance_mobile_cache_enabled: 'Keep a separate cache only when mobile markup differs.',
		nbh_performance_sitemap_preload_enabled: 'Warm priority URLs discovered from XML sitemaps.',
		nbh_performance_instant_page: 'Prefetch eligible same-origin links when visitor intent is detected.',
		nbh_performance_speculation_rules_enabled: 'Use browser Speculation Rules for safe prefetch or prerender.',
		nbh_performance_resource_priority_enabled: 'Learn actual LCP images, above-the-fold assets, fonts and origins from real browser visits.',
		nbh_performance_automatic_preconnect_enabled: 'Preconnect only the highest-confidence external origins learned for this page.',
		nbh_performance_css_minify_enabled: 'Reduce local stylesheet size with failure isolation.',
		nbh_performance_js_minify_enabled: 'Minify local scripts and preserve the original on failure.',
		nbh_performance_defer_js_enabled: 'Move eligible scripts out of the critical render path.',
		nbh_performance_delay_js_enabled: 'Delay selected third-party scripts until interaction.',
		nbh_performance_script_manager_enabled: 'Control individual scripts and stylesheets with dependency-aware unload, delay, defer and page conditions.',
		nbh_performance_critical_css_enabled: 'Inline conservative PHP CSS per page. Includes retained stylesheet rules; the first screen is not measured.',
		nbh_performance_unused_css_enabled: 'Analyze a supported local stylesheet in the background and retain dynamic or uncertain rules.',
		nbh_performance_visual_regression_guard_enabled: 'Require visual evidence from custom browser generators. Built-in PHP mode uses static safeguards and cannot compare screenshots.',
		nbh_performance_image_conversion_enabled: 'Generate WebP and AVIF while preserving originals.',
		nbh_performance_nextgen_image_serving_enabled: 'Serve the best available format per browser.',
		nbh_performance_lazy_load_enabled: 'Prioritize LCP media and defer off-screen assets.',
		nbh_performance_responsive_images_enabled: 'Serve sized sources with safer attributes for modern browsers.',
		nbh_performance_youtube_facade_enabled: 'Replace heavy embeds with a click-to-load facade.',
		nbh_performance_youtube_autoload_after_consent: 'Load the real YouTube player after media consent is granted.',
		nbh_performance_chat_facade_enabled: 'Delay chat widgets until interaction or consent allows them.',
		nbh_performance_consent_mode_enabled: 'Hold consent-gated third parties until categories are granted.',
		nbh_performance_cdn_rewrite_enabled: 'Rewrite eligible static assets to the configured CDN.',
		nbh_performance_edge_auto_purge_enabled: 'Synchronize origin and edge cache invalidation.',
		nbh_performance_edge_cache_headers_enabled: 'Send shared-cache Cache-Control headers for eligible public HTML.',
		nbh_performance_resource_hints_enabled: 'Add preconnect and dns-prefetch hints for the CDN hostname.',
		nbh_performance_database_cleanup_enabled: 'Schedule conservative, non-destructive maintenance.',
		nbh_performance_font_preload_enabled: 'Detect and preload critical same-origin fonts observed by the browser.',
		nbh_performance_rum_enabled: 'Collect privacy-safe real-user Core Web Vitals.',
		nbh_performance_heartbeat_control_enabled: 'Reduce Heartbeat frequency outside protected screens.'
	},
		oneClickExclusions: {
		css: {
			title: 'CSS one-click exclusions',
			description: 'Protect builder, theme and plugin styles that should remain untouched by CSS optimization.',
			target: 'nbh_performance_css_exclusions',
			groups: [
				{ id: 'builders', label: 'Page Builders', icon: 'layers', items: [
					{ label: 'Elementor', value: 'elementor' }, { label: 'Beaver Builder', value: 'fl-builder' }, { label: 'Divi Builder', value: 'et-builder' }, { label: 'WPBakery', value: 'js_composer' }, { label: 'Bricks', value: 'bricks' }
				] },
				{ id: 'commerce', label: 'E-commerce', icon: 'cart', items: [
					{ label: 'WooCommerce', value: 'woocommerce' }, { label: 'WooCommerce Blocks', value: 'wc-blocks' }, { label: 'SureCart', value: 'surecart' }
				] },
				{ id: 'forms', label: 'Forms', icon: 'filter', items: [
					{ label: 'Gravity Forms', value: 'gform' }, { label: 'WPForms', value: 'wpforms' }, { label: 'Contact Form 7', value: 'contact-form-7' }, { label: 'Fluent Forms', value: 'fluentform' }, { label: 'Formidable Forms', value: 'formidable' }
				] },
				{ id: 'visual', label: 'Sliders & Visual Effects', icon: 'image', items: [
					{ label: 'Slider Revolution', value: 'revslider' }, { label: 'Smart Slider', value: 'smartslider' }, { label: 'Swiper', value: 'swiper' }, { label: 'Slick Slider', value: 'slick' }, { label: 'Animate.css', value: 'animate.css' }
				] }
			]
		},
		delay: {
			title: 'JavaScript Delay one-click exclusions',
			description: 'Keep critical third-party and WordPress scripts available immediately when interaction delay would affect functionality.',
			target: 'nbh_performance_delay_js_exclusions',
			groups: [
				{ id: 'analytics', label: 'Analytics & Trackers', icon: 'chart', items: [
					{ label: 'Google Analytics', value: 'google-analytics.com' }, { label: 'Google Tag Manager', value: 'googletagmanager.com' }, { label: 'Google Optimize', value: 'googleoptimize.com' }, { label: 'HubSpot', value: 'hs-scripts.com' }, { label: 'Microsoft Clarity', value: 'clarity.ms' }
				] },
				{ id: 'ads', label: 'Ad Networks', icon: 'globe', items: [
					{ label: 'Google Ads', value: 'googleadservices.com' }, { label: 'Google AdSense', value: 'pagead2.googlesyndication.com' }, { label: 'Meta Pixel', value: 'connect.facebook.net' }, { label: 'TikTok Pixel', value: 'analytics.tiktok.com' }, { label: 'LinkedIn Insight', value: 'snap.licdn.com' }
				] },
				{ id: 'payments', label: 'Payment Processors', icon: 'lock', items: [
					{ label: 'Stripe', value: 'js.stripe.com' }, { label: 'PayPal', value: 'paypal.com/sdk/js' }, { label: 'Square', value: 'squarecdn.com' }, { label: 'Mollie', value: 'mollie.com' }, { label: 'Razorpay', value: 'checkout.razorpay.com' }
				] },
				{ id: 'support', label: 'Chat & Support', icon: 'info', items: [
					{ label: 'Crisp', value: 'client.crisp.chat' }, { label: 'Intercom', value: 'widget.intercom.io' }, { label: 'Tawk.to', value: 'embed.tawk.to' }, { label: 'Zendesk', value: 'static.zdassets.com' }, { label: 'Drift', value: 'js.driftt.com' }
				] },
				{ id: 'wp', label: 'WordPress & E-commerce', icon: 'database', items: [
					{ label: 'WooCommerce Cart Fragments', value: 'wc-cart-fragments' }, { label: 'WooCommerce Checkout', value: 'woocommerce-checkout' }, { label: 'Elementor Frontend', value: 'elementor-frontend' }, { label: 'Gravity Forms', value: 'gform' }, { label: 'WPForms', value: 'wpforms' }
				] }
			]
		}
	}
	};
}());
