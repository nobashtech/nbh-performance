<?php
/** Detailed dashboard settings and exclusion persistence. @package NBHPerformance */
namespace NBH\Performance\Modules\Hub;

final class AdvancedSettingsManager {
	public function schema() {
		return array(
			'caching'        => array(
				'label'       => __( 'Page Cache', 'nbh-performance' ),
				'description' => __( 'Control how long page copies stay fresh and which requests should never be cached.', 'nbh-performance' ),
				'fields'      => array(
					'nbh_performance_cache_ttl'            => $this->number( __( 'Cache lifespan', 'nbh-performance' ), __( 'Seconds before a cached page expires.', 'nbh-performance' ), 3600, 60, 604800, 'seconds' ),
					'nbh_performance_cache_stale_while_revalidate' => $this->number( __( 'Stale while revalidating', 'nbh-performance' ), __( 'Serve the previous safe cache while one request refreshes an expired page.', 'nbh-performance' ), 300, 0, 86400, 'seconds' ),
					'nbh_performance_cache_stale_if_error' => $this->number( __( 'Stale if origin fails', 'nbh-performance' ), __( 'Keep serving the previous safe page after a detected PHP or origin error.', 'nbh-performance' ), 86400, 0, 604800, 'seconds' ),
					'nbh_performance_cache_gzip_enabled'   => $this->boolean_default( __( 'Precompress cache with Gzip', 'nbh-performance' ), __( 'Create ready-to-serve Gzip copies of cached HTML.', 'nbh-performance' ), 1 ),
					'nbh_performance_cache_brotli_enabled' => $this->boolean_default( __( 'Precompress cache with Brotli', 'nbh-performance' ), __( 'Create Brotli copies when the server has a Brotli PHP encoder.', 'nbh-performance' ), 1 ),
					'nbh_performance_cache_verify_integrity' => $this->boolean( __( 'Verify cache integrity on every hit', 'nbh-performance' ), __( 'Hash-check HTML before serving. Use only for high-security sites because it adds disk and CPU work.', 'nbh-performance' ) ),
					'nbh_performance_cache_404_enabled'    => $this->boolean( __( 'Cache public 404 pages', 'nbh-performance' ), __( 'Cache safe public not-found pages. Leave disabled on sites where 404 output changes per visitor.', 'nbh-performance' ) ),
					'nbh_performance_cache_redirects_enabled' => $this->boolean( __( 'Cache permanent redirects', 'nbh-performance' ), __( 'Cache only same-origin 301 and 308 redirects after strict response validation.', 'nbh-performance' ) ),
					'nbh_performance_cache_min_free_disk_mb' => $this->number( __( 'Emergency disk reserve', 'nbh-performance' ), __( 'Stop creating new page-cache files when free disk space falls below this reserve.', 'nbh-performance' ), 128, 16, 10240, 'MB' ),
					'nbh_performance_debug_headers'        => $this->boolean( __( 'Show cache status headers', 'nbh-performance' ), __( 'Add technical cache status headers for troubleshooting. Leave off during normal use.', 'nbh-performance' ) ),
					'nbh_performance_cache_excluded_urls'  => $this->textarea( __( 'Excluded URLs', 'nbh-performance' ), __( 'One path or URL pattern per line. Wildcards are supported by the matching layer.', 'nbh-performance' ), 'exclusions' ),
					'nbh_performance_cache_excluded_cookies' => $this->textarea( __( 'Excluded cookies', 'nbh-performance' ), __( 'Never cache requests containing these cookie names.', 'nbh-performance' ), 'exclusions' ),
					'nbh_performance_cache_ignored_query_args' => $this->textarea( __( 'Ignored query parameters', 'nbh-performance' ), __( 'Tracking parameters that should not create a separate cache entry.', 'nbh-performance' ), 'exclusions' ),
					'nbh_performance_cache_allowed_query_args' => $this->textarea( __( 'Cacheable query parameters', 'nbh-performance' ), __( 'Public query parameters allowed to create separate cache entries. Leave empty unless the parameter changes safe public content.', 'nbh-performance' ), 'exclusions' ),
					'nbh_performance_cache_vary_cookies'   => $this->textarea( __( 'Public vary cookies', 'nbh-performance' ), __( 'Create bounded public cache variants for these non-sensitive cookies. Never add login, session, cart or consent identifiers.', 'nbh-performance' ), 'exclusions' ),
					'nbh_performance_language_cache_variant' => $this->boolean( __( 'Separate cache by language cookie', 'nbh-performance' ), __( 'Use a bounded language variant for WPML, Polylang, TranslatePress or Weglot cookies.', 'nbh-performance' ) ),
					'nbh_performance_language_variant_cookies' => $this->textarea( __( 'Additional language cookies', 'nbh-performance' ), __( 'Additional language cookie names, one per line.', 'nbh-performance' ), 'exclusions' ),
				),
			),
			'optimization'   => array(
				'label'       => __( 'Optimization', 'nbh-performance' ),
				'description' => __( 'Configure CSS, JavaScript, render-path and script-loading behavior.', 'nbh-performance' ),
				'fields'      => array(
					'nbh_performance_combine_mode'         => $this->select(
						__( 'Combine CSS and JavaScript files', 'nbh-performance' ),
						__( 'Automatic and HTTP/1.1 modes combine only when the protocol makes it beneficial. Disabled turns combining off.', 'nbh-performance' ),
						'auto',
						array(
							'off'   => __( 'Disabled', 'nbh-performance' ),
							'auto'  => __( 'Automatic', 'nbh-performance' ),
							'http1' => __( 'HTTP/1.1 only', 'nbh-performance' ),
						)
					),
					'nbh_performance_disable_emojis'       => $this->boolean( __( 'Disable emojis', 'nbh-performance' ), __( 'Remove WordPress emoji scripts, styles and DNS hint.', 'nbh-performance' ) ),
					'nbh_performance_disable_embeds'       => $this->boolean( __( 'Disable embeds', 'nbh-performance' ), __( 'Remove WordPress embed discovery and frontend embed script.', 'nbh-performance' ) ),
					'nbh_performance_disable_jquery_migrate' => $this->boolean( __( 'Disable jQuery Migrate', 'nbh-performance' ), __( 'Remove jQuery Migrate for logged-out frontend visitors.', 'nbh-performance' ) ),
					'nbh_performance_disable_dashicons_guests' => $this->boolean( __( 'Disable Dashicons for guests', 'nbh-performance' ), __( 'Do not load Dashicons for logged-out visitors.', 'nbh-performance' ) ),
					'nbh_performance_remove_query_strings' => $this->boolean( __( 'Remove asset version query strings', 'nbh-performance' ), __( 'Remove only the WordPress ver parameter from local assets.', 'nbh-performance' ) ),
					'nbh_performance_disable_xmlrpc'       => $this->boolean( __( 'Disable XML-RPC', 'nbh-performance' ), __( 'Disable XML-RPC and remove the pingback response header.', 'nbh-performance' ) ),
					'nbh_performance_remove_head_links'    => $this->boolean( __( 'Clean WordPress head', 'nbh-performance' ), __( 'Remove generator, RSD, WLW and shortlink metadata.', 'nbh-performance' ) ),
					'nbh_performance_delay_timeout'        => $this->number( __( 'Maximum script delay', 'nbh-performance' ), __( 'Maximum delay before scripts execute without interaction.', 'nbh-performance' ), 8000, 1000, 30000, 'ms' ),
					'nbh_performance_css_exclusions'       => $this->textarea( __( 'CSS exclusions', 'nbh-performance' ), __( 'Stylesheet handles, URL fragments or filenames to leave untouched.', 'nbh-performance' ), 'exclusions' ),
					'nbh_performance_js_exclusions'        => $this->textarea( __( 'JavaScript exclusions', 'nbh-performance' ), __( 'Script handles, URL fragments or filenames excluded from minify/defer.', 'nbh-performance' ), 'exclusions' ),
					'nbh_performance_delay_js_exclusions'  => $this->textarea( __( 'Delay exclusions', 'nbh-performance' ), __( 'Scripts that must execute immediately and must never be delayed.', 'nbh-performance' ), 'exclusions' ),
					'nbh_performance_unused_css_safelist'  => $this->textarea( __( 'CSS selectors to always keep', 'nbh-performance' ), __( 'Dynamic classes and selector fragments that must always be retained.', 'nbh-performance' ), 'exclusions' ),
					'nbh_performance_visual_difference_threshold' => $this->number( __( 'Maximum visual difference', 'nbh-performance' ), __( 'Reject generated Used CSS when browser screenshot differences exceed this percentage.', 'nbh-performance' ), 5, 1, 25, '%' ),
					'nbh_performance_visual_missing_nodes_limit' => $this->number( __( 'Allowed missing elements', 'nbh-performance' ), __( 'Maximum DOM elements allowed to disappear during the browser comparison. Zero is safest.', 'nbh-performance' ), 0, 0, 50, 'elements' ),
					'nbh_performance_visual_node_delta_limit' => $this->number( __( 'Visible-element change limit', 'nbh-performance' ), __( 'Maximum visible-element count change allowed before Used CSS is rejected.', 'nbh-performance' ), 10, 2, 100, 'elements' ),
					'nbh_performance_unused_css_max_reduction' => $this->number( __( 'Maximum Used CSS reduction', 'nbh-performance' ), __( 'Reject generated CSS that removes more than this percentage before browser validation.', 'nbh-performance' ), 80, 5, 95, '%' ),
					'nbh_performance_browser_css_ttl'      => $this->number( __( 'Generated CSS freshness', 'nbh-performance' ), __( 'Regenerate Critical CSS and Used CSS after this lifetime. Original stylesheets remain active until a fresh bundle passes validation.', 'nbh-performance' ), 2592000, 86400, 7776000, 'seconds' ),
				),
			),
			'media'          => array(
				'label'       => __( 'Media Delivery', 'nbh-performance' ),
				'description' => __( 'Tune lazy loading, video facades, consent and layout stability.', 'nbh-performance' ),
				'fields'      => array(
					'nbh_performance_add_missing_image_dimensions' => $this->boolean( __( 'Add missing image dimensions', 'nbh-performance' ), __( 'Add width and height to local attachment images to reduce layout shift.', 'nbh-performance' ) ),
					'nbh_performance_lazyload_eager_images' => $this->number( __( 'Images loaded immediately', 'nbh-performance' ), __( 'Keep this many early document images eager before lazy loading begins.', 'nbh-performance' ), 2, 0, 10, 'images' ),
					'nbh_performance_lazyload_background_images' => $this->boolean( __( 'Lazy-load inline background images', 'nbh-performance' ), __( 'Move eligible inline background images behind an IntersectionObserver loader.', 'nbh-performance' ) ),
					'nbh_performance_video_preload'        => $this->select(
						__( 'Video preload', 'nbh-performance' ),
						__( 'Control how much non-autoplay video data the browser loads before interaction.', 'nbh-performance' ),
						'metadata',
						array(
							'none'     => __( 'None', 'nbh-performance' ),
							'metadata' => __( 'Metadata only', 'nbh-performance' ),
							'auto'     => __( 'Browser default', 'nbh-performance' ),
						)
					),
					'nbh_performance_font_display'         => $this->select(
						__( 'Font display', 'nbh-performance' ),
						__( 'Applied to safely processed local font declarations.', 'nbh-performance' ),
						'swap',
						array(
							'swap'     => 'swap',
							'optional' => 'optional',
							'fallback' => 'fallback',
							'block'    => 'block',
						)
					),
					'nbh_performance_media_excluded_urls'  => $this->textarea( __( 'Excluded media URLs', 'nbh-performance' ), __( 'Iframe, video or media URL fragments that must not be optimized.', 'nbh-performance' ), 'exclusions' ),
					'nbh_performance_lazyload_excluded_classes' => $this->textarea( __( 'Lazy-load class exclusions', 'nbh-performance' ), __( 'CSS classes whose media must load normally.', 'nbh-performance' ), 'exclusions' ),
					'nbh_performance_lcp_image_exclusions' => $this->textarea( __( 'LCP detection exclusions', 'nbh-performance' ), __( 'Selectors or URL fragments that should never be promoted as the LCP image.', 'nbh-performance' ), 'exclusions' ),
					'nbh_performance_font_excluded_families' => $this->textarea( __( 'Excluded font families', 'nbh-performance' ), __( 'Font families that should not be localized or modified.', 'nbh-performance' ), 'exclusions' ),
				),
			),
			'images'         => array(
				'label'       => __( 'Image Conversion', 'nbh-performance' ),
				'description' => __( 'Convert JPEG and PNG uploads to WebP and AVIF while always preserving original files.', 'nbh-performance' ),
				'fields'      => array(
					'nbh_performance_image_optimization_profile' => $this->select(
						__( 'Optimization profile', 'nbh-performance' ),
						__( 'Choose a tested quality profile. Custom uses the format quality controls below.', 'nbh-performance' ),
						'balanced',
						array(
							'lossless'   => __( 'Lossless priority', 'nbh-performance' ),
							'balanced'   => __( 'Balanced', 'nbh-performance' ),
							'aggressive' => __( 'Aggressive', 'nbh-performance' ),
							'custom'     => __( 'Custom', 'nbh-performance' ),
						)
					),
					'nbh_performance_lcp_image_optimization_enabled' => $this->boolean_default( __( 'Optimize the likely LCP image', 'nbh-performance' ), __( 'Promote the first eligible content image with eager loading and high fetch priority.', 'nbh-performance' ), 1 ),
					'nbh_performance_image_minimum_saving_percent' => $this->number( __( 'Minimum source saving', 'nbh-performance' ), __( 'Keep a compressed source only when it saves at least this percentage and passes decode and dimension validation.', 'nbh-performance' ), 1, 1, 50, '%' ),
					'nbh_performance_generate_webp'       => $this->boolean_default( __( 'Generate WebP', 'nbh-performance' ), __( 'Create broadly supported WebP variants for eligible JPEG and PNG images.', 'nbh-performance' ), 1 ),
					'nbh_performance_generate_avif'       => $this->boolean_default( __( 'Generate AVIF', 'nbh-performance' ), __( 'Create smaller AVIF variants when the server processor supports AVIF.', 'nbh-performance' ), 1 ),
					'nbh_performance_convert_on_upload'   => $this->boolean_default( __( 'Convert new uploads automatically', 'nbh-performance' ), __( 'Generate enabled formats after new images are uploaded or edited.', 'nbh-performance' ), 1 ),
					'nbh_performance_webp_quality'        => $this->number( __( 'WebP quality', 'nbh-performance' ), __( 'Compression quality used for generated WebP images.', 'nbh-performance' ), 82, 40, 100, '%' ),
					'nbh_performance_avif_quality'        => $this->number( __( 'AVIF quality', 'nbh-performance' ), __( 'Compression quality used for generated AVIF images.', 'nbh-performance' ), 70, 30, 100, '%' ),
					'nbh_performance_image_batch_size'    => $this->number( __( 'Images processed per run', 'nbh-performance' ), __( 'Maximum images processed by each manual conversion request.', 'nbh-performance' ), 10, 1, 25, 'images' ),
					'nbh_performance_image_max_source_mb' => $this->number( __( 'Skip images larger than', 'nbh-performance' ), __( 'Skip source images larger than this limit to protect server memory and CPU.', 'nbh-performance' ), 25, 1, 100, 'MB' ),
					'nbh_performance_compress_original_images' => $this->boolean( __( 'Compress JPEG and PNG source files', 'nbh-performance' ), __( 'Create a verified backup, then replace eligible source sizes only when the compressed file is meaningfully smaller.', 'nbh-performance' ) ),
					'nbh_performance_jpeg_quality'        => $this->number( __( 'JPEG source quality', 'nbh-performance' ), __( 'Quality used only when source compression is enabled.', 'nbh-performance' ), 82, 40, 100, '%' ),
					'nbh_performance_png_quality'         => $this->number( __( 'PNG source quality', 'nbh-performance' ), __( 'Quality hint used by the active WordPress image editor for PNG source compression.', 'nbh-performance' ), 88, 40, 100, '%' ),
					'nbh_performance_image_conversion_exclusions' => $this->textarea( __( 'Conversion exclusions', 'nbh-performance' ), __( 'Image URL or file-path fragments that NBH Performance must never convert.', 'nbh-performance' ), 'exclusions' ),
				),
			),
			'preload'        => array(
				'label'       => __( 'Preload & Navigation', 'nbh-performance' ),
				'description' => __( 'Control sitemap warming, navigation prefetch, LCP priorities and font preloads.', 'nbh-performance' ),
				'fields'      => array(
					'nbh_performance_preload_batch_size'  => $this->number( __( 'Pages warmed per run', 'nbh-performance' ), __( 'URLs processed during each background preload run.', 'nbh-performance' ), 3, 1, 20, 'URLs' ),
					'nbh_performance_preload_max_urls'    => $this->number( __( 'Maximum pages in queue', 'nbh-performance' ), __( 'Maximum sitemap URLs retained in the bounded preload queue.', 'nbh-performance' ), 500, 10, 2000, 'URLs' ),
					'nbh_performance_preload_request_timeout' => $this->number( __( 'Page warm-up timeout', 'nbh-performance' ), __( 'Maximum time allowed for each preload request.', 'nbh-performance' ), 12, 3, 30, 'seconds' ),
					'nbh_performance_preload_retry_limit' => $this->number( __( 'Failed-attempt limit', 'nbh-performance' ), __( 'Maximum failed attempts before a URL is removed from the queue.', 'nbh-performance' ), 3, 1, 5, 'attempts' ),
					'nbh_performance_speculation_mode'    => $this->select(
						__( 'Next-page preparation mode', 'nbh-performance' ),
						__( 'Prefetch is safest. Prerender is faster but consumes more resources.', 'nbh-performance' ),
						'prefetch',
						array(
							'prefetch'  => __( 'Prefetch', 'nbh-performance' ),
							'prerender' => __( 'Prerender', 'nbh-performance' ),
						)
					),
					'nbh_performance_priority_elements_sample_rate' => $this->number( __( 'Priority Elements learning sample', 'nbh-performance' ), __( 'Percentage of logged-out visits used to learn actual LCP images, above-the-fold assets, fonts and origins.', 'nbh-performance' ), 20, 1, 100, '%' ),
					'nbh_performance_priority_elements_retention_days' => $this->number( __( 'Priority Elements retention', 'nbh-performance' ), __( 'Days to retain aggregated browser observations before automatic cleanup.', 'nbh-performance' ), 30, 1, 365, 'days' ),
					'nbh_performance_preconnect_limit'    => $this->number( __( 'Automatic preconnect origins', 'nbh-performance' ), __( 'Maximum external origins learned from real visits and preconnected on matching pages.', 'nbh-performance' ), 3, 0, 6, 'origins' ),
					'nbh_performance_lcp_preload_limit'   => $this->number( __( 'Priority images per page', 'nbh-performance' ), __( 'Maximum likely above-the-fold images promoted per page.', 'nbh-performance' ), 1, 1, 3, 'images' ),
					'nbh_performance_font_preload_limit'  => $this->number( __( 'Priority fonts per page', 'nbh-performance' ), __( 'Maximum number of local font files preloaded per page.', 'nbh-performance' ), 2, 0, 4, 'fonts' ),
					'nbh_performance_priority_preload_urls' => $this->textarea( __( 'Priority files to preload', 'nbh-performance' ), __( 'Up to four same-origin image, font or CSS URLs.', 'nbh-performance' ), 'urls' ),
					'nbh_performance_font_preload_urls'   => $this->textarea( __( 'Font preload URLs', 'nbh-performance' ), __( 'One same-origin WOFF2 font URL per line.', 'nbh-performance' ), 'urls' ),
					'nbh_performance_preload_excluded_urls' => $this->textarea( __( 'Preload URL exclusions', 'nbh-performance' ), __( 'Paths or URL fragments that must never enter the preload queue.', 'nbh-performance' ), 'exclusions' ),
					'nbh_performance_preload_mobile_variant' => $this->boolean( __( 'Warm mobile cache variant', 'nbh-performance' ), __( 'Queue a separate mobile warm-up request when mobile page caching is active.', 'nbh-performance' ) ),
					'nbh_performance_preload_slow_request_ms' => $this->number( __( 'Slow warm-up threshold', 'nbh-performance' ), __( 'Flag warm-up requests slower than this many milliseconds.', 'nbh-performance' ), 5000, 1000, 30000, 'milliseconds' ),
					'nbh_performance_speculation_exclusions' => $this->textarea( __( 'Speculation exclusions', 'nbh-performance' ), __( 'Paths that must never be prefetched or prerendered.', 'nbh-performance' ), 'exclusions' ),
				),
			),
			'cdn'            => array(
				'label'       => __( 'CDN & Edge Cache', 'nbh-performance' ),
				'description' => __( 'Configure asset delivery, edge caching and provider-specific exclusions.', 'nbh-performance' ),
				'fields'      => array(
					'nbh_performance_edge_provider'       => $this->select(
						__( 'CDN provider', 'nbh-performance' ),
						__( 'Select the edge provider used for purge integration.', 'nbh-performance' ),
						'custom',
						array(
							'custom'     => __( 'Custom CDN & Edge Cache', 'nbh-performance' ),
							'cloudflare' => 'Cloudflare',
							'bunnycdn'   => 'BunnyCDN',
							'keycdn'     => 'KeyCDN',
						)
					),
					'nbh_performance_cdn_base_url'        => $this->text( __( 'CDN hostname', 'nbh-performance' ), __( 'HTTPS CDN base URL, for example https://cdn.example.com.', 'nbh-performance' ), 'url' ),
					'nbh_performance_edge_cache_ttl'      => $this->number( __( 'Edge cache lifespan', 'nbh-performance' ), __( 'Shared-cache TTL for eligible public HTML.', 'nbh-performance' ), 3600, 60, 2592000, 'seconds' ),
					'nbh_performance_cdn_included_extensions' => $this->textarea( __( 'Included extensions', 'nbh-performance' ), __( 'One static file extension per line.', 'nbh-performance' ), 'exclusions' ),
					'nbh_performance_cdn_excluded_urls'   => $this->textarea( __( 'CDN exclusions', 'nbh-performance' ), __( 'Asset URL fragments that should remain on the origin.', 'nbh-performance' ), 'exclusions' ),
					'nbh_performance_edge_excluded_paths' => $this->textarea( __( 'Edge-cache exclusions', 'nbh-performance' ), __( 'Dynamic paths that must never be cached at the edge.', 'nbh-performance' ), 'exclusions' ),
				),
			),
			'database'       => array(
				'label'       => __( 'Database Cleanup', 'nbh-performance' ),
				'description' => __( 'Set conservative cleanup defaults. Destructive tasks still require dry-run and confirmation.', 'nbh-performance' ),
				'fields'      => array(
					'nbh_performance_database_schedule'   => $this->select(
						__( 'Cleanup schedule', 'nbh-performance' ),
						__( 'Automatic cleanup remains limited to non-destructive operations.', 'nbh-performance' ),
						'weekly',
						array(
							'off'     => __( 'Disabled', 'nbh-performance' ),
							'daily'   => __( 'Daily', 'nbh-performance' ),
							'weekly'  => __( 'Weekly', 'nbh-performance' ),
							'monthly' => __( 'Monthly', 'nbh-performance' ),
						)
					),
					'nbh_performance_revision_retention_days' => $this->number( __( 'Revision retention', 'nbh-performance' ), __( 'Only revisions older than this age appear in cleanup previews.', 'nbh-performance' ), 30, 1, 3650, 'days' ),
					'nbh_performance_database_batch_size' => $this->number( __( 'Cleanup batch size', 'nbh-performance' ), __( 'Maximum rows processed in one confirmed cleanup batch.', 'nbh-performance' ), 250, 10, 500, 'rows' ),
					'nbh_performance_database_excluded_tables' => $this->textarea( __( 'Protected tables', 'nbh-performance' ), __( 'Additional WordPress-prefixed tables to exclude from optimization.', 'nbh-performance' ), 'exclusions' ),
					'nbh_performance_action_scheduler_retention_days' => $this->number( __( 'Action Scheduler retention', 'nbh-performance' ), __( 'Completed, cancelled and failed background actions older than this age are eligible for cleanup.', 'nbh-performance' ), 30, 7, 365, 'days' ),
				),
			),
			'tools'          => array(
				'label'       => __( 'Tools', 'nbh-performance' ),
				'description' => __( 'Diagnostics, monitoring, debug behavior and operational safeguards.', 'nbh-performance' ),
				'fields'      => array(
					'nbh_performance_server_rules_enabled' => $this->boolean_default( __( 'Update safe server rules automatically', 'nbh-performance' ), __( 'Off by default. Enable only after reviewing your server configuration. For Apache/LiteSpeed, writes marker-scoped rules; for Nginx and IIS, generates manual snippets.', 'nbh-performance' ), 0 ),
					'nbh_performance_browser_cache_rules_enabled' => $this->boolean_default( __( 'Browser caching rules', 'nbh-performance' ), __( 'Apply long-lived immutable caching to versioned static assets while keeping HTML and data files revalidatable.', 'nbh-performance' ), 1 ),
					'nbh_performance_compression_rules_enabled' => $this->boolean_default( __( 'Compression compatibility rules', 'nbh-performance' ), __( 'Enable guarded Brotli or GZIP directives only when the matching server module is available.', 'nbh-performance' ), 1 ),
					'nbh_performance_static_cache_max_age' => $this->number( __( 'Static asset cache lifetime', 'nbh-performance' ), __( 'Browser cache lifetime for CSS, JavaScript, images and fonts.', 'nbh-performance' ), YEAR_IN_SECONDS, DAY_IN_SECONDS, YEAR_IN_SECONDS, 'seconds' ),
					'nbh_performance_rum_sample_rate'      => $this->number( __( 'Real-user monitoring sample', 'nbh-performance' ), __( 'Percentage of eligible visits used for privacy-safe field metrics.', 'nbh-performance' ), 10, 1, 100, '%' ),
					'nbh_performance_rum_retention_days'   => $this->number( __( 'Performance data retention', 'nbh-performance' ), __( 'Days to retain aggregated real-user performance data.', 'nbh-performance' ), 90, 7, 365, 'days' ),
					'nbh_performance_lab_testing_enabled'  => $this->boolean( __( 'Scheduled lab testing', 'nbh-performance' ), __( 'Queue PageSpeed Insights Lighthouse tests for selected pages and devices.', 'nbh-performance' ) ),
					'nbh_performance_lab_test_frequency'   => $this->select(
						__( 'Lab test schedule', 'nbh-performance' ),
						__( 'Run the selected page suite daily or weekly through the reliable job queue.', 'nbh-performance' ),
						'weekly',
						array(
							'daily'  => __( 'Daily', 'nbh-performance' ),
							'weekly' => __( 'Weekly', 'nbh-performance' ),
						)
					),
					'nbh_performance_lab_test_strategies'  => $this->select(
						__( 'Lab test devices', 'nbh-performance' ),
						__( 'Measure mobile, desktop, or both Lighthouse strategies.', 'nbh-performance' ),
						'both',
						array(
							'both'    => __( 'Mobile and desktop', 'nbh-performance' ),
							'mobile'  => __( 'Mobile only', 'nbh-performance' ),
							'desktop' => __( 'Desktop only', 'nbh-performance' ),
						)
					),
					'nbh_performance_lab_test_urls'        => $this->textarea( __( 'Monitored performance pages', 'nbh-performance' ), __( 'Up to ten same-origin HTTPS pages, one URL per line. The homepage is used when empty.', 'nbh-performance' ), 'urls' ),
					'nbh_performance_lab_retention_days'   => $this->number( __( 'Lab result retention', 'nbh-performance' ), __( 'Days to retain laboratory performance history. Explicit baselines are preserved.', 'nbh-performance' ), 180, 30, 365, 'days' ),
					'nbh_performance_regression_score_drop' => $this->number( __( 'Performance score regression threshold', 'nbh-performance' ), __( 'Alert when a Lighthouse performance score drops by at least this many points.', 'nbh-performance' ), 5, 3, 30, 'points' ),
					'nbh_performance_regression_lcp_percent' => $this->number( __( 'LCP regression threshold', 'nbh-performance' ), __( 'Alert when laboratory LCP increases by this percentage.', 'nbh-performance' ), 12, 5, 100, '%' ),
					'nbh_performance_regression_tbt_percent' => $this->number( __( 'TBT regression threshold', 'nbh-performance' ), __( 'Alert when Total Blocking Time increases by this percentage.', 'nbh-performance' ), 20, 5, 200, '%' ),
					'nbh_performance_regression_ttfb_percent' => $this->number( __( 'TTFB regression threshold', 'nbh-performance' ), __( 'Alert when server response time increases by this percentage.', 'nbh-performance' ), 15, 5, 200, '%' ),
					'nbh_performance_regression_email_alerts' => $this->boolean( __( 'Email performance regression alerts', 'nbh-performance' ), __( 'Send a concise alert to the WordPress administrator when a monitored page regresses.', 'nbh-performance' ) ),
					'nbh_performance_heartbeat_admin_interval' => $this->number( __( 'Admin background-check interval', 'nbh-performance' ), __( 'Heartbeat frequency outside protected editor screens.', 'nbh-performance' ), 60, 15, 120, 'seconds' ),
					'nbh_performance_performance_budgets_enabled' => $this->boolean( __( 'Warn about asset growth', 'nbh-performance' ), __( 'Track script and stylesheet counts to detect regressions.', 'nbh-performance' ) ),
					'nbh_performance_budget_script_count'  => $this->number( __( 'Maximum scripts before warning', 'nbh-performance' ), __( 'Maximum enqueued scripts before a regression is reported.', 'nbh-performance' ), 30, 5, 200, 'scripts' ),
					'nbh_performance_budget_style_count'   => $this->number( __( 'Maximum stylesheets before warning', 'nbh-performance' ), __( 'Maximum enqueued stylesheets before a regression is reported.', 'nbh-performance' ), 20, 5, 200, 'styles' ),
					'nbh_performance_job_worker_batch_size' => $this->number( __( 'Background worker batch size', 'nbh-performance' ), __( 'Maximum queued reliability jobs processed in one bounded worker run.', 'nbh-performance' ), 5, 1, 20, 'jobs' ),
					'nbh_performance_staging_auto_validation' => $this->boolean_default( __( 'Validate changes automatically', 'nbh-performance' ), __( 'Run frontend smoke tests after managed NBH Performance settings change.', 'nbh-performance' ), 1 ),
					'nbh_performance_auto_rollback_on_validation_failure' => $this->boolean_default( __( 'Rollback failed staging changes', 'nbh-performance' ), __( 'Restore the previous settings transaction when critical frontend validation fails on staging.', 'nbh-performance' ), 1 ),
					'nbh_performance_allow_production_auto_rollback' => $this->boolean( __( 'Allow automatic rollback on production', 'nbh-performance' ), __( 'Advanced safety option. Restore the previous transaction after a critical production smoke-test failure.', 'nbh-performance' ) ),
					'nbh_performance_smart_rollback_enabled' => $this->boolean_default( __( 'Arm Smart Optimize rollback', 'nbh-performance' ), __( 'Keep the latest Smart Optimize transaction available for verified visual, frontend and performance regression recovery.', 'nbh-performance' ), 1 ),
					'nbh_performance_smart_rollback_production_enabled' => $this->boolean( __( 'Allow Smart Optimize rollback on production', 'nbh-performance' ), __( 'Automatically restore the latest Smart Optimize transaction after a verified critical production regression.', 'nbh-performance' ) ),
					'nbh_performance_smart_rollback_window_minutes' => $this->number( __( 'Smart rollback protection window', 'nbh-performance' ), __( 'Maximum time after Smart Optimize when a verified regression may trigger automatic rollback.', 'nbh-performance' ), 360, 15, 1440, 'minutes' ),
					'nbh_performance_force_staging_validation' => $this->boolean( __( 'Treat this site as staging', 'nbh-performance' ), __( 'Enable staging rollback behavior when the hosting environment type is unavailable.', 'nbh-performance' ) ),
					'nbh_performance_external_cron_configured' => $this->boolean( __( 'External worker cron configured', 'nbh-performance' ), __( 'Mark this after configuring wp nbh-performance jobs run from a real server cron.', 'nbh-performance' ) ),
					'nbh_performance_validation_urls'      => $this->textarea( __( 'Validation URLs', 'nbh-performance' ), __( 'Optional same-origin pages to smoke-test after configuration changes.', 'nbh-performance' ), 'urls' ),
					'nbh_performance_diagnostic_exclusions' => $this->textarea( __( 'Diagnostic exclusions', 'nbh-performance' ), __( 'Checks or custom integration identifiers to suppress in support reports.', 'nbh-performance' ), 'exclusions' ),
					'nbh_performance_disable_rest_api_links' => $this->boolean( __( 'Remove REST API discovery links', 'nbh-performance' ), __( 'Remove public REST discovery tags and response headers without disabling the REST API.', 'nbh-performance' ) ),
					'nbh_performance_disable_rss_links'    => $this->boolean( __( 'Remove feed discovery links', 'nbh-performance' ), __( 'Remove RSS and comment-feed discovery tags from the document head.', 'nbh-performance' ) ),
					'nbh_performance_remove_comment_author_urls' => $this->boolean( __( 'Remove comment author website fields', 'nbh-performance' ), __( 'Hide the comment website field and author URL output to reduce spam incentives.', 'nbh-performance' ) ),
					'nbh_performance_disable_self_pingbacks' => $this->boolean( __( 'Disable self-pingbacks', 'nbh-performance' ), __( 'Prevent WordPress from pinging links that point back to the same site.', 'nbh-performance' ) ),
					'nbh_performance_disable_block_library_css' => $this->boolean( __( 'Disable block library CSS', 'nbh-performance' ), __( 'Advanced: remove Gutenberg block styles when the frontend does not use core blocks.', 'nbh-performance' ) ),
					'nbh_performance_disable_global_styles' => $this->boolean( __( 'Disable global styles', 'nbh-performance' ), __( 'Advanced: remove theme.json global and classic-theme style handles.', 'nbh-performance' ) ),
					'nbh_performance_disable_woocommerce_cart_fragments' => $this->boolean( __( 'Disable cart fragments outside store pages', 'nbh-performance' ), __( 'Remove the cart-fragment refresh script on non-commerce pages. Test mini-cart widgets before enabling.', 'nbh-performance' ) ),
					'nbh_performance_disable_woocommerce_scripts_non_shop' => $this->boolean( __( 'Unload WooCommerce assets outside store pages', 'nbh-performance' ), __( 'Remove common WooCommerce scripts and styles on pages that are not product, cart, checkout or account views.', 'nbh-performance' ) ),
					'nbh_performance_disable_password_strength_meter' => $this->boolean( __( 'Unload password strength meter when unused', 'nbh-performance' ), __( 'Remove password-strength scripts from non-account and non-checkout pages.', 'nbh-performance' ) ),
					'nbh_performance_woocommerce_currency_variant' => $this->boolean( __( 'Separate cache by WooCommerce currency', 'nbh-performance' ), __( 'Add a bounded currency variant when a currency switcher is active.', 'nbh-performance' ) ),
					'nbh_performance_woocommerce_currency_cookies' => $this->textarea( __( 'Currency switcher cookies', 'nbh-performance' ), __( 'Additional cookie names used by a currency switcher, one per line.', 'nbh-performance' ), 'exclusions' ),
				),
			),
			'script_manager' => array(
				'label'       => __( 'Script Manager', 'nbh-performance' ),
				'description' => __( 'Testing Mode, inventory capture and safety defaults for per-file script and stylesheet rules.', 'nbh-performance' ),
				'fields'      => array(
					'nbh_performance_script_manager_testing_mode' => $this->boolean_default( __( 'Script Manager testing mode', 'nbh-performance' ), __( 'Apply Script Manager rules only to logged-in administrators until they are verified.', 'nbh-performance' ), 1 ),
					'nbh_performance_asset_inventory_enabled' => $this->boolean( __( 'Track loaded CSS and JavaScript', 'nbh-performance' ), __( 'Capture a privacy-safe inventory of enqueued scripts and styles for the Script Manager. Inventory also captures while Script Manager itself is enabled.', 'nbh-performance' ) ),
				),
			),
			'enterprise'     => array(
				'label'       => __( 'Agency', 'nbh-performance' ),
				'description' => __( 'Coordinate advanced delivery, multisite reporting, reusable client profiles and agency branding.', 'nbh-performance' ),
				'fields'      => array(
					'nbh_performance_edge_html_ttl'       => $this->number( __( 'Edge HTML cache lifetime', 'nbh-performance' ), __( 'Shared-cache lifetime for public HTML responses.', 'nbh-performance' ), HOUR_IN_SECONDS, 60, WEEK_IN_SECONDS, 'seconds' ),
					'nbh_performance_edge_stale_while_revalidate' => $this->number( __( 'Serve stale while revalidating', 'nbh-performance' ), __( 'Allow the edge to serve a stale response while it refreshes the page in the background.', 'nbh-performance' ), 300, 0, DAY_IN_SECONDS, 'seconds' ),
					'nbh_performance_edge_stale_if_error' => $this->number( __( 'Serve stale during origin errors', 'nbh-performance' ), __( 'Maximum time an edge may serve a stale page when the origin is temporarily unavailable.', 'nbh-performance' ), DAY_IN_SECONDS, 0, WEEK_IN_SECONDS, 'seconds' ),
					'nbh_performance_edge_purge_webhook_url' => $this->text( __( 'Signed edge purge webhook', 'nbh-performance' ), __( 'Optional HTTPS endpoint that accepts signed tag-purge requests from NBH Performance.', 'nbh-performance' ), 'url' ),
					'nbh_performance_agency_company'      => $this->text( __( 'Agency or company name', 'nbh-performance' ), __( 'Brand name used in portfolio and client performance reports.', 'nbh-performance' ), 'text' ),
					'nbh_performance_agency_report_title' => $this->text( __( 'Report title', 'nbh-performance' ), __( 'Default heading for generated portfolio reports.', 'nbh-performance' ), 'text' ),
					'nbh_performance_agency_footer'       => $this->text( __( 'Report footer', 'nbh-performance' ), __( 'Short footer text shown in generated reports.', 'nbh-performance' ), 'text' ),
					'nbh_performance_agency_logo_url'     => $this->text( __( 'Agency logo URL', 'nbh-performance' ), __( 'Optional HTTPS logo URL for branded reports.', 'nbh-performance' ), 'url' ),
				),
			),
		);
	}

	public function state() {
		$schema = $this->schema();
		foreach ( $schema as &$section ) {
			foreach ( $section['fields'] as $option => &$field ) {
				$field['value'] = get_option( $option, $field['default'] );
			}
		}
		return $schema;
	}

	/** Reject compound JSON values before performing any writes. */
	public function validate_values( array $values ) {
		foreach ( $values as $value ) {
			if ( ! is_scalar( $value ) && null !== $value ) {
				return new \WP_Error( 'nbh_performance_invalid_setting_value', __( 'Setting values must be text, numbers or booleans.', 'nbh-performance' ), array( 'status' => 400 ) );
			}
		}
		return true;
	}

	public function update( array $values ) {
		if ( is_wp_error( $this->validate_values( $values ) ) ) {
			return array();
		}
		do_action( 'nbh_performance_before_advanced_settings_updated', $values );
		$definitions = array();
		foreach ( $this->schema() as $section ) {
			$definitions = array_merge( $definitions, $section['fields'] );
		}
		$updated = array();
		foreach ( $values as $option => $value ) {
			if ( ! isset( $definitions[ $option ] ) ) {
				continue;
			}
			$field = $definitions[ $option ];
			$clean = $this->sanitize( $value, $field );
			update_option( $option, $clean, false );
			$updated[ $option ] = $clean;
		}
		do_action( 'nbh_performance_advanced_settings_updated', $updated );
		return $updated;
	}

	private function sanitize( $value, array $field ) {
		if ( 'number' === $field['type'] ) {
			return max( $field['min'], min( $field['max'], absint( $value ) ) );
		}
		if ( 'boolean' === $field['type'] ) {
			return rest_sanitize_boolean( $value ) ? 1 : 0;
		}
		if ( 'select' === $field['type'] ) {
			$value = sanitize_key( $value );
			return isset( $field['choices'][ $value ] ) ? $value : $field['default'];
		}
		if ( 'url' === $field['sanitize'] ) {
			$url = esc_url_raw( trim( (string) $value ) );
			return 'https' === strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ? untrailingslashit( $url ) : '';
		}
		if ( 'hostname' === $field['sanitize'] ) {
			return preg_replace( '/[^a-z0-9.\-:]/i', '', strtolower( trim( (string) $value ) ) );
		}
		$lines = preg_split( '/\r\n|\r|\n/', (string) $value );
		$lines = array_slice( array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $lines ) ) ) ), 0, 200 );
		return implode( "\n", $lines );
	}

	private function number( $label, $description, $default_value, $min, $max, $suffix ) {
		return array(
			'label'       => $label,
			'description' => $description,
			'default'     => $default_value,
			'min'         => $min,
			'max'         => $max,
			'suffix'      => $suffix,
		) + array(
			'type'     => 'number',
			'sanitize' => 'number',
		);
	}
	private function boolean( $label, $description ) {
		return $this->boolean_default( $label, $description, 0 );
	}
	private function boolean_default( $label, $description, $default_value ) {
		return array(
			'label'       => $label,
			'description' => $description,
			'default'     => $default_value,
		) + array(
			'type'     => 'boolean',
			'sanitize' => 'boolean',
		);
	}
	private function textarea( $label, $description, $sanitize ) {
		return array(
			'label'       => $label,
			'description' => $description,
			'sanitize'    => $sanitize,
		) + array(
			'type'    => 'textarea',
			'default' => '',
		);
	}
	private function text( $label, $description, $sanitize ) {
		return array(
			'label'       => $label,
			'description' => $description,
			'sanitize'    => $sanitize,
		) + array(
			'type'    => 'text',
			'default' => '',
		);
	}
	private function select( $label, $description, $default_value, array $choices ) {
		return array(
			'label'       => $label,
			'description' => $description,
			'default'     => $default_value,
			'choices'     => $choices,
		) + array(
			'type'     => 'select',
			'sanitize' => 'select',
		);
	}
}
