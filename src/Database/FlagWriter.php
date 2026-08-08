<?php
/**
 * Write operations for flag definitions and targeting rules.
 *
 * @package Myrk
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

namespace Myrk\Database;

/**
 * Handles insert, update, and delete operations for flag definitions and targeting rules.
 * Environment state writes go through StateWriter instead.
 */
class FlagWriter {

	// -------------------------------------------------------------------------
	// Flag definitions (wp_myrk_flags)
	// -------------------------------------------------------------------------

	/**
	 * Insert a new flag row. Returns the new ID, or null on failure.
	 *
	 * @param array $data Flag fields: flag_key, label, description, default_state,
	 *                    rewind_strategy, lifecycle, group_id, tags.
	 * @return int|null
	 */
	public static function create( array $data ): ?int {
		global $wpdb;

		$now    = current_time( 'mysql', true );
		$result = $wpdb->insert(
			$wpdb->prefix . 'myrk_flags',
			[
				'flag_key'        => $data['flag_key'],
				'label'           => $data['label'],
				'description'     => $data['description'] ?? '',
				'default_state'   => (int) ( $data['default_state'] ?? 0 ),
				'rewind_strategy' => $data['rewind_strategy'] ?? 'stepwise',
				'lifecycle'       => $data['lifecycle'] ?? 'temporary',
				'group_id'        => $data['group_id'] ?? null,
				'tags'            => $data['tags'] ?? null,
				'is_registered'   => 0,
				'created_at'      => $now,
				'updated_at'      => $now,
			]
		);

		if ( false === $result || 0 === $wpdb->insert_id ) {
			return null;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update mutable fields on a flag definition. Does not touch state columns.
	 *
	 * @param string $flag_key The flag key to update.
	 * @param array  $changes  Fields to update: label, description, rewind_strategy, default_state.
	 * @return bool
	 */
	public static function update_definition( string $flag_key, array $changes ): bool {
		global $wpdb;

		$allowed = [ 'label', 'description', 'rewind_strategy', 'default_state', 'lifecycle', 'group_id', 'tags' ];
		$data    = array_intersect_key( $changes, array_flip( $allowed ) );

		if ( empty( $data ) ) {
			return false;
		}

		$data['updated_at'] = current_time( 'mysql', true );

		$result = $wpdb->update(
			$wpdb->prefix . 'myrk_flags',
			$data,
			[ 'flag_key' => $flag_key ]
		);

		return false !== $result;
	}

	/**
	 * Delete a flag and all its associated state, targets, and environment rows.
	 * State-log and fatal-log rows are intentionally retained for audit purposes.
	 *
	 * @param string $flag_key The flag key to delete.
	 * @return bool
	 */
	public static function delete( string $flag_key ): bool {
		global $wpdb;

		$flag_id = FlagRepository::get_flag_id( $flag_key );
		if ( null === $flag_id ) {
			return false;
		}

		$wpdb->delete( $wpdb->prefix . 'myrk_flag_targets', [ 'flag_id' => $flag_id ] );
		$wpdb->delete( $wpdb->prefix . 'myrk_flag_environments', [ 'flag_id' => $flag_id ] );
		$result = $wpdb->delete( $wpdb->prefix . 'myrk_flags', [ 'id' => $flag_id ] );

		return false !== $result;
	}

	// -------------------------------------------------------------------------
	// Targeting rules (wp_myrk_flag_targets)
	// -------------------------------------------------------------------------

	/**
	 * Insert a new targeting rule. Returns the new ID, or null on failure.
	 *
	 * @param int    $flag_id     DB row ID of the parent flag.
	 * @param string $environment Target environment for the rule.
	 * @param array  $data        Rule fields: type, operator, value, enabled, sort_order.
	 * @return int|null
	 */
	public static function create_target( int $flag_id, string $environment, array $data ): ?int {
		global $wpdb;

		$sort_order = isset( $data['sort_order'] )
			? (int) $data['sort_order']
			: self::next_sort_order( $flag_id, $environment );

		$result = $wpdb->insert(
			$wpdb->prefix . 'myrk_flag_targets',
			[
				'flag_id'     => $flag_id,
				'environment' => $environment,
				'type'        => $data['type'],
				'operator'    => $data['operator'],
				'value'       => $data['value'],
				'enabled'     => (int) ( $data['enabled'] ?? 1 ),
				'sort_order'  => $sort_order,
			]
		);

		if ( false === $result || 0 === $wpdb->insert_id ) {
			return null;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a targeting rule. Only the provided keys are changed.
	 *
	 * @param int   $target_id DB row ID of the target to update.
	 * @param array $changes   Fields to update: type, operator, value, enabled, sort_order.
	 * @return bool
	 */
	public static function update_target( int $target_id, array $changes ): bool {
		global $wpdb;

		$allowed = [ 'type', 'operator', 'value', 'enabled', 'sort_order' ];
		$data    = array_intersect_key( $changes, array_flip( $allowed ) );

		if ( empty( $data ) ) {
			return false;
		}

		$result = $wpdb->update(
			$wpdb->prefix . 'myrk_flag_targets',
			$data,
			[ 'id' => $target_id ]
		);

		return false !== $result;
	}

	/**
	 * Delete a single targeting rule.
	 *
	 * @param int $target_id DB row ID of the target to delete.
	 * @return bool
	 */
	public static function delete_target( int $target_id ): bool {
		global $wpdb;

		$result = $wpdb->delete(
			$wpdb->prefix . 'myrk_flag_targets',
			[ 'id' => $target_id ]
		);

		return false !== $result;
	}

	// -------------------------------------------------------------------------
	// Helpers.
	// -------------------------------------------------------------------------

	/**
	 * Return the next available sort_order for a flag + environment combination.
	 *
	 * @param int    $flag_id     DB row ID of the flag.
	 * @param string $environment Target environment.
	 * @return int
	 */
	private static function next_sort_order( int $flag_id, string $environment ): int {
		global $wpdb;

		$max = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(sort_order) FROM {$wpdb->prefix}myrk_flag_targets
				 WHERE flag_id = %d AND environment = %s",
				$flag_id,
				$environment
			)
		);

		return null === $max ? 0 : (int) $max + 10;
	}
}
