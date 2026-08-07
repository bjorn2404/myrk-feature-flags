<?php
/**
 * Integration tests for CircuitBreaker — requires WordPress DB.
 *
 * @package Myrk\Tests\Integration
 */

declare( strict_types=1 );

namespace Myrk\Tests\Integration;

use Myrk\ChangedVia;
use Myrk\CircuitBreaker;
use Myrk\Database\FlagRepository;
use Myrk\Database\FlagWriter;
use Myrk\Database\StateWriter;
use RuntimeException;
use WP_UnitTestCase;

/**
 * Promotes the three markTestIncomplete unit stubs to real integration tests
 * and adds reset/is_tripped coverage.
 */
class CircuitBreakerIntegrationTest extends WP_UnitTestCase {

	private const ENVIRONMENT = 'local';
	private const FLAG        = 'cb_int_flag';

	public function set_up(): void {
		parent::set_up();

		// Create the flag and an env state row so record_failure() has a row to update.
		FlagWriter::create( [ 'flag_key' => self::FLAG, 'label' => 'CB Integration' ] );
		StateWriter::upsert_environment_state(
			self::FLAG,
			self::ENVIRONMENT,
			[ 'status' => 1, 'rollout_percentage' => 100, 'circuit_breaker_count' => 0, 'circuit_breaker_tripped' => 0 ],
			ChangedVia::Api
		);
	}

	public function tear_down(): void {
		global $wpdb;

		$wpdb->query( "DELETE FROM {$wpdb->prefix}myrk_flag_targets" );     // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}myrk_flag_environments" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}myrk_state_log" );         // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}myrk_fatal_log" );         // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}myrk_flags" );             // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// record_failure() increments failure count
	// -------------------------------------------------------------------------

	public function test_record_failure_increments_failure_count(): void {
		CircuitBreaker::record_failure( self::FLAG, new RuntimeException( 'err' ) );

		$state = FlagRepository::get_environment_state( self::FLAG, self::ENVIRONMENT );
		$this->assertSame( 1, (int) $state->circuit_breaker_count );
		$this->assertSame( 0, (int) $state->circuit_breaker_tripped );
	}

	public function test_record_failure_trips_circuit_at_threshold(): void {
		$threshold = CircuitBreaker::DEFAULT_THRESHOLD;

		for ( $i = 0; $i < $threshold; $i++ ) {
			CircuitBreaker::record_failure( self::FLAG, new RuntimeException( 'err' ), $threshold );
		}

		$state = FlagRepository::get_environment_state( self::FLAG, self::ENVIRONMENT );
		$this->assertSame( $threshold, (int) $state->circuit_breaker_count );
		$this->assertSame( 1, (int) $state->circuit_breaker_tripped );
	}

	// -------------------------------------------------------------------------
	// is_tripped() reads from DB
	// -------------------------------------------------------------------------

	public function test_is_tripped_returns_false_when_not_tripped(): void {
		$this->assertFalse( CircuitBreaker::is_tripped( self::FLAG ) );
	}

	public function test_is_tripped_returns_true_after_trip(): void {
		StateWriter::upsert_environment_state(
			self::FLAG,
			self::ENVIRONMENT,
			[ 'circuit_breaker_tripped' => 1 ],
			ChangedVia::Api
		);

		$this->assertTrue( CircuitBreaker::is_tripped( self::FLAG ) );
	}

	// -------------------------------------------------------------------------
	// attempt() returns fallback when circuit is tripped
	// -------------------------------------------------------------------------

	public function test_attempt_returns_fallback_when_tripped(): void {
		StateWriter::upsert_environment_state(
			self::FLAG,
			self::ENVIRONMENT,
			[ 'circuit_breaker_tripped' => 1 ],
			ChangedVia::Api
		);

		$called = false;
		$result = CircuitBreaker::attempt(
			self::FLAG,
			function () use ( &$called ) {
				$called = true;
				return 'callback_value';
			},
			fn() => 'fallback_value'
		);

		$this->assertSame( 'fallback_value', $result );
		$this->assertFalse( $called, 'Callback must not execute when circuit is tripped.' );
	}

	public function test_attempt_executes_callback_when_not_tripped(): void {
		$result = CircuitBreaker::attempt(
			self::FLAG,
			fn() => 'callback_value',
			fn() => 'fallback_value'
		);

		$this->assertSame( 'callback_value', $result );
	}

	public function test_attempt_returns_fallback_and_records_failure_on_exception(): void {
		$result = CircuitBreaker::attempt(
			self::FLAG,
			function () { throw new RuntimeException( 'boom' ); },
			fn() => 'fallback_value'
		);

		$this->assertSame( 'fallback_value', $result );

		$state = FlagRepository::get_environment_state( self::FLAG, self::ENVIRONMENT );
		$this->assertSame( 1, (int) $state->circuit_breaker_count );
	}

	public function test_attempt_trips_circuit_after_threshold_failures(): void {
		$threshold = 2;

		for ( $i = 0; $i < $threshold; $i++ ) {
			CircuitBreaker::attempt(
				self::FLAG,
				function () { throw new RuntimeException( 'boom' ); },
				fn() => 'fallback_value',
				$threshold
			);
		}

		$this->assertTrue( CircuitBreaker::is_tripped( self::FLAG ) );
	}

	// -------------------------------------------------------------------------
	// reset() clears the tripped state
	// -------------------------------------------------------------------------

	public function test_reset_clears_tripped_state(): void {
		StateWriter::upsert_environment_state(
			self::FLAG,
			self::ENVIRONMENT,
			[ 'circuit_breaker_count' => 5, 'circuit_breaker_tripped' => 1 ],
			ChangedVia::Api
		);

		CircuitBreaker::reset( self::FLAG );

		$state = FlagRepository::get_environment_state( self::FLAG, self::ENVIRONMENT );
		$this->assertSame( 0, (int) $state->circuit_breaker_count );
		$this->assertSame( 0, (int) $state->circuit_breaker_tripped );
	}

	public function test_reset_allows_callback_to_run_again(): void {
		StateWriter::upsert_environment_state(
			self::FLAG,
			self::ENVIRONMENT,
			[ 'circuit_breaker_tripped' => 1 ],
			ChangedVia::Api
		);

		CircuitBreaker::reset( self::FLAG );

		$result = CircuitBreaker::attempt(
			self::FLAG,
			fn() => 'callback_value',
			fn() => 'fallback_value'
		);

		$this->assertSame( 'callback_value', $result );
	}
}
