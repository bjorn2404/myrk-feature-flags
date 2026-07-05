<?php

declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// WordPress time constants used by Cleanup and CircuitBreaker in pure-PHP paths.
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

// Minimal WordPress function stubs for unit tests that exercise pure-PHP paths.
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $str ): string {
		return trim( strip_tags( $str ) );
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return stripslashes_deep( $value );
	}
}
if ( ! function_exists( 'stripslashes_deep' ) ) {
	function stripslashes_deep( $value ) {
		if ( is_array( $value ) ) {
			return array_map( 'stripslashes_deep', $value );
		}
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}
