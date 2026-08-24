<?php
/**
 * Database migration for schema version 1.0.0.
 *
 * @package   Myrk
 * @author    Bjorn Holine <bjorn@myrk.build>
 * @license   GPL-2.0-or-later
 * @link      https://myrk.build/
 * @copyright 2026 Bjorn Holine
 */

declare( strict_types=1 );

namespace Myrk\Database\Migrations;

/**
 * Creates all six core database tables introduced in version 1.0.0.
 */
class Migration100 {

	/**
	 * Create all six core tables for the 1.0.0 schema.
	 *
	 * Uses dbDelta() for idempotent table creation. Foreign key constraints are
	 * not used because dbDelta() does not support them — application-level cascade
	 * deletes handle referential integrity.
	 *
	 * Note on dbDelta() formatting requirements:
	 *  - Two spaces between PRIMARY KEY and the column list.
	 *  - Each column definition on its own line.
	 *  - No trailing comma after the last column.
	 */
	public static function run(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Groups must be created before flags (flags.group_id references groups).
		dbDelta( self::flag_groups_table_sql( $wpdb->prefix, $charset_collate ) );
		dbDelta( self::flags_table_sql( $wpdb->prefix, $charset_collate ) );
		dbDelta( self::flag_environments_table_sql( $wpdb->prefix, $charset_collate ) );
		dbDelta( self::flag_targets_table_sql( $wpdb->prefix, $charset_collate ) );
		dbDelta( self::state_log_table_sql( $wpdb->prefix, $charset_collate ) );
		dbDelta( self::fatal_log_table_sql( $wpdb->prefix, $charset_collate ) );
	}

	/**
	 * Return the CREATE TABLE SQL for the flag groups table.
	 *
	 * Groups organize related flags by sprint, release, or initiative. Each group
	 * can carry an optional external_ref (e.g. a Jira key) and clickable URL
	 * linking out to the authoritative PM system — Myrk deliberately does not
	 * replicate project management, only links to it.
	 *
	 * @param string $prefix          WordPress table prefix.
	 * @param string $charset_collate Database charset and collation string.
	 * @return string
	 */
	private static function flag_groups_table_sql( string $prefix, string $charset_collate ): string {
		return "CREATE TABLE {$prefix}myrk_flag_groups (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  name varchar(255) NOT NULL,
  description text,
  external_ref varchar(255) DEFAULT NULL,
  external_ref_url varchar(500) DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id)
) {$charset_collate};";
	}

	/**
	 * Return the CREATE TABLE SQL for the flags table.
	 *
	 * @param string $prefix          WordPress table prefix.
	 * @param string $charset_collate Database charset and collation string.
	 * @return string
	 */
	private static function flags_table_sql( string $prefix, string $charset_collate ): string {
		return "CREATE TABLE {$prefix}myrk_flags (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  flag_key varchar(100) NOT NULL,
  label varchar(255) NOT NULL,
  description text,
  default_state tinyint(1) NOT NULL DEFAULT 0,
  rewind_strategy varchar(20) NOT NULL DEFAULT 'stepwise',
  lifecycle varchar(20) NOT NULL DEFAULT 'temporary',
  group_id bigint(20) DEFAULT NULL,
  tags varchar(500) DEFAULT NULL,
  stale_threshold_days smallint(5) DEFAULT NULL,
  last_evaluated_at datetime DEFAULT NULL,
  is_registered tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY flag_key (flag_key),
  KEY is_registered (is_registered),
  KEY group_id (group_id)
) {$charset_collate};";
	}

	/**
	 * Return the CREATE TABLE SQL for the flag environments table.
	 *
	 * @param string $prefix          WordPress table prefix.
	 * @param string $charset_collate Database charset and collation string.
	 * @return string
	 */
	private static function flag_environments_table_sql( string $prefix, string $charset_collate ): string {
		return "CREATE TABLE {$prefix}myrk_flag_environments (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  flag_id bigint(20) NOT NULL,
  environment varchar(50) NOT NULL,
  status tinyint(1) NOT NULL DEFAULT 0,
  rollout_percentage tinyint(3) NOT NULL DEFAULT 0,
  anonymous_strategy varchar(20) NOT NULL DEFAULT 'ip',
  circuit_breaker_count tinyint(3) NOT NULL DEFAULT 0,
  circuit_breaker_tripped tinyint(1) NOT NULL DEFAULT 0,
  locked_until datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY flag_env (flag_id, environment),
  KEY status (status),
  KEY environment (environment)
) {$charset_collate};";
	}

	/**
	 * Return the CREATE TABLE SQL for the flag targets table.
	 *
	 * @param string $prefix          WordPress table prefix.
	 * @param string $charset_collate Database charset and collation string.
	 * @return string
	 */
	private static function flag_targets_table_sql( string $prefix, string $charset_collate ): string {
		return "CREATE TABLE {$prefix}myrk_flag_targets (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  flag_id bigint(20) NOT NULL,
  environment varchar(50) NOT NULL,
  type varchar(50) NOT NULL,
  operator varchar(20) NOT NULL DEFAULT 'equals',
  value text NOT NULL,
  enabled tinyint(1) NOT NULL DEFAULT 1,
  sort_order tinyint(3) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY flag_env (flag_id, environment)
) {$charset_collate};";
	}

	/**
	 * Return the CREATE TABLE SQL for the state log table.
	 *
	 * @param string $prefix          WordPress table prefix.
	 * @param string $charset_collate Database charset and collation string.
	 * @return string
	 */
	private static function state_log_table_sql( string $prefix, string $charset_collate ): string {
		// No foreign key on flag_id — historical records survive flag deletion.
		// flag_key is stored denormalized for human-readable history.
		return "CREATE TABLE {$prefix}myrk_state_log (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  flag_id bigint(20) NOT NULL,
  flag_key varchar(100) NOT NULL,
  environment varchar(50) NOT NULL,
  changed_by bigint(20) DEFAULT NULL,
  changed_via varchar(20) NOT NULL,
  previous_state longtext,
  new_state longtext,
  git_sha varchar(40) DEFAULT NULL,
  note text,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY flag_id (flag_id),
  KEY environment (environment),
  KEY created_at (created_at),
  KEY changed_via (changed_via)
) {$charset_collate};";
	}

	/**
	 * Return the CREATE TABLE SQL for the fatal log table.
	 *
	 * @param string $prefix          WordPress table prefix.
	 * @param string $charset_collate Database charset and collation string.
	 * @return string
	 */
	private static function fatal_log_table_sql( string $prefix, string $charset_collate ): string {
		return "CREATE TABLE {$prefix}myrk_fatal_log (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  flag_id bigint(20) NOT NULL,
  flag_key varchar(100) NOT NULL,
  environment varchar(50) NOT NULL,
  error_type varchar(50) NOT NULL,
  error_message text NOT NULL,
  error_file varchar(255) DEFAULT NULL,
  error_line int(11) DEFAULT NULL,
  flag_state longtext,
  resolved tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY flag_id (flag_id),
  KEY environment (environment),
  KEY resolved (resolved),
  KEY created_at (created_at)
) {$charset_collate};";
	}
}
