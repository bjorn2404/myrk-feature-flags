<?php
/**
 * Database read and write operations for flag groups.
 *
 * @package Myrk
 */

declare( strict_types=1 );

namespace Myrk\Database;

/**
 * Handles all persistence for wp_myrk_flag_groups.
 * Groups organize related flags by sprint, release, or initiative and carry an
 * optional outbound link to the authoritative PM system.
 */
class GroupRepository {

	// -------------------------------------------------------------------------
	// Reads
	// -------------------------------------------------------------------------

	/**
	 * Return all groups with a flag_count aggregate.
	 *
	 * @return list<object>
	 */
	public static function get_all_with_flag_counts(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT fg.id, fg.name, fg.description, fg.external_ref, fg.external_ref_url,
			        fg.created_at, fg.updated_at,
			        COUNT( f.id ) AS flag_count
			 FROM {$wpdb->prefix}myrk_flag_groups fg
			 LEFT JOIN {$wpdb->prefix}myrk_flags f ON f.group_id = fg.id
			 GROUP BY fg.id
			 ORDER BY fg.name ASC"
		);

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Return a single group row by ID, or null when not found.
	 *
	 * @param int $id Group DB primary key.
	 * @return object|null
	 */
	public static function get_by_id( int $id ): ?object {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}myrk_flag_groups WHERE id = %d",
				$id
			)
		);

		return $row ?? null;
	}

	/**
	 * Return a single group row by exact name match, or null when not found.
	 *
	 * @param string $name Group name.
	 * @return object|null
	 */
	public static function get_by_name( string $name ): ?object {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}myrk_flag_groups WHERE name = %s",
				$name
			)
		);

		return $row ?? null;
	}

	/**
	 * Return all flag rows belonging to a group.
	 *
	 * @param int $id Group DB primary key.
	 * @return list<object>
	 */
	public static function get_flags_for_group( int $id ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT flag_key, label, description, lifecycle, tags
				 FROM {$wpdb->prefix}myrk_flags
				 WHERE group_id = %d
				 ORDER BY flag_key ASC",
				$id
			)
		);

		return is_array( $rows ) ? $rows : [];
	}

	// -------------------------------------------------------------------------
	// Writes
	// -------------------------------------------------------------------------

	/**
	 * Look up a group by name; create it if absent. Returns the group ID.
	 * Used during flag sync to resolve a group name passed to Myrk::register().
	 *
	 * @param string $name Group name.
	 * @return int
	 */
	public static function get_or_create_by_name( string $name ): int {
		$existing = self::get_by_name( $name );
		if ( null !== $existing ) {
			return (int) $existing->id;
		}

		$id = self::create( [ 'name' => $name ] );
		return $id ?? 0;
	}

	/**
	 * Insert a new group. Returns the new ID, or null on failure.
	 *
	 * @param array $data Fields: name (required), description, external_ref, external_ref_url.
	 * @return int|null
	 */
	public static function create( array $data ): ?int {
		global $wpdb;

		$now    = current_time( 'mysql', true );
		$result = $wpdb->insert(
			$wpdb->prefix . 'myrk_flag_groups',
			[
				'name'             => $data['name'],
				'description'      => $data['description'] ?? '',
				'external_ref'     => $data['external_ref'] ?? null,
				'external_ref_url' => $data['external_ref_url'] ?? null,
				'created_at'       => $now,
				'updated_at'       => $now,
			]
		);

		if ( false === $result || 0 === $wpdb->insert_id ) {
			return null;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update mutable fields on a group. Returns true on success.
	 *
	 * @param int   $id      Group DB primary key.
	 * @param array $changes Fields to update: name, description, external_ref, external_ref_url.
	 * @return bool
	 */
	public static function update( int $id, array $changes ): bool {
		global $wpdb;

		$allowed = [ 'name', 'description', 'external_ref', 'external_ref_url' ];
		$data    = array_intersect_key( $changes, array_flip( $allowed ) );

		if ( empty( $data ) ) {
			return false;
		}

		$data['updated_at'] = current_time( 'mysql', true );

		$result = $wpdb->update(
			$wpdb->prefix . 'myrk_flag_groups',
			$data,
			[ 'id' => $id ]
		);

		return false !== $result;
	}

	/**
	 * Delete a group. Flags in the group have their group_id set to NULL
	 * (application-level, since dbDelta does not support FK CASCADE).
	 *
	 * @param int $id Group DB primary key.
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'myrk_flags',
			[ 'group_id' => null ],
			[ 'group_id' => $id ]
		);

		$result = $wpdb->delete(
			$wpdb->prefix . 'myrk_flag_groups',
			[ 'id' => $id ]
		);

		return false !== $result;
	}
}
