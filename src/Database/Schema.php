<?php
/**
 * Database schema version manager.
 *
 * @package   Myrk
 * @author    Bjorn Holine <bjorn@myrk.build>
 * @license   GPL-2.0-or-later
 * @link      https://myrk.build/
 * @copyright 2026 Bjorn Holine
 */

declare( strict_types=1 );

namespace Myrk\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Myrk\Database\Migrations\Migration100;

/**
 * Manages database schema installation and version tracking.
 */
class Schema {

	private const CURRENT_DB_VERSION = '1.0.0';

	/**
	 * Run any outstanding migrations. Called on plugin activation and on update.
	 */
	public static function install(): void {
		$installed = self::get_installed_version();

		if ( version_compare( $installed, '1.0.0', '<' ) ) {
			Migration100::run();
			update_option( 'myrk_db_version', '1.0.0' );
		}

		/*
		 * Future migrations follow the same pattern:
		 * if ( version_compare( $installed, '1.1.0', '<' ) ) {
		 *     Migration110::run();
		 *     update_option( 'myrk_db_version', '1.1.0' );
		 * }
		 */
	}

	/**
	 * Return the currently installed database schema version.
	 *
	 * @return string
	 */
	public static function get_installed_version(): string {
		return (string) get_option( 'myrk_db_version', '0.0.0' );
	}

	/**
	 * Return the latest known database schema version bundled with the plugin.
	 *
	 * @return string
	 */
	public static function get_current_version(): string {
		return self::CURRENT_DB_VERSION;
	}
}
