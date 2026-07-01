<?php
/**
 * Admin submenu page for flag groups.
 *
 * @package Myrk
 */

declare( strict_types=1 );

namespace Myrk\Admin;

/**
 * Visible submenu page under the Myrk top-level menu.
 * Slug: `myrk-groups` → `admin.php?page=myrk-groups`
 */
class GroupsScreen {

	private const PARENT_SLUG   = 'myrk';
	private const PAGE_SLUG     = 'myrk-groups';
	private const SCRIPT_HANDLE = 'myrk-admin-groups';
	private const STYLE_HANDLE  = 'myrk-admin';

	/**
	 * Hook suffix returned by add_submenu_page(), used to scope script enqueuing.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Register the submenu page and enqueue hook.
	 */
	public function register(): void {
		$this->hook_suffix = (string) add_submenu_page(
			self::PARENT_SLUG,
			__( 'Groups — Myrk', 'myrk' ),
			__( 'Groups', 'myrk' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render' ]
		);

		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
	}

	/**
	 * Enqueue the groups JS bundle and CSS on this screen only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_scripts( string $hook ): void {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		$asset_file = MYRK_DIR . 'build/admin/groups.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: [
				'dependencies' => [ 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n', 'wp-dataviews' ],
				'version'      => MYRK_VERSION,
			];

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			MYRK_URL . 'build/admin/groups.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		$css_path = MYRK_DIR . 'build/admin/groups.css';
		if ( file_exists( $css_path ) ) {
			wp_enqueue_style(
				self::SCRIPT_HANDLE . '-built',
				MYRK_URL . 'build/admin/groups.css',
				[ 'wp-components' ],
				$asset['version']
			);
		}

		wp_enqueue_style(
			self::STYLE_HANDLE . '-dataviews',
			MYRK_URL . 'assets/css/dataviews.css',
			[ 'wp-components' ],
			MYRK_VERSION
		);

		wp_enqueue_style(
			self::STYLE_HANDLE,
			MYRK_URL . 'assets/css/admin.css',
			[ self::STYLE_HANDLE . '-dataviews' ],
			MYRK_VERSION
		);

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'myrkAdminGroups',
			[
				'restUrl'  => rest_url( 'myrk/v1/' ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'flagsUrl' => admin_url( 'admin.php?page=myrk' ),
			]
		);
	}

	/**
	 * Render the React mount point for the groups list.
	 */
	public function render(): void {
		echo '<div class="wrap"><div id="myrk-groups-root"></div></div>';
	}
}
