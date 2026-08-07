<?php
/**
 * Integration tests for Plugin::sync_registered_flags().
 *
 * @package Myrk\Tests\Integration
 */

declare( strict_types=1 );

namespace Myrk\Tests\Integration;

use Myrk\Database\FlagRepository;
use Myrk\Database\FlagWriter;
use Myrk\Database\GroupRepository;
use Myrk\Flag;
use Myrk\Plugin;
use Myrk\Registry;
use WP_UnitTestCase;

/**
 * Covers the code→DB sync that runs on the init hook: new flags are inserted,
 * existing flags are updated, groups are auto-created, and is_registered is set.
 */
class PluginSyncTest extends WP_UnitTestCase {

	private Plugin $plugin;

	public function set_up(): void {
		parent::set_up();

		$this->plugin = new Plugin();
		Registry::reset();
	}

	public function tear_down(): void {
		global $wpdb;

		$wpdb->query( "DELETE FROM {$wpdb->prefix}myrk_flag_targets" );     // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}myrk_flag_environments" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}myrk_state_log" );         // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}myrk_flags" );             // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}myrk_flag_groups" );       // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		Registry::reset();
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Insert path — flag in Registry but not yet in DB
	// -------------------------------------------------------------------------

	public function test_sync_inserts_new_flag_from_registry(): void {
		Registry::register( 'new_sync_flag', new Flag( 'new_sync_flag', 'New Sync Flag' ) );

		$this->plugin->sync_registered_flags();

		$row = FlagRepository::get_by_key( 'new_sync_flag' );
		$this->assertNotNull( $row );
		$this->assertSame( 'New Sync Flag', $row->label );
		$this->assertSame( '1', $row->is_registered );
	}

	public function test_sync_sets_is_registered_to_one_on_insert(): void {
		Registry::register( 'reg_flag', new Flag( 'reg_flag', 'Reg Flag' ) );

		$this->plugin->sync_registered_flags();

		$row = FlagRepository::get_by_key( 'reg_flag' );
		$this->assertSame( '1', $row->is_registered );
	}

	// -------------------------------------------------------------------------
	// Update path — flag already in DB, Registry overwrites code-owned fields
	// -------------------------------------------------------------------------

	public function test_sync_updates_existing_flag_label(): void {
		FlagWriter::create( [ 'flag_key' => 'existing_flag', 'label' => 'Old Label' ] );
		Registry::register( 'existing_flag', new Flag( 'existing_flag', 'New Label' ) );

		$this->plugin->sync_registered_flags();

		$row = FlagRepository::get_by_key( 'existing_flag' );
		$this->assertSame( 'New Label', $row->label );
	}

	public function test_sync_marks_existing_flag_as_registered(): void {
		FlagWriter::create( [ 'flag_key' => 'was_orphan', 'label' => 'Orphan' ] );

		// Manually set is_registered = 0 to simulate an orphaned flag.
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'myrk_flags', [ 'is_registered' => 0 ], [ 'flag_key' => 'was_orphan' ] );

		Registry::register( 'was_orphan', new Flag( 'was_orphan', 'Orphan' ) );
		$this->plugin->sync_registered_flags();

		$row = FlagRepository::get_by_key( 'was_orphan' );
		$this->assertSame( '1', $row->is_registered );
	}

	// -------------------------------------------------------------------------
	// Group auto-creation
	// -------------------------------------------------------------------------

	public function test_sync_creates_group_when_flag_specifies_one(): void {
		Registry::register(
			'grouped_flag',
			new Flag( 'grouped_flag', 'Grouped Flag', group: 'My Team' )
		);

		$this->plugin->sync_registered_flags();

		$group = GroupRepository::get_by_name( 'My Team' );
		$this->assertNotNull( $group );

		$row = FlagRepository::get_by_key( 'grouped_flag' );
		$this->assertSame( (string) $group->id, (string) $row->group_id );
	}

	public function test_sync_reuses_existing_group_not_create_duplicate(): void {
		GroupRepository::create( [ 'name' => 'Existing Group' ] );
		Registry::register(
			'reuse_group_flag',
			new Flag( 'reuse_group_flag', 'Reuse Group', group: 'Existing Group' )
		);

		$this->plugin->sync_registered_flags();

		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}myrk_flag_groups WHERE name = %s",
				'Existing Group'
			)
		);
		$this->assertSame( 1, $count );
	}

	// -------------------------------------------------------------------------
	// Idempotency
	// -------------------------------------------------------------------------

	public function test_sync_is_idempotent_when_called_twice(): void {
		Registry::register( 'idempotent_flag', new Flag( 'idempotent_flag', 'Idempotent' ) );

		$this->plugin->sync_registered_flags();
		$this->plugin->sync_registered_flags();

		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}myrk_flags WHERE flag_key = %s",
				'idempotent_flag'
			)
		);
		$this->assertSame( 1, $count );
	}

	// -------------------------------------------------------------------------
	// Empty registry — no-op
	// -------------------------------------------------------------------------

	public function test_sync_does_nothing_when_registry_is_empty(): void {
		// Registry is reset in set_up — nothing registered.
		$this->plugin->sync_registered_flags();

		global $wpdb;
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}myrk_flags" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( 0, $count );
	}
}
