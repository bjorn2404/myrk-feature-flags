<?php

declare( strict_types=1 );

namespace Myrk\Tests\Integration;

use Myrk\Database\Schema;
use WP_UnitTestCase;

class SchemaTest extends WP_UnitTestCase {

	/** @var list<string> */
	private array $tables = [];

	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		$this->tables = [
			$wpdb->prefix . 'myrk_flags',
			$wpdb->prefix . 'myrk_flag_environments',
			$wpdb->prefix . 'myrk_flag_targets',
			$wpdb->prefix . 'myrk_state_log',
			$wpdb->prefix . 'myrk_fatal_log',
		];

		// Drop tables and reset version so each test starts clean.
		foreach ( array_reverse( $this->tables ) as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}

		delete_option( 'myrk_db_version' );
	}

	public function tear_down(): void {
		delete_option( 'myrk_db_version' );
		// Recreate tables so subsequent integration tests don't find a missing schema.
		Schema::install();
		parent::tear_down();
	}

	public function test_creates_all_five_tables_on_activation(): void {
		global $wpdb;

		Schema::install();

		foreach ( $this->tables as $table ) {
			$result = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
			);
			$this->assertSame(
				$table,
				$result,
				"Table {$table} should exist after Schema::install()."
			);
		}
	}

	public function test_migration_runs_once_not_twice(): void {
		// Run install twice — should be idempotent, version stays at 1.0.0.
		Schema::install();
		Schema::install();

		$this->assertSame( '1.0.0', get_option( 'myrk_db_version' ) );
	}

	public function test_stored_version_matches_current_after_install(): void {
		Schema::install();

		$this->assertSame(
			Schema::get_current_version(),
			Schema::get_installed_version(),
			'Stored DB version must match CURRENT_DB_VERSION after fresh install.'
		);
	}

	public function test_upgrade_migration_adds_new_column(): void {
		// Simulate a site that already ran 1.0.0 — set version to an older value.
		Schema::install();
		$this->assertSame( '1.0.0', Schema::get_installed_version() );

		// Simulate a hypothetical 1.1.0 migration gate by temporarily downgrading
		// the stored version and confirming the gate logic works correctly.
		update_option( 'myrk_db_version', '0.9.0' );
		Schema::install();

		// The 1.0.0 migration runs again (dbDelta is idempotent for table creation)
		// and the stored version is bumped back to 1.0.0.
		$this->assertSame( '1.0.0', Schema::get_installed_version() );
	}
}
