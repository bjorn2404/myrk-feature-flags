<?php
/**
 * Plugin Name:       Myrk Feature Flags
 * Plugin URI:        https://myrk.build
 * Description:       Enterprise feature flags for WordPress. Per-environment control, percentage rollout, user targeting, and stale flag detection.
 * Version:           1.0.0
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Author:            Bjorn Holine
 * Author URI:        https://myrk.build
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       myrk
 * Domain Path:       /languages
 *
 * @package Myrk
 */

declare( strict_types=1 );

use Myrk\Myrk as MyrkFacade;
use Myrk\Plugin;
use Myrk\Pro\Plugin as ProPlugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MYRK_VERSION', '1.0.0' );
define( 'MYRK_FILE', __FILE__ );
define( 'MYRK_DIR', plugin_dir_path( __FILE__ ) );
define( 'MYRK_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	function ( string $class_name ): void {
		if ( ! str_starts_with( $class_name, 'Myrk\\' ) ) {
			return;
		}
		$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, 5 ) ) . '.php';
		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

// Default implementation — returns false in the free tier.
// Pro distribution overrides this via pro/Plugin.php before this guard executes.
if ( ! function_exists( 'myrk_is_licensed' ) ) {
	/**
	 * Return true when a valid Pro licence is active.
	 *
	 * @return bool
	 */
	function myrk_is_licensed(): bool {
		return false;
	}
}

// Procedural aliases for WordPress-convention developers.
if ( ! function_exists( 'myrk_is_enabled' ) ) {
	/**
	 * Return true when the given flag is enabled for the current user.
	 *
	 * @param string $flag_key The flag key to evaluate.
	 * @param bool   $fallback Fallback value when the flag is not found.
	 * @return bool
	 */
	function myrk_is_enabled( string $flag_key, bool $fallback = false ): bool {
		return MyrkFacade::is_enabled( $flag_key, null, $fallback );
	}
}

if ( ! function_exists( 'myrk_is_enabled_for' ) ) {
	/**
	 * Return true when the given flag is enabled for a specific user.
	 *
	 * @param string  $flag_key The flag key to evaluate.
	 * @param WP_User $user     The user to evaluate targeting rules against.
	 * @param bool    $fallback Fallback value when the flag is not found.
	 * @return bool
	 */
	function myrk_is_enabled_for( string $flag_key, WP_User $user, bool $fallback = false ): bool {
		return MyrkFacade::is_enabled( $flag_key, $user, $fallback );
	}
}

$myrk_plugin = new Plugin();
$myrk_plugin->boot();

if ( myrk_is_licensed() ) {
	$myrk_pro = new ProPlugin();
	$myrk_pro->boot();
}
