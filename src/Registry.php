<?php
/**
 * In-memory registry of registered feature flags.
 *
 * @package   Myrk
 * @author    Bjorn Holine <bjorn@myrk.build>
 * @license   GPL-2.0-or-later
 * @link      https://myrk.build/
 * @copyright 2026 Bjorn Holine
 */

declare( strict_types=1 );

namespace Myrk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static registry holding all flags registered in code via Myrk::register().
 */
class Registry {

	/**
	 * All registered flags, keyed by flag_key.
	 *
	 * @var array<string, Flag>
	 */
	private static array $flags = [];

	/**
	 * Register a flag definition in the in-memory store.
	 *
	 * @param string $flag_key Unique flag identifier.
	 * @param Flag   $flag     Flag definition object.
	 */
	public static function register( string $flag_key, Flag $flag ): void {
		self::$flags[ $flag_key ] = $flag;
	}

	/**
	 * Return the flag definition for a given key, or null when not found.
	 *
	 * @param string $flag_key The flag key to look up.
	 * @return Flag|null
	 */
	public static function get( string $flag_key ): ?Flag {
		return self::$flags[ $flag_key ] ?? null;
	}

	/**
	 * Return all registered flags keyed by flag_key.
	 *
	 * @return array<string, Flag>
	 */
	public static function all(): array {
		return self::$flags;
	}

	/**
	 * Return true when a flag with the given key is registered.
	 *
	 * @param string $flag_key The flag key to check.
	 * @return bool
	 */
	public static function has( string $flag_key ): bool {
		return isset( self::$flags[ $flag_key ] );
	}

	/**
	 * Clear all registered flags (used in tests).
	 */
	public static function reset(): void {
		self::$flags = [];
	}
}
