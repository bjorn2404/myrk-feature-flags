<?php
/**
 * Circuit breaker for feature flag callbacks.
 *
 * @package Myrk
 */

declare( strict_types=1 );

namespace Myrk;

use Myrk\Database\FlagRepository;
use Myrk\Database\StateWriter;
use Throwable;

/**
 * Tracks failures for a flag and trips after a configurable threshold.
 */
class CircuitBreaker {

	public const DEFAULT_THRESHOLD = 3;

	/**
	 * Execute $callback for the flag. On exception, record the failure and
	 * invoke $fallback. When failure count reaches $threshold the circuit trips:
	 * subsequent calls skip $callback and return $fallback immediately.
	 *
	 * @param string   $flag_key  Flag key used to track and trip the circuit.
	 * @param callable $callback  Callable to attempt.
	 * @param callable $fallback  Callable invoked on failure or open circuit.
	 * @param int      $threshold Number of failures before the circuit trips.
	 * @return mixed
	 */
	public static function attempt(
		string $flag_key,
		callable $callback,
		callable $fallback,
		int $threshold = self::DEFAULT_THRESHOLD
	): mixed {
		if ( self::is_tripped( $flag_key ) ) {
			return $fallback();
		}

		try {
			$result = $callback();
			return $result;
		} catch ( Throwable $e ) {
			self::record_failure( $flag_key, $e, $threshold );
			return $fallback();
		}
	}

	/**
	 * Return true when the circuit breaker for the flag is currently tripped.
	 *
	 * @param string $flag_key The flag to check.
	 * @return bool
	 */
	public static function is_tripped( string $flag_key ): bool {
		$environment = wp_get_environment_type();
		$state       = FlagRepository::get_environment_state( $flag_key, $environment );

		return null !== $state && 1 === (int) $state->circuit_breaker_tripped;
	}

	/**
	 * Increment the failure counter. If $threshold is reached, trip the circuit
	 * and write a fatal log entry.
	 *
	 * @param string    $flag_key  Flag key to record the failure against.
	 * @param Throwable $e         The exception that caused the failure.
	 * @param int       $threshold Failure count required to trip the circuit.
	 */
	public static function record_failure(
		string $flag_key,
		Throwable $e,
		int $threshold = self::DEFAULT_THRESHOLD
	): void {
		$environment = wp_get_environment_type();
		$state       = FlagRepository::get_environment_state( $flag_key, $environment );
		$count       = null !== $state ? (int) $state->circuit_breaker_count + 1 : 1;
		$tripped     = $count >= $threshold ? 1 : 0;

		$flag_id = FlagRepository::get_or_create_flag_id( $flag_key );
		if ( null === $flag_id ) {
			return;
		}

		// Update circuit breaker columns directly — does not go through StateWriter
		// to avoid a state log entry for every individual failure tick.
		if ( null !== $state ) {
			global $wpdb;
			$wpdb->update(
				$wpdb->prefix . 'myrk_flag_environments',
				[
					'circuit_breaker_count'   => $count,
					'circuit_breaker_tripped' => $tripped,
					'updated_at'              => current_time( 'mysql', true ),
				],
				[ 'id' => (int) $state->id ]
			);
			FlagRepository::invalidate_cache( $flag_key, $environment );
		}

		self::write_fatal_log( $flag_id, $flag_key, $environment, $e, $state );

		if ( $tripped ) {
			// Log the trip as a proper state change.
			StateWriter::upsert_environment_state(
				flag_key:     $flag_key,
				environment:  $environment,
				changes:      [ 'circuit_breaker_tripped' => 1 ],
				changed_via:  ChangedVia::CircuitBreaker,
				note:         "Circuit tripped after {$count} failures: " . $e->getMessage()
			);

			/**
			 * Fires when a flag's circuit breaker trips.
			 *
			 * @param string     $flag_key
			 * @param string     $environment
			 * @param Throwable $e
			 */
			do_action( 'myrk_circuit_breaker_tripped', $flag_key, $environment, $e );
		}
	}

	/**
	 * Reset the circuit breaker for the flag, clearing failure count and tripped state.
	 *
	 * @param string $flag_key The flag whose circuit to reset.
	 */
	public static function reset( string $flag_key ): void {
		$environment = wp_get_environment_type();

		StateWriter::upsert_environment_state(
			flag_key:    $flag_key,
			environment: $environment,
			changes:     [
				'circuit_breaker_count'   => 0,
				'circuit_breaker_tripped' => 0,
			],
			changed_via: ChangedVia::Admin,
			note:        'Circuit breaker manually reset.'
		);
	}

	/**
	 * Write a fatal log entry for a circuit-breaker failure.
	 *
	 * @param int         $flag_id     DB row ID of the flag.
	 * @param string      $flag_key    Flag key.
	 * @param string      $environment Environment where the failure occurred.
	 * @param Throwable   $e           The exception that caused the failure.
	 * @param object|null $state      Current environment state row, if any.
	 */
	private static function write_fatal_log(
		int $flag_id,
		string $flag_key,
		string $environment,
		Throwable $e,
		?object $state
	): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'myrk_fatal_log',
			[
				'flag_id'       => $flag_id,
				'flag_key'      => $flag_key,
				'environment'   => $environment,
				'error_type'    => get_class( $e ),
				'error_message' => $e->getMessage(),
				'error_file'    => $e->getFile(),
				'error_line'    => $e->getLine(),
				'flag_state'    => null !== $state ? (string) wp_json_encode( $state ) : null,
				'resolved'      => 0,
				'created_at'    => current_time( 'mysql', true ),
			]
		);
	}
}
