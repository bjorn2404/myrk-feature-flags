<?php
/**
 * Uninstall routine — drops all Myrk tables and options.
 *
 * @package Myrk
 */

declare( strict_types=1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// -------------------------------------------------------------------------
// Tables — free tier + Pro (dropped if present; safe to call on free installs)
// -------------------------------------------------------------------------

$tables = [
	'myrk_scheduled_changes', // Pro — drop first; references myrk_flags.
	'myrk_fatal_log',
	'myrk_state_log',
	'myrk_flag_targets',
	'myrk_flag_environments',
	'myrk_flags',             // Must precede myrk_flag_groups (group_id FK).
	'myrk_flag_groups',
];

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

// -------------------------------------------------------------------------
// Options
// -------------------------------------------------------------------------

$options = [
	'myrk_db_version',
	'myrk_license_key',
	'myrk_license_status',
	'myrk_license_expiry',
	'myrk_license_checked_at',
];

foreach ( $options as $option ) {
	delete_option( $option );
}

// -------------------------------------------------------------------------
// Rate-limit transients — stored as _transient_myrk_rl_* in wp_options;
// delete_transient() requires the exact key so we use a direct query here.
// -------------------------------------------------------------------------

$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_myrk_rl_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_myrk_rl_' ) . '%'
	)
);
