<?php

declare( strict_types=1 );

$_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: sys_get_temp_dir() . '/wordpress-tests-lib';

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	// WP_TESTS_DIR not available — load autoloader + WP constant stubs so that
	// unit tests work on any machine with PHP 8.1+ and Composer installed.
	require_once __DIR__ . '/unit-bootstrap.php';
	return;
}

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );

// Ensure wp_get_environment_type() returns 'local' consistently across all test environments.
if ( ! defined( 'WP_ENVIRONMENT_TYPE' ) ) {
	define( 'WP_ENVIRONMENT_TYPE', 'local' );
}

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter( 'muplugins_loaded', function () {
	require dirname( __DIR__ ) . '/myrk-feature-flags.php';
} );

// Create plugin tables once, before any test class runs.
tests_add_filter( 'after_setup_theme', function () {
	Myrk\Database\Schema::install();
} );

require $_tests_dir . '/includes/bootstrap.php';
