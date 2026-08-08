<?php
/**
 * Read-only database queries for feature flags.
 *
 * @package Myrk
 */

declare( strict_types=1 );

namespace Myrk\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central read repository for flag definitions, environment state, and targeting rules.
 */
class FlagRepository {

	private const CACHE_GROUP = 'myrk';
	private const CACHE_TTL   = 5 * MINUTE_IN_SECONDS;

	// -------------------------------------------------------------------------
	// Evaluation path — cached, minimal columns
	// -------------------------------------------------------------------------

	/**
	 * Return the environment state row needed for flag evaluation.
	 * Cached in the WordPress object cache; invalidated by StateWriter on change.
	 *
	 * @param string $flag_key   Flag key to look up.
	 * @param string $environment Target environment.
	 * @return object|null
	 */
	public static function get_environment_state( string $flag_key, string $environment ): ?object {
		$cache_key = "env:{$flag_key}:{$environment}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached ? $cached : null;
		}

		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT fle.id, fle.status, fle.rollout_percentage, fle.anonymous_strategy,
				        fle.circuit_breaker_count, fle.circuit_breaker_tripped, fle.updated_at
				 FROM {$wpdb->prefix}myrk_flag_environments fle
				 INNER JOIN {$wpdb->prefix}myrk_flags f ON f.id = fle.flag_id
				 WHERE f.flag_key = %s
				   AND fle.environment = %s
				 LIMIT 1",
				$flag_key,
				$environment
			)
		);

		wp_cache_set( $cache_key, $row ? $row : 0, self::CACHE_GROUP, self::CACHE_TTL );

		return $row ? $row : null;
	}

	/**
	 * Return enabled targeting rules for a flag+environment, ordered by sort_order.
	 * Used during flag evaluation — returns only enabled rules.
	 *
	 * @param string $flag_key   Flag key.
	 * @param string $environment Target environment.
	 * @return list<object>
	 */
	public static function get_targets( string $flag_key, string $environment ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ft.type, ft.operator, ft.value
				 FROM {$wpdb->prefix}myrk_flag_targets ft
				 INNER JOIN {$wpdb->prefix}myrk_flags f ON f.id = ft.flag_id
				 WHERE f.flag_key = %s
				   AND ft.environment = %s
				   AND ft.enabled = 1
				 ORDER BY ft.sort_order ASC",
				$flag_key,
				$environment
			)
		);

		return is_array( $rows ) ? $rows : [];
	}

	// -------------------------------------------------------------------------
	// REST API path — uncached, full columns
	// -------------------------------------------------------------------------

	/**
	 * Return flags with their state for a given environment, for REST list endpoint.
	 * Uses LEFT JOIN so flags without an env row are included (env columns are NULL).
	 *
	 * @param string $environment Target environment name.
	 * @param array  $args        Optional filters: status, stale, per_page, offset.
	 * @return list<object>
	 */
	public static function get_all_with_env_state( string $environment, array $args = [] ): array {
		global $wpdb;

		$per_page = max( 1, (int) ( $args['per_page'] ?? 100 ) );
		$offset   = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$env_join = isset( $args['status'] ) ? 'INNER JOIN' : 'LEFT JOIN'; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- value is a hardcoded SQL keyword, never user input.

		[ $where, $where_args ] = self::build_where( $args );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT f.id, f.flag_key, f.label, f.description, f.default_state,
				        f.rewind_strategy, f.lifecycle, f.group_id, f.tags,
				        f.is_registered, f.created_at, f.updated_at,
				        fg.name AS group_name, fg.external_ref, fg.external_ref_url,
				        fle.id AS env_id, fle.status, fle.rollout_percentage,
				        fle.anonymous_strategy, fle.circuit_breaker_tripped,
				        fle.updated_at AS env_updated_at
				 FROM {$wpdb->prefix}myrk_flags f
				 {$env_join} {$wpdb->prefix}myrk_flag_environments fle
				       ON fle.flag_id = f.id AND fle.environment = %s
				 LEFT JOIN {$wpdb->prefix}myrk_flag_groups fg ON fg.id = f.group_id
				 {$where}
				 ORDER BY f.flag_key ASC
				 LIMIT %d OFFSET %d",
				array_merge( [ $environment ], $where_args, [ $per_page, $offset ] )
			)
		);
		// phpcs:enable

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Return total flag count matching optional status filter, for X-WP-Total header.
	 *
	 * @param array $args Optional filter: status, environment.
	 * @return int
	 */
	public static function get_total_count( array $args = [] ): int {
		global $wpdb;

		if ( isset( $args['status'] ) ) {
			$status = 'enabled' === $args['status'] ? 1 : 0;
			$env    = $args['environment'] ?? wp_get_environment_type();
			$count  = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT f.id)
					 FROM {$wpdb->prefix}myrk_flags f
					 INNER JOIN {$wpdb->prefix}myrk_flag_environments fle
					       ON fle.flag_id = f.id AND fle.environment = %s
					 WHERE fle.status = %d",
					$env,
					$status
				)
			);
		} else {
			$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}myrk_flags" );
		}

		return (int) $count;
	}

	/**
	 * Return all targets for an environment, keyed by flag_id.
	 * Used to load targets for an entire flag list in a single query.
	 *
	 * @param string $environment Target environment name.
	 * @return array<int, list<object>>
	 */
	public static function get_all_targets_for_env( string $environment ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ft.id, ft.flag_id, ft.type, ft.operator, ft.value, ft.enabled, ft.sort_order
				 FROM {$wpdb->prefix}myrk_flag_targets ft
				 WHERE ft.environment = %s
				 ORDER BY ft.flag_id ASC, ft.sort_order ASC",
				$environment
			)
		);

		$grouped = [];
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$grouped[ (int) $row->flag_id ][] = $row;
			}
		}

		return $grouped;
	}

	/**
	 * Return full flag definition with ALL environment states.
	 * Used by GET /flags/{key}.
	 *
	 * @param string $flag_key The flag key to look up.
	 * @return array{flag: object, environments: list<object>}|null
	 */
	public static function get_with_all_environments( string $flag_key ): ?array {
		global $wpdb;

		$flag = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}myrk_flags WHERE flag_key = %s",
				$flag_key
			)
		);

		if ( null === $flag ) {
			return null;
		}

		$environments = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT environment, status, rollout_percentage, anonymous_strategy,
				        circuit_breaker_tripped, updated_at
				 FROM {$wpdb->prefix}myrk_flag_environments
				 WHERE flag_id = %d",
				(int) $flag->id
			)
		);

		return [
			'flag'         => $flag,
			'environments' => is_array( $environments ) ? $environments : [],
		];
	}

	/**
	 * Return all targets for a specific flag (all environments or one).
	 *
	 * @param int         $flag_id     DB row ID of the flag.
	 * @param string|null $environment Filter by environment, or null for all environments.
	 * @return list<object>
	 */
	public static function get_flag_targets( int $flag_id, ?string $environment = null ): array {
		global $wpdb;

		if ( null !== $environment ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}myrk_flag_targets
					 WHERE flag_id = %d AND environment = %s
					 ORDER BY sort_order ASC",
					$flag_id,
					$environment
				)
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}myrk_flag_targets
					 WHERE flag_id = %d
					 ORDER BY environment ASC, sort_order ASC",
					$flag_id
				)
			);
		}

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Return a single target by ID.
	 *
	 * @param int $target_id DB row ID of the target.
	 * @return object|null
	 */
	public static function get_target_by_id( int $target_id ): ?object {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}myrk_flag_targets WHERE id = %d",
				$target_id
			)
		);

		return $row ? $row : null;
	}

	/**
	 * Return the raw flag row by key, or null when not found.
	 *
	 * @param string $flag_key The flag key to look up.
	 * @return object|null
	 */
	public static function get_by_key( string $flag_key ): ?object {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}myrk_flags WHERE flag_key = %s",
				$flag_key
			)
		);

		return $row ? $row : null;
	}

	// -------------------------------------------------------------------------
	// Shared helpers
	// -------------------------------------------------------------------------

	/**
	 * Return the numeric flag ID, or null when the flag has no DB record.
	 *
	 * @param string $flag_key The flag key to look up.
	 * @return int|null
	 */
	public static function get_flag_id( string $flag_key ): ?int {
		global $wpdb;

		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}myrk_flags WHERE flag_key = %s",
				$flag_key
			)
		);

		return null !== $id ? (int) $id : null;
	}

	/**
	 * Return the flag ID, creating the flags row from the Registry if absent.
	 * Allows state writes to proceed even before an explicit `wp myrk flags init`.
	 *
	 * @param string $flag_key The flag key.
	 * @return int|null
	 */
	public static function get_or_create_flag_id( string $flag_key ): ?int {
		$id = self::get_flag_id( $flag_key );
		if ( null !== $id ) {
			return $id;
		}

		$flag = \Myrk\Registry::get( $flag_key );
		if ( null === $flag ) {
			return null;
		}

		global $wpdb;
		$now = current_time( 'mysql', true );

		$wpdb->insert(
			$wpdb->prefix . 'myrk_flags',
			[
				'flag_key'        => $flag->flag_key,
				'label'           => $flag->label,
				'description'     => $flag->description,
				'default_state'   => (int) $flag->fallback,
				'rewind_strategy' => $flag->rewind_strategy->value,
				'lifecycle'       => $flag->lifecycle,
				'tags'            => empty( $flag->tags ) ? null : implode( ',', $flag->tags ),
				'is_registered'   => 1,
				'created_at'      => $now,
				'updated_at'      => $now,
			]
		);

		return $wpdb->insert_id > 0 ? (int) $wpdb->insert_id : null;
	}

	/**
	 * Delete the object-cache entry for a specific flag + environment combination.
	 *
	 * @param string $flag_key   The flag key.
	 * @param string $environment The environment name.
	 */
	public static function invalidate_cache( string $flag_key, string $environment ): void {
		wp_cache_delete( "env:{$flag_key}:{$environment}", self::CACHE_GROUP );
	}

	// -------------------------------------------------------------------------
	// Internal helpers.
	// -------------------------------------------------------------------------

	/**
	 * Build a WHERE clause and ordered prepare-args array from $args filters.
	 * Returns [ $where_sql, $args_array ] where args are ordered to match placeholders.
	 *
	 * Supported keys: status ('enabled'|'disabled'), group (name string), tag (string).
	 *
	 * @param array $args Filter arguments.
	 * @return array{ 0: string, 1: list<mixed> }
	 */
	private static function build_where( array $args ): array {
		$conditions = [];
		$prepare    = [];

		if ( 'enabled' === ( $args['status'] ?? null ) ) {
			$conditions[] = 'fle.status = 1';
		} elseif ( 'disabled' === ( $args['status'] ?? null ) ) {
			$conditions[] = 'fle.status = 0';
		}

		if ( ! empty( $args['group'] ) ) {
			$conditions[] = 'fg.name = %s';
			$prepare[]    = $args['group'];
		}

		if ( ! empty( $args['tag'] ) ) {
			$conditions[] = 'FIND_IN_SET( %s, f.tags ) > 0';
			$prepare[]    = $args['tag'];
		}

		$where = empty( $conditions ) ? '' : 'WHERE ' . implode( ' AND ', $conditions );

		return [ $where, $prepare ];
	}
}
