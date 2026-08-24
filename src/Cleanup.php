<?php
/**
 * Helpers for detecting and cleaning up stale feature flags.
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

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Utility methods for identifying stale flags and locating code references.
 */
class Cleanup {

	public const DEFAULT_STALE_DAYS = 90;

	/**
	 * Return true when a flag environment meets all staleness criteria:
	 *  - Fully rolled out (enabled at 100%) OR fully disabled (0%)
	 *  - No state changes in the last $threshold_days days
	 *
	 * The caller is responsible for confirming no active targeting rules apply,
	 * since that check requires a DB query (handled at the repository layer).
	 *
	 * @param array $flag_env_row   Environment state row with keys: status, rollout_percentage, updated_at.
	 * @param int   $threshold_days Number of days without changes to be considered stale.
	 * @return bool
	 */
	public static function is_stale( array $flag_env_row, int $threshold_days = self::DEFAULT_STALE_DAYS ): bool {
		$status     = (int) $flag_env_row['status'];
		$percentage = (int) $flag_env_row['rollout_percentage'];

		$fully_on  = 1 === $status && 100 === $percentage;
		$fully_off = 0 === $status && 0 === $percentage;

		if ( ! $fully_on && ! $fully_off ) {
			return false;
		}

		$updated_at      = strtotime( $flag_env_row['updated_at'] );
		$seconds_elapsed = time() - $updated_at;
		$days_elapsed    = $seconds_elapsed / DAY_IN_SECONDS;

		return $days_elapsed >= $threshold_days;
	}

	/**
	 * Scan $search_path recursively for all Myrk API call sites referencing $flag_key.
	 *
	 * Patterns detected:
	 *  - Myrk::is_enabled( 'flag_key' )
	 *  - Myrk::attempt( 'flag_key'
	 *  - myrk_is_enabled( 'flag_key' )
	 *  - myrk_is_enabled_for( 'flag_key'
	 *
	 * @param string $flag_key    The flag key to search for.
	 * @param string $search_path Directory to scan recursively.
	 * @return list<array{file: string, line: int, context: string}>
	 */
	public static function find_refs( string $flag_key, string $search_path ): array {
		if ( ! is_dir( $search_path ) ) {
			return [];
		}

		$escaped  = preg_quote( $flag_key, '/' );
		$patterns = [
			"/Myrk::is_enabled\s*\(\s*['\"]" . $escaped . "['\"][\s,)]/",
			"/Myrk::attempt\s*\(\s*['\"]" . $escaped . "['\"][\s,)]/",
			"/myrk_is_enabled\s*\(\s*['\"]" . $escaped . "['\"][\s,)]/",
			"/myrk_is_enabled_for\s*\(\s*['\"]" . $escaped . "['\"][\s,)]/",
		];

		$refs     = [];
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $search_path, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY,
			RecursiveIteratorIterator::CATCH_GET_CHILD
		);

		/**
		 * Current file from the recursive directory iterator.
		 *
		 * @var SplFileInfo $file
		 */
		foreach ( $iterator as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}

			$lines = file( $file->getPathname(), FILE_IGNORE_NEW_LINES );
			if ( false === $lines ) {
				continue;
			}

			foreach ( $lines as $line_number => $line_content ) {
				foreach ( $patterns as $pattern ) {
					if ( preg_match( $pattern, $line_content ) ) {
						$refs[] = [
							'file'    => $file->getPathname(),
							'line'    => $line_number + 1,
							'context' => trim( $line_content ),
						];
						break; // Only record once per line even if multiple patterns match.
					}
				}
			}
		}

		return $refs;
	}
}
