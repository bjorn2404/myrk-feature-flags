<?php

declare( strict_types=1 );

$_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: sys_get_temp_dir() . '/wordpress-tests-lib';

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	// WP_TESTS_DIR not available — load autoloader + WP constant stubs so that
	// unit tests work on any machine with PHP 8.1+ and Composer installed.
	require_once __DIR__ . '/unit-bootstrap.php';
	return;
}

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter( 'muplugins_loaded', function () {
	require dirname( __DIR__ ) . '/myrk.php';
} );

require $_tests_dir . '/includes/bootstrap.php';
