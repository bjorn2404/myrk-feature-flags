<?php
/**
 * Integration tests for the Myrk public PHP API.
 *
 * @package Myrk\Tests\Integration
 */

declare( strict_types=1 );

namespace Myrk\Tests\Integration;

use Myrk\ChangedVia;
use Myrk\Database\FlagRepository;
use Myrk\Database\FlagWriter;
use Myrk\Database\StateWriter;
use Myrk\Evaluator;
use Myrk\Flag;
use Myrk\Myrk;
use Myrk\Registry;
use WP_UnitTestCase;
use WP_User;

/**
 * Covers Myrk::enable(), disable(), set_rollout(), and bootstrap() — the
 * methods in Myrk.php that are not thin proxies to already-tested classes.
 */
class MurkApiTest extends WP_UnitTestCase {

	private const ENVIRONMENT = 'local';

	public function set_up(): void {
		parent::set_up();
		Registry::reset();
	}

	public function tear_down(): void {
		global $wpdb;

		$wpdb->query( "DELETE FROM {$wpdb->prefix}myrk_flag_targets" );     // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}myrk_flag_environments" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}myrk_state_log" );         // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}myrk_flags" );             // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		Registry::reset();
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function make_flag( string $key, int $status = 1, int $rollout = 100 ): void {
		FlagWriter::create( [ 'flag_key' => $key, 'label' => $key ] );
		StateWriter::upsert_environment_state(
			$key,
			self::ENVIRONMENT,
			[ 'status' => $status, 'rollout_percentage' => $rollout ],
			ChangedVia::Api
		);
		Registry::register( $key, new Flag( $key, $key ) );
	}

	// -------------------------------------------------------------------------
	// Myrk::enable()
	// -------------------------------------------------------------------------

	public function test_enable_sets_status_to_enabled(): void {
		$this->make_flag( 'enable_flag', status: 0, rollout: 0 );

		Myrk::enable( 'enable_flag' );

		$state = FlagRepository::get_environment_state( 'enable_flag', self::ENVIRONMENT );
		$this->assertSame( 1, (int) $state->status );
		$this->assertSame( 100, (int) $state->rollout_percentage );
	}

	public function test_enable_with_percentage_sets_partial_rollout(): void {
		$this->make_flag( 'enable_pct_flag', status: 0, rollout: 0 );

		Myrk::enable( 'enable_pct_flag', 50 );

		$state = FlagRepository::get_environment_state( 'enable_pct_flag', self::ENVIRONMENT );
		$this->assertSame( 1, (int) $state->status );
		$this->assertSame( 50, (int) $state->rollout_percentage );
	}

	public function test_enable_fires_myrk_flag_enabled_action(): void {
		$this->make_flag( 'enable_hook_flag', status: 0, rollout: 0 );

		$fired = false;
		add_action(
			'myrk_flag_enabled',
			function ( string $key ) use ( &$fired ) {
				if ( 'enable_hook_flag' === $key ) {
					$fired = true;
				}
			}
		);

		Myrk::enable( 'enable_hook_flag' );

		$this->assertTrue( $fired );
	}

	// -------------------------------------------------------------------------
	// Myrk::disable()
	// -------------------------------------------------------------------------

	public function test_disable_sets_status_to_disabled(): void {
		$this->make_flag( 'disable_flag', status: 1, rollout: 100 );

		Myrk::disable( 'disable_flag' );

		$state = FlagRepository::get_environment_state( 'disable_flag', self::ENVIRONMENT );
		$this->assertSame( 0, (int) $state->status );
		$this->assertSame( 0, (int) $state->rollout_percentage );
	}

	public function test_disable_fires_myrk_flag_disabled_action(): void {
		$this->make_flag( 'disable_hook_flag', status: 1, rollout: 100 );

		$fired = false;
		add_action(
			'myrk_flag_disabled',
			function ( string $key ) use ( &$fired ) {
				if ( 'disable_hook_flag' === $key ) {
					$fired = true;
				}
			}
		);

		Myrk::disable( 'disable_hook_flag' );

		$this->assertTrue( $fired );
	}

	// -------------------------------------------------------------------------
	// Myrk::set_rollout()
	// -------------------------------------------------------------------------

	public function test_set_rollout_updates_percentage_without_changing_status(): void {
		$this->make_flag( 'rollout_flag', status: 1, rollout: 100 );

		Myrk::set_rollout( 'rollout_flag', 25 );

		$state = FlagRepository::get_environment_state( 'rollout_flag', self::ENVIRONMENT );
		$this->assertSame( 1, (int) $state->status );
		$this->assertSame( 25, (int) $state->rollout_percentage );
	}

	public function test_set_rollout_clamps_above_100(): void {
		$this->make_flag( 'clamp_high_flag', status: 1, rollout: 50 );

		Myrk::set_rollout( 'clamp_high_flag', 150 );

		$state = FlagRepository::get_environment_state( 'clamp_high_flag', self::ENVIRONMENT );
		$this->assertSame( 100, (int) $state->rollout_percentage );
	}

	public function test_set_rollout_clamps_below_0(): void {
		$this->make_flag( 'clamp_low_flag', status: 1, rollout: 50 );

		Myrk::set_rollout( 'clamp_low_flag', -10 );

		$state = FlagRepository::get_environment_state( 'clamp_low_flag', self::ENVIRONMENT );
		$this->assertSame( 0, (int) $state->rollout_percentage );
	}

	public function test_set_rollout_fires_myrk_flag_rollout_changed_action(): void {
		$this->make_flag( 'rollout_hook_flag', status: 1, rollout: 100 );

		$received_pct = null;
		add_action(
			'myrk_flag_rollout_changed',
			function ( string $key, string $env, int $pct ) use ( &$received_pct ) {
				if ( 'rollout_hook_flag' === $key ) {
					$received_pct = $pct;
				}
			},
			10,
			3
		);

		Myrk::set_rollout( 'rollout_hook_flag', 42 );

		$this->assertSame( 42, $received_pct );
	}

	// -------------------------------------------------------------------------
	// Myrk::bootstrap()
	// -------------------------------------------------------------------------

	public function test_bootstrap_returns_evaluated_state_for_all_registered_flags(): void {
		$this->make_flag( 'boot_on', status: 1, rollout: 100 );
		$this->make_flag( 'boot_off', status: 0, rollout: 0 );

		$result = Myrk::bootstrap();

		$this->assertArrayHasKey( 'boot_on', $result );
		$this->assertArrayHasKey( 'boot_off', $result );
		$this->assertTrue( $result['boot_on'] );
		$this->assertFalse( $result['boot_off'] );
	}

	public function test_bootstrap_returns_empty_array_when_registry_is_empty(): void {
		$this->assertSame( [], Myrk::bootstrap() );
	}

	public function test_bootstrap_uses_flag_fallback_for_unset_env_state(): void {
		// Flag registered with fallback=true but no env state row exists.
		Registry::register( 'fallback_flag', new Flag( 'fallback_flag', 'Fallback', fallback: true ) );
		FlagWriter::create( [ 'flag_key' => 'fallback_flag', 'label' => 'Fallback' ] );
		// No StateWriter call — no env row.

		$result = Myrk::bootstrap();

		// No env state → evaluate() returns the flag's fallback value (true).
		$this->assertTrue( $result['fallback_flag'] );
	}
}
