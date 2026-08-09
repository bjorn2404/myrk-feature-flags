<?php
/**
 * Admin edit screen for creating and editing feature flags.
 *
 * @package Myrk
 */

declare( strict_types=1 );

namespace Myrk\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hidden admin submenu page for create/edit flag (DataForm).
 * Slug: `myrk-edit-flag` → `admin.php?page=myrk-edit-flag[&flag_key=xxx]`
 *
 * Not visible in the nav (hidden via CSS, not removed — removing breaks WordPress's
 * hook name resolution and causes access denied error). Uses the same
 * `admin/flags` JS bundle as FlagsScreen — React reads the URL to render
 * the edit form instead of the list.
 */
class EditScreen {

	private const PARENT_SLUG   = 'myrk';
	private const PAGE_SLUG     = 'myrk-edit-flag';
	private const SCRIPT_HANDLE = 'myrk-admin-flags';

	/**
	 * Hook suffix returned by add_submenu_page(), used to scope script enqueuing.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Register the hidden submenu page and related hooks.
	 */
	public function register(): void {
		$this->hook_suffix = (string) add_submenu_page(
			self::PARENT_SLUG,
			__( 'Edit Flag — Myrk', 'myrk-feature-flags' ),
			__( 'Edit Flag', 'myrk-feature-flags' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render' ]
		);

		// Hide from nav via CSS — we cannot call remove_submenu_page() because
		// that removes the entry from $submenu, which breaks get_plugin_page_hookname()
		// and causes WordPress's access check to return false (access denied).
		add_action( 'admin_head', [ $this, 'hide_from_nav' ] );

		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
	}

	/**
	 * Enqueue the admin JS and CSS bundles on the edit screen only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_scripts( string $hook ): void {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		$asset_file = MYRK_DIR . 'build/admin/flags.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: [
				'dependencies' => [ 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n', 'wp-dataviews' ],
				'version'      => MYRK_VERSION,
			];

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			MYRK_URL . 'build/admin/flags.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		$css_path = MYRK_DIR . 'build/admin/flags.css';
		if ( file_exists( $css_path ) ) {
			wp_enqueue_style(
				self::SCRIPT_HANDLE . '-built',
				MYRK_URL . 'build/admin/flags.css',
				[ 'wp-components' ],
				$asset['version']
			);
		}

		wp_enqueue_style(
			'myrk-admin',
			MYRK_URL . 'assets/css/admin.css',
			[],
			MYRK_VERSION
		);

		// Tell React to render the edit form, and which flag to load (empty = create).
		wp_localize_script(
			self::SCRIPT_HANDLE,
			'myrkAdminFlags',
			[
				'restUrl'    => rest_url( 'myrk/v1/' ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'currentEnv' => wp_get_environment_type(),
				'editUrl'    => admin_url( 'admin.php?page=' . self::PAGE_SLUG ),
				'editMode'   => true,
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only URL param passed to JS; all data operations go through the REST API with its own nonce (line 104).
				'flagKey'    => sanitize_key( wp_unslash( $_GET['flag_key'] ?? '' ) ),
				'listUrl'    => admin_url( 'admin.php?page=myrk' ),
			]
		);
	}

	/**
	 * Output inline CSS that hides the edit-flag submenu item from the nav.
	 */
	public function hide_from_nav(): void {
		echo '<style>#adminmenu .wp-submenu li:has(> a[href*="'
			. esc_attr( self::PAGE_SLUG )
			. '"]) { display: none !important; }</style>' . "\n";
	}

	/**
	 * Render the React mount point for the flag edit form.
	 */
	public function render(): void {
		echo '<div class="wrap"><div id="myrk-flags-root"></div></div>';
	}
}
