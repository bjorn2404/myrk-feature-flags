<?php
/**
 * Writes environment state changes and state log entries to the database.
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

use Myrk\ChangedVia;

/**
 * Handles all write operations for flag environment state and the state audit log.
 */
class StateWriter {

	/**
	 * Upsert the environment state row and write a state log entry.
	 *
	 * $changes keys match column names in wp_myrk_flag_environments:
	 *   status, rollout_percentage, anonymous_strategy,
	 *   circuit_breaker_count, circuit_breaker_tripped
	 *
	 * @param string               $flag_key    Flag key to update.
	 * @param string               $environment Target environment.
	 * @param array<string, mixed> $changes     Column/value pairs to write.
	 * @param ChangedVia           $changed_via Actor that triggered the change.
	 * @param int|null             $changed_by  User ID, or null for system changes.
	 * @param string|null          $note        Optional human-readable note.
	 * @param string|null          $git_sha     Optional git commit SHA.
	 * @return bool
	 */
	public static function upsert_environment_state(
		string $flag_key,
		string $environment,
		array $changes,
		ChangedVia $changed_via,
		?int $changed_by = null,
		?string $note = null,
		?string $git_sha = null
	): bool {
		global $wpdb;

		$flag_id = FlagRepository::get_or_create_flag_id( $flag_key );
		if ( null === $flag_id ) {
			return false;
		}

		$previous_row   = FlagRepository::get_environment_state( $flag_key, $environment );
		$previous_state = self::serialize_state( $previous_row );

		$now = current_time( 'mysql', true );

		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}myrk_flag_environments WHERE flag_id = %d AND environment = %s",
				$flag_id,
				$environment
			)
		);

		if ( $existing_id ) {
			$wpdb->update(
				$wpdb->prefix . 'myrk_flag_environments',
				array_merge( $changes, [ 'updated_at' => $now ] ),
				[ 'id' => (int) $existing_id ]
			);
		} else {
			$wpdb->insert(
				$wpdb->prefix . 'myrk_flag_environments',
				array_merge(
					[
						'flag_id'                 => $flag_id,
						'environment'             => $environment,
						'status'                  => 0,
						'rollout_percentage'      => 0,
						'anonymous_strategy'      => 'ip',
						'circuit_breaker_count'   => 0,
						'circuit_breaker_tripped' => 0,
						'created_at'              => $now,
						'updated_at'              => $now,
					],
					$changes
				)
			);
		}

		FlagRepository::invalidate_cache( $flag_key, $environment );

		$new_row = FlagRepository::get_environment_state( $flag_key, $environment );

		self::write_log(
			flag_id:        $flag_id,
			flag_key:       $flag_key,
			environment:    $environment,
			changed_by:     $changed_by,
			changed_via:    $changed_via,
			previous_state: $previous_state,
			new_state:      self::serialize_state( $new_row ),
			note:           $note,
			git_sha:        $git_sha
		);

		/**
		 * Fires after a flag environment state is changed.
		 *
		 * @param string     $flag_key
		 * @param string     $environment
		 * @param ChangedVia $changed_via
		 */
		do_action( 'myrk_flag_state_changed', $flag_key, $environment, $changed_via );

		return true;
	}

	/**
	 * Write directly to the state log without changing environment state.
	 * Used by the circuit breaker and rewind mechanism.
	 *
	 * @param int         $flag_id        DB row ID of the flag.
	 * @param string      $flag_key       Flag key (denormalized for readability).
	 * @param string      $environment    Environment the change applies to.
	 * @param int|null    $changed_by     User ID, or null for system changes.
	 * @param ChangedVia  $changed_via    Actor that triggered the change.
	 * @param string|null $previous_state JSON snapshot before the change.
	 * @param string|null $new_state      JSON snapshot after the change.
	 * @param string|null $note           Optional human-readable note.
	 * @param string|null $git_sha        Optional git commit SHA.
	 */
	public static function write_log(
		int $flag_id,
		string $flag_key,
		string $environment,
		?int $changed_by,
		ChangedVia $changed_via,
		?string $previous_state,
		?string $new_state,
		?string $note = null,
		?string $git_sha = null
	): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'myrk_state_log',
			[
				'flag_id'        => $flag_id,
				'flag_key'       => $flag_key,
				'environment'    => $environment,
				'changed_by'     => $changed_by,
				'changed_via'    => $changed_via->value,
				'previous_state' => $previous_state,
				'new_state'      => $new_state,
				'git_sha'        => $git_sha,
				'note'           => $note,
				'created_at'     => current_time( 'mysql', true ),
			]
		);
	}

	/**
	 * Serialize an environment state row to the compact JSON shape used in logs.
	 *
	 * @param object|null $row Environment state row to serialize.
	 * @return string|null JSON: {"status":"enabled","percentage":25,"anonymous_strategy":"ip"}
	 */
	private static function serialize_state( ?object $row ): ?string {
		if ( null === $row ) {
			return null;
		}

		return (string) wp_json_encode(
			[
				'status'             => 1 === (int) $row->status ? 'enabled' : 'disabled',
				'percentage'         => (int) $row->rollout_percentage,
				'anonymous_strategy' => $row->anonymous_strategy,
			]
		);
	}
}
