<?php
/**
 * Flag evaluation engine.
 *
 * @package Myrk
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

namespace Myrk;

use Myrk\Database\FlagRepository;
use WP_User;

/**
 * Evaluates feature flags against user context, targeting rules, and rollout percentage.
 */
class Evaluator {

	/**
	 * Evaluate a flag for the given user context.
	 *
	 * When $user is null the current WordPress user is resolved automatically.
	 * Anonymous (not logged-in) users skip targeting rules and go straight to
	 * percentage rollout via IP-based hashing.
	 *
	 * Evaluation order (Section 10):
	 *  1. Circuit breaker tripped → return $fallback immediately.
	 *  2. Targeting rules (sort_order ASC) → first match returns true.
	 *  3. Flag disabled → return $fallback.
	 *  4. rollout_percentage 100 → true; 0 → $fallback.
	 *  5. Hash user/anonymous identifier → true if bucket < percentage.
	 *
	 * @param string       $flag_key The flag to evaluate.
	 * @param WP_User|null $user     User context; null resolves current user.
	 * @param bool         $fallback Fallback value when evaluation cannot proceed.
	 * @return bool
	 */
	public static function evaluate( string $flag_key, ?WP_User $user, bool $fallback ): bool {
		if ( ! Registry::has( $flag_key ) ) {
			return $fallback;
		}

		$environment = wp_get_environment_type();
		$state       = FlagRepository::get_environment_state( $flag_key, $environment );

		if ( null === $state ) {
			return $fallback;
		}

		// 1. Circuit breaker.
		if ( 1 === (int) $state->circuit_breaker_tripped ) {
			return $fallback;
		}

		// Resolve current user when not explicitly supplied.
		if ( null === $user ) {
			$current = wp_get_current_user();
			if ( $current->ID > 0 ) {
				$user = $current;
			}
		}

		// 2. Targeting rules — only evaluated for authenticated users.
		if ( null !== $user ) {
			$targets = FlagRepository::get_targets( $flag_key, $environment );
			foreach ( $targets as $target ) {
				if ( self::evaluate_target( $target, $user ) ) {
					return true;
				}
			}
		}

		// 3. Status.
		if ( 1 !== (int) $state->status ) {
			return $fallback;
		}

		$percentage = (int) $state->rollout_percentage;

		// 4. Percentage short-circuits.
		if ( $percentage >= 100 ) {
			return true;
		}
		if ( $percentage <= 0 ) {
			return $fallback;
		}

		// 5. Deterministic hash.
		$identifier = null !== $user
			? (string) $user->ID
			: self::resolve_anonymous_identifier( $flag_key );

		return self::is_in_percentage_bucket( $flag_key, $identifier, $percentage );
	}

	/**
	 * Hash a flag key and identifier to a deterministic bucket 0–99.
	 * Identical inputs always produce the same bucket — no randomness.
	 *
	 * @param string $flag_key   The flag key.
	 * @param string $identifier User or anonymous identifier.
	 * @return int
	 */
	public static function hash_identifier( string $flag_key, string $identifier ): int {
		return abs( crc32( $flag_key . '|' . $identifier ) ) % 100;
	}

	/**
	 * Return true when the identifier falls inside the rollout percentage bucket.
	 *
	 * @param string $flag_key   The flag key.
	 * @param string $identifier User or anonymous identifier.
	 * @param int    $percentage Rollout percentage (0–100).
	 * @return bool
	 */
	public static function is_in_percentage_bucket(
		string $flag_key,
		string $identifier,
		int $percentage
	): bool {
		return self::hash_identifier( $flag_key, $identifier ) < $percentage;
	}

	/**
	 * Derive an anonymous identifier from the current request IP.
	 * The flag key is included to prevent cross-flag correlation.
	 *
	 * @param string $flag_key The flag key used to salt the hash.
	 * @return string
	 */
	public static function resolve_anonymous_identifier( string $flag_key ): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return md5( $ip . $flag_key );
	}

	/**
	 * Evaluate a single targeting rule against a user.
	 * Returns true when the rule matches (first-match-wins from the caller).
	 *
	 * Supported types (free tier): role, capability, user_id, email_domain.
	 * Operators: equals, not_equals, contains, in_list.
	 *
	 * @param object  $target The targeting rule row.
	 * @param WP_User $user   The user to evaluate the rule against.
	 * @return bool
	 */
	public static function evaluate_target( object $target, WP_User $user ): bool {
		$type     = (string) $target->type;
		$operator = (string) $target->operator;
		$value    = (string) $target->value;

		$result = match ( $type ) {
			'role'         => self::match_role( $operator, $value, $user ),
			'capability'   => self::match_capability( $operator, $value, $user ),
			'user_id'      => self::match_user_id( $operator, $value, $user ),
			'email_domain' => self::match_email_domain( $operator, $value, $user ),
			default        => false,
		};

		/**
		 * Filters the result of a single targeting rule evaluation.
		 *
		 * @param bool     $result
		 * @param object   $target
		 * @param WP_User $user
		 */
		return (bool) apply_filters( 'myrk_evaluate_target', $result, $target, $user );
	}

	// -------------------------------------------------------------------------
	// Private helpers.
	// -------------------------------------------------------------------------

	/**
	 * Evaluate a role-based targeting rule.
	 *
	 * @param string  $operator Comparison operator.
	 * @param string  $value    Role name or comma-separated list.
	 * @param WP_User $user     The user to check.
	 * @return bool
	 */
	private static function match_role( string $operator, string $value, WP_User $user ): bool {
		$user_roles = (array) $user->roles;

		return match ( $operator ) {
			'equals'     => in_array( $value, $user_roles, true ),
			'not_equals' => ! in_array( $value, $user_roles, true ),
			'in_list'    => (bool) array_intersect( $user_roles, self::split_list( $value ) ),
			default      => false,
		};
	}

	/**
	 * Evaluate a capability-based targeting rule.
	 *
	 * @param string  $operator Comparison operator.
	 * @param string  $value    Capability name.
	 * @param WP_User $user     The user to check.
	 * @return bool
	 */
	private static function match_capability( string $operator, string $value, WP_User $user ): bool {
		$has = $user->has_cap( $value );

		return match ( $operator ) {
			'equals'     => $has,
			'not_equals' => ! $has,
			default      => false,
		};
	}

	/**
	 * Evaluate a user-ID-based targeting rule.
	 *
	 * @param string  $operator Comparison operator.
	 * @param string  $value    User ID or comma-separated list.
	 * @param WP_User $user     The user to check.
	 * @return bool
	 */
	private static function match_user_id( string $operator, string $value, WP_User $user ): bool {
		$uid = (string) $user->ID;

		return match ( $operator ) {
			'equals'     => $uid === $value,
			'not_equals' => $uid !== $value,
			'in_list'    => in_array( $uid, self::split_list( $value ), true ),
			default      => false,
		};
	}

	/**
	 * Evaluate an email-domain-based targeting rule.
	 *
	 * @param string  $operator Comparison operator.
	 * @param string  $value    Email domain to match.
	 * @param WP_User $user     The user to check.
	 * @return bool
	 */
	private static function match_email_domain( string $operator, string $value, WP_User $user ): bool {
		$email  = (string) $user->user_email;
		$parts  = explode( '@', $email, 2 );
		$domain = 2 === count( $parts ) ? strtolower( $parts[1] ) : '';

		return match ( $operator ) {
			'equals'     => strtolower( $value ) === $domain,
			'not_equals' => strtolower( $value ) !== $domain,
			'contains'   => str_contains( $domain, strtolower( $value ) ),
			default      => false,
		};
	}

	/**
	 * Split a comma-separated string into a trimmed list of values.
	 *
	 * @param string $value Comma-separated string.
	 * @return list<string>
	 */
	private static function split_list( string $value ): array {
		return array_map( 'trim', explode( ',', $value ) );
	}
}
