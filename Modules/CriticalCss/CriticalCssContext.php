<?php
/** Critical CSS request context resolver. @package NBHPerformance */
namespace NBH\Performance\Modules\CriticalCss;

final class CriticalCssContext {
	/** Resolve the current template context. @return string */
	public function resolve() {
		if ( function_exists( 'is_front_page' ) && is_front_page() ) {
			return 'home';
		}
		if ( function_exists( 'is_home' ) && is_home() ) {
			return 'posts-page';
		}
		if ( function_exists( 'is_singular' ) && is_singular( 'product' ) ) {
			return 'product';
		}
		if ( function_exists( 'is_singular' ) && is_singular() ) {
			return 'single-' . sanitize_key( (string) get_post_type() );
		}
		if ( function_exists( 'is_post_type_archive' ) && is_post_type_archive() ) {
			return 'archive-' . sanitize_key( (string) get_query_var( 'post_type' ) );
		}
		if ( function_exists( 'is_tax' ) && is_tax() ) {
			return 'taxonomy-' . sanitize_key( (string) get_query_var( 'taxonomy' ) );
		}
		if ( function_exists( 'is_category' ) && is_category() ) {
			return 'category';
		}
		if ( function_exists( 'is_tag' ) && is_tag() ) {
			return 'tag';
		}
		if ( function_exists( 'is_author' ) && is_author() ) {
			return 'author';
		}
		if ( function_exists( 'is_search' ) && is_search() ) {
			return 'search';
		}
		return 'generic';
	}
}
