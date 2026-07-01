<?php
/**
 * Public API class for the Myrk feature flag system.
 *
 * @package Myrk
 */

declare( strict_types=1 );

namespace Myrk;

use Myrk\Database\StateWriter;

/**
 * Primary developer-facing API for registering and evaluating feature flags.
 */
class Myrk {

	/**
	 * Register a flag definition in the in-memory Registry.
	 * The Registry is synced to wp_myrk_flags on the init hook by Plugin::boot().
	 *
	 * @param string $flag_key Unique flag identifier.
	 * @param array  $args     Optional flag configuration (label, description, default, rewind_strategy, anonymous).
	 */
	public static function register( string $flag_key, array $args = [] ): void {
		$flag = new Flag(
			flag_key:           $flag_key,
			label:              $args['label'] ?? $flag_key,
			description:        $args['description'] ?? '',
			fallback:           $args['default'] ?? false,
			rewind_strategy:    RewindStrategy::from( $args['rewind_strategy'] ?? 'stepwise' ),
			anonymous_strategy: $args['anonymous'] ?? 'ip',
			lifecycle:          $args['lifecycle'] ?? 'temporary',
			group:              $args['group'] ?? null,
			tags:               $args['tags'] ?? [],
		);

		Registry::register( $flag_key, $flag );
	}

	/**
	 * Return true when the flag is enabled for the given user context.
	 *
	 * @param string        $flag_key Unique flag identifier.
	 * @param \WP_User|null $user     User to evaluate against; null resolves current user.
	 * @param bool          $fallback Fallback when no flag state exists.
	 * @return bool
	 */
	public static function is_enabled( string $flag_key, ?\WP_User $user = null, bool $fallback = false ): bool {
		return Evaluator::evaluate( $flag_key, $user, $fallback );
	}

	/**
	 * Execute a callback guarded by the circuit breaker for the flag.
	 *
	 * @param string   $flag_key Flag key used to track failures.
	 * @param callable $callback Callable to attempt.
	 * @param callable $fallback Callable invoked on failure or when circuit is open.
	 * @return mixed
	 */
	public static function attempt( string $flag_key, callable $callback, callable $fallback ): mixed {
		return CircuitBreaker::attempt( $flag_key, $callback, $fallback );
	}

	/**
	 * Enable the flag for the current environment. Creates the environment row if absent.
	 * Also accepts an optional $percentage so you can enable at a partial rollout in one call.
	 *
	 * @param string $flag_key   The flag to enable.
	 * @param int    $percentage Rollout percentage (0–100).
	 */
	public static function enable( string $flag_key, int $percentage = 100 ): void {
		$environment = wp_get_environment_type();
		$changes     = [
			'status'             => 1,
			'rollout_percentage' => max( 0, min( 100, $percentage ) ),
		];

		$uid = get_current_user_id();
		StateWriter::upsert_environment_state(
			flag_key:    $flag_key,
			environment: $environment,
			changes:     $changes,
			changed_via: ChangedVia::Admin,
			changed_by:  $uid ? $uid : null
		);

		do_action( 'myrk_flag_enabled', $flag_key, $environment, $percentage );
	}

	/**
	 * Disable the flag for the current environment.
	 *
	 * @param string $flag_key The flag to disable.
	 */
	public static function disable( string $flag_key ): void {
		$environment = wp_get_environment_type();

		$uid = get_current_user_id();
		StateWriter::upsert_environment_state(
			flag_key:    $flag_key,
			environment: $environment,
			changes:     [
				'status'             => 0,
				'rollout_percentage' => 0,
			],
			changed_via: ChangedVia::Admin,
			changed_by:  $uid ? $uid : null
		);

		do_action( 'myrk_flag_disabled', $flag_key, $environment );
	}

	/**
	 * Set the rollout percentage without changing the enabled/disabled status.
	 *
	 * @param string $flag_key   The flag to update.
	 * @param int    $percentage Rollout percentage (0–100).
	 */
	public static function set_rollout( string $flag_key, int $percentage ): void {
		$environment = wp_get_environment_type();
		$clamped     = max( 0, min( 100, $percentage ) );

		$uid = get_current_user_id();
		StateWriter::upsert_environment_state(
			flag_key:    $flag_key,
			environment: $environment,
			changes:     [ 'rollout_percentage' => $clamped ],
			changed_via: ChangedVia::Admin,
			changed_by:  $uid ? $uid : null
		);

		do_action( 'myrk_flag_rollout_changed', $flag_key, $environment, $clamped );
	}

	/**
	 * Return a deterministic anonymous identifier for the current request IP.
	 * Suitable for passing to JS bootstrap for consistent client-side experience.
	 */
	public static function get_anonymous_id(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return md5( $ip );
	}

	/**
	 * Return all evaluated flag states for the current environment.
	 * Used to bootstrap flag data into the page for JS consumption.
	 *
	 * @return array<string, bool>
	 */
	public static function bootstrap(): array {
		$result = [];
		foreach ( Registry::all() as $flag_key => $flag ) {
			$result[ $flag_key ] = Evaluator::evaluate( $flag_key, null, $flag->fallback );
		}
		return $result;
	}

	/**
	 * Request a flag rewind (Pro only; no-op in the free tier).
	 *
	 * @param string $flag_key The flag to rewind.
	 */
	public static function rewind( string $flag_key ): void {
		// Pro only — no-op in free tier.
		do_action( 'myrk_rewind_requested', $flag_key );
	}
}
