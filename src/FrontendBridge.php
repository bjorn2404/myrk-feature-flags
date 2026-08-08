<?php
/**
 * Injects evaluated flag states into the page for JS consumption.
 *
 * @package Myrk
 */

declare( strict_types=1 );

namespace Myrk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the myrk-flags script and populates window.myrkFlags on every page
 * load (frontend and admin) so JavaScript code can read flag states synchronously
 * without an additional HTTP request.
 *
 * Mirrors how LaunchDarkly's browser SDK bootstraps flag values: the server
 * evaluates each flag for the current user context and ships the results as
 * a JSON payload; the JS helper simply reads from that object.
 */
class FrontendBridge {

	/**
	 * Register WordPress hooks.
	 */
	public function register_hooks(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * Enqueue the helper script and inject the current user's flag states.
	 * Skips gracefully when no flags are registered.
	 */
	public function enqueue(): void {
		if ( empty( Registry::all() ) ) {
			return;
		}

		wp_register_script(
			'myrk-flags',
			MYRK_URL . 'assets/js/myrk-flags.js',
			[],
			MYRK_VERSION,
			[ 'in_footer' => true ]
		);

		wp_add_inline_script(
			'myrk-flags',
			'window.myrkFlags = ' . wp_json_encode( Myrk::bootstrap() ) . ';',
			'before'
		);

		wp_enqueue_script( 'myrk-flags' );
	}
}
