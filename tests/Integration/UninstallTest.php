<?php
/**
 * Integration test for the plugin uninstall routine.
 *
 * @package Myrk\Tests\Integration
 */

declare( strict_types=1 );

namespace Myrk\Tests\Integration;

use Myrk\Database\GroupRepository;
use Myrk\Database\FlagWriter;
use Myrk\Database\Schema;
use WP_UnitTestCase;

/**
 * Verifies that uninstall.php drops all Myrk tables and cleans up options.
 *
 * WP_UnitTestCase wraps each test in a MySQL transaction and rolls it back in
 * tear_down(). DDL (DROP/CREATE TABLE) always causes an implicit commit in
 * MySQL, so the transaction-based rollback cannot restore tables. We therefore
 * disable the transaction wrapper for this test class and handle cleanup
 * manually through Schema::install() in tear_down().
 */
class UninstallTest extends WP_UnitTestCase {

	/** @var list<string> */
	private array $tables = [];

	// Disable the per-test transaction so DDL from uninstall.php commits cleanly.
	public function start_transaction(): void {}

	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		$this->tables = [
			$wpdb->prefix . 'myrk_fatal_log',
			$wpdb->prefix . 'myrk_state_log',
			$wpdb->prefix . 'myrk_flag_targets',
			$wpdb->prefix . 'myrk_flag_environments',
			$wpdb->prefix . 'myrk_flags',
			$wpdb->prefix . 'myrk_flag_groups',
		];

		// Ensure a clean, fully-installed schema before every test.
		Schema::install();
	}

	public function tear_down(): void {
		// Re-create schema so subsequent tests find all tables intact.
		Schema::install();
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Execute uninstall.php with WP_UNINSTALL_PLUGIN defined.
	 */
	private function run_uninstall(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'myrk/myrk.php' );
		}

		ob_start();
		require dirname( __DIR__, 2 ) . '/uninstall.php'; // phpcs:ignore WPThemeReview.CoreFunctionality.FileInclude.FileIncludeFound
		ob_end_clean();
	}

	private function table_exists( string $table ): bool {
		global $wpdb;

		return $table === $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);
	}

	// -------------------------------------------------------------------------
	// Tests
	// -------------------------------------------------------------------------

	public function test_uninstall_drops_all_six_myrk_tables(): void {
		// Seed rows so the tables are populated before uninstall.
		GroupRepository::create( [ 'name' => 'Seed Group' ] );
		FlagWriter::create( [ 'flag_key' => 'seed_flag', 'label' => 'Seed' ] );

		$this->run_uninstall();

		foreach ( $this->tables as $table ) {
			$this->assertFalse(
				$this->table_exists( $table ),
				"Table {$table} should not exist after uninstall."
			);
		}
	}

	public function test_uninstall_removes_myrk_db_version_option(): void {
		update_option( 'myrk_db_version', '1.0.0' );

		$this->run_uninstall();

		$this->assertFalse( get_option( 'myrk_db_version' ) );
	}

	public function test_uninstall_removes_rate_limit_transients(): void {
		global $wpdb;

		// Simulate a rate-limit transient written by the circuit breaker.
		set_transient( 'myrk_rl_checkout_v2', 5, 300 );

		$this->run_uninstall();

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_myrk_rl_' ) . '%'
			)
		);

		$this->assertSame( 0, $count, 'Rate-limit transients should be deleted by uninstall.' );
	}

	public function test_uninstall_is_safe_when_tables_do_not_exist(): void {
		global $wpdb;

		// Drop all tables to simulate a partially uninstalled state.
		foreach ( array_reverse( $this->tables ) as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		// Should complete without errors.
		$this->run_uninstall();

		foreach ( $this->tables as $table ) {
			$this->assertFalse( $this->table_exists( $table ) );
		}
	}
}
