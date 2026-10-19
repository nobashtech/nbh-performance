<?php
/**
 * Minimal PHP 7.4-compatible safe wrappers required by the scoped CSS parser.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Vendor\Safe;

use RuntimeException;

function preg_match( $pattern, $subject, &$matches = null, $flags = 0, $offset = 0 ) {
	$result = \preg_match( $pattern, $subject, $matches, $flags, $offset );
	if ( false === $result ) {
		throw new RuntimeException( 'preg_match failed: ' . preg_last_error() );
	}
	return $result;
}

function preg_match_all( $pattern, $subject, &$matches = null, $flags = PREG_PATTERN_ORDER, $offset = 0 ) {
	$result = \preg_match_all( $pattern, $subject, $matches, $flags, $offset );
	if ( false === $result ) {
		throw new RuntimeException( 'preg_match_all failed: ' . preg_last_error() );
	}
	return $result;
}

function preg_split( $pattern, $subject, $limit = -1, $flags = 0 ) {
	$result = \preg_split( $pattern, $subject, $limit, $flags );
	if ( false === $result ) {
		throw new RuntimeException( 'preg_split failed: ' . preg_last_error() );
	}
	return $result;
}

function preg_replace( $pattern, $replacement, $subject, $limit = -1, &$count = null ) {
	$result = \preg_replace( $pattern, $replacement, $subject, $limit, $count );
	if ( null === $result ) {
		throw new RuntimeException( 'preg_replace failed: ' . preg_last_error() );
	}
	return $result;
}

function iconv( $from_encoding, $to_encoding, $string ) {
	$result = \iconv( $from_encoding, $to_encoding, $string );
	if ( false === $result ) {
		throw new RuntimeException( 'iconv failed.' );
	}
	return $result;
}

function class_alias( $class, $alias, $autoload = true ) {
	$result = \class_alias( $class, $alias, $autoload );
	if ( false === $result ) {
		throw new RuntimeException( 'class_alias failed for ' . $class . '.' );
	}
	return $result;
}
