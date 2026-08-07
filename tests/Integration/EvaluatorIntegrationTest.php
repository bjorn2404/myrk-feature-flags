<?php
/**
 * Integration tests for Evaluator::evaluate() — targeting rules and evaluation order.
 *
 * @package Myrk\Tests\Integration
 */

declare( strict_types=1 );

namespace Myrk\Tests\Integration;

use Myrk\ChangedVia;
use Myrk\Database\StateWriter;
use Myrk\Database\FlagWriter;
use Myrk\Evaluator;
use Myrk\Flag;
use Myrk\Registry;
use WP_UnitTestCase;
use WP_User;

/**
 * Covers all targeting rule paths and the five-step evaluation order.
 *
 * Every test uses status=0 on the flag's environment row unless the test is
 * specifically about status/percentage fall-through, so a targeting match is the
 * only way evaluate() can return true. This isolates targeting from the rest of
 * the pipeline.
 */
class EvaluatorIntegrationTest extends WP_UnitTestCase {

	private const ENVIRONMENT = 'local';

	// -------------------------------------------------------------------------
	// Set-up / tear-down
	// -------------------------------------------------------------------------

	public function set_up(): void {
		parent::set_up();
		Registry::reset();
	}

	public function tear_down(): void {
		global $wpdb;

		$wpdb->query( "DELETE FROM {$wpdb->prefix}myrk_flag_targets" );      // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}myrk_flag_environments" );  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}myrk_state_log" );          // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}myrk_fatal_log" );          // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}myrk_flags" );              // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		Registry::reset();
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Create a flag DB row, write its environment state, and register it in the
	 * in-memory Registry so Evaluator::evaluate() does not short-circuit on the
	 * Registry::has() guard.
	 *
	 * @param string $key      Flag key (must be unique within the test run).
	 * @param int    $status   Environment status: 1=enabled, 0=disabled.
	 * @param int    $rollout  Rollout percentage (0-100).
	 * @return int             The newly created flag DB row ID.
	 */
	private function make_flag( string $key, int $status = 1, int $rollout = 100 ): int {
		$flag_id = FlagWriter::create( [ 'flag_key' => $key, 'label' => $key ] );
		$this->assertNotNull( $flag_id, "FlagWriter::create() returned null for key '{$key}'." );

		StateWriter::upsert_environment_state(
			$key,
			self::ENVIRONMENT,
			[ 'status' => $status, 'rollout_percentage' => $rollout ],
			ChangedVia::Api
		);

		Registry::register( $key, new Flag( $key, $key ) );

		return (int) $flag_id;
	}

	/**
	 * Insert a targeting rule row directly so we control every column including
	 * enabled and sort_order.
	 *
	 * @param int    $flag_id    Flag DB row ID.
	 * @param string $type       Rule type: role|capability|user_id|email_domain.
	 * @param string $operator   Rule operator: equals|not_equals|in_list|contains.
	 * @param string $value      Rule value.
	 * @param int    $sort_order Lower = evaluated first.
	 * @param int    $enabled    1=active, 0=disabled (skipped by get_targets).
	 */
	private function add_target(
		int $flag_id,
		string $type,
		string $operator,
		string $value,
		int $sort_order = 0,
		int $enabled = 1
	): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'myrk_flag_targets',
			[
				'flag_id'     => $flag_id,
				'environment' => self::ENVIRONMENT,
				'type'        => $type,
				'operator'    => $operator,
				'value'       => $value,
				'enabled'     => $enabled,
				'sort_order'  => $sort_order,
				'created_at'  => current_time( 'mysql', true ),
			]
		);
	}

	// -------------------------------------------------------------------------
	// Role targeting
	// -------------------------------------------------------------------------

	public function test_role_equals_matches_administrator(): void {
		$flag_id = $this->make_flag( 'role_eq', status: 0 );
		$this->add_target( $flag_id, 'role', 'equals', 'administrator' );

		$admin = new WP_User( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertTrue( Evaluator::evaluate( 'role_eq', $admin, false ) );
	}

	public function test_role_equals_does_not_match_subscriber(): void {
		$flag_id = $this->make_flag( 'role_eq_miss', status: 0 );
		$this->add_target( $flag_id, 'role', 'equals', 'administrator' );

		$sub = new WP_User( $this->factory->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertFalse( Evaluator::evaluate( 'role_eq_miss', $sub, false ) );
	}

	public function test_role_not_equals_matches_non_administrator(): void {
		$flag_id = $this->make_flag( 'role_ne', status: 0 );
		$this->add_target( $flag_id, 'role', 'not_equals', 'administrator' );

		$sub = new WP_User( $this->factory->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertTrue( Evaluator::evaluate( 'role_ne', $sub, false ) );
	}

	public function test_role_not_equals_does_not_match_administrator(): void {
		$flag_id = $this->make_flag( 'role_ne_admin', status: 0 );
		$this->add_target( $flag_id, 'role', 'not_equals', 'administrator' );

		$admin = new WP_User( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertFalse( Evaluator::evaluate( 'role_ne_admin', $admin, false ) );
	}

	public function test_role_in_list_matches_when_user_has_listed_role(): void {
		$flag_id = $this->make_flag( 'role_in', status: 0 );
		$this->add_target( $flag_id, 'role', 'in_list', 'editor, administrator' );

		$editor = new WP_User( $this->factory->user->create( [ 'role' => 'editor' ] ) );
		$this->assertTrue( Evaluator::evaluate( 'role_in', $editor, false ) );
	}

	public function test_role_in_list_does_not_match_unlisted_role(): void {
		$flag_id = $this->make_flag( 'role_in_miss', status: 0 );
		$this->add_target( $flag_id, 'role', 'in_list', 'editor, administrator' );

		$sub = new WP_User( $this->factory->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertFalse( Evaluator::evaluate( 'role_in_miss', $sub, false ) );
	}

	// -------------------------------------------------------------------------
	// Capability targeting
	// -------------------------------------------------------------------------

	public function test_capability_equals_matches_user_with_capability(): void {
		$flag_id = $this->make_flag( 'cap_eq', status: 0 );
		$this->add_target( $flag_id, 'capability', 'equals', 'publish_posts' );

		$editor = new WP_User( $this->factory->user->create( [ 'role' => 'editor' ] ) );
		$this->assertTrue( Evaluator::evaluate( 'cap_eq', $editor, false ) );
	}

	public function test_capability_equals_does_not_match_user_without_capability(): void {
		$flag_id = $this->make_flag( 'cap_eq_miss', status: 0 );
		$this->add_target( $flag_id, 'capability', 'equals', 'manage_options' );

		$sub = new WP_User( $this->factory->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertFalse( Evaluator::evaluate( 'cap_eq_miss', $sub, false ) );
	}

	public function test_capability_not_equals_matches_user_without_capability(): void {
		$flag_id = $this->make_flag( 'cap_ne', status: 0 );
		$this->add_target( $flag_id, 'capability', 'not_equals', 'manage_options' );

		$sub = new WP_User( $this->factory->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertTrue( Evaluator::evaluate( 'cap_ne', $sub, false ) );
	}

	public function test_capability_not_equals_does_not_match_user_with_capability(): void {
		$flag_id = $this->make_flag( 'cap_ne_miss', status: 0 );
		$this->add_target( $flag_id, 'capability', 'not_equals', 'manage_options' );

		$admin = new WP_User( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertFalse( Evaluator::evaluate( 'cap_ne_miss', $admin, false ) );
	}

	// -------------------------------------------------------------------------
	// User ID targeting
	// -------------------------------------------------------------------------

	public function test_user_id_equals_matches_specific_user(): void {
		$flag_id = $this->make_flag( 'uid_eq', status: 0 );
		$user_id = $this->factory->user->create();
		$this->add_target( $flag_id, 'user_id', 'equals', (string) $user_id );

		$this->assertTrue( Evaluator::evaluate( 'uid_eq', new WP_User( $user_id ), false ) );
	}

	public function test_user_id_equals_does_not_match_different_user(): void {
		$flag_id  = $this->make_flag( 'uid_eq_miss', status: 0 );
		$user_id  = $this->factory->user->create();
		$other_id = $this->factory->user->create();
		$this->add_target( $flag_id, 'user_id', 'equals', (string) $user_id );

		$this->assertFalse( Evaluator::evaluate( 'uid_eq_miss', new WP_User( $other_id ), false ) );
	}

	public function test_user_id_not_equals_matches_different_user(): void {
		$flag_id  = $this->make_flag( 'uid_ne', status: 0 );
		$user_id  = $this->factory->user->create();
		$other_id = $this->factory->user->create();
		$this->add_target( $flag_id, 'user_id', 'not_equals', (string) $user_id );

		$this->assertTrue( Evaluator::evaluate( 'uid_ne', new WP_User( $other_id ), false ) );
	}

	public function test_user_id_in_list_matches_when_id_is_listed(): void {
		$flag_id  = $this->make_flag( 'uid_in', status: 0 );
		$user_id  = $this->factory->user->create();
		$other_id = $this->factory->user->create();
		$this->add_target( $flag_id, 'user_id', 'in_list', "{$user_id},{$other_id}" );

		$this->assertTrue( Evaluator::evaluate( 'uid_in', new WP_User( $user_id ), false ) );
	}

	public function test_user_id_in_list_does_not_match_unlisted_id(): void {
		$flag_id  = $this->make_flag( 'uid_in_miss', status: 0 );
		$user_id  = $this->factory->user->create();
		$third_id = $this->factory->user->create();
		$this->add_target( $flag_id, 'user_id', 'in_list', (string) $user_id );

		$this->assertFalse( Evaluator::evaluate( 'uid_in_miss', new WP_User( $third_id ), false ) );
	}

	// -------------------------------------------------------------------------
	// Email domain targeting
	// -------------------------------------------------------------------------

	public function test_email_domain_equals_matches_exact_domain(): void {
		$flag_id = $this->make_flag( 'email_eq', status: 0 );
		$this->add_target( $flag_id, 'email_domain', 'equals', 'acme.com' );

		$user = new WP_User( $this->factory->user->create( [ 'user_email' => 'alice@acme.com' ] ) );
		$this->assertTrue( Evaluator::evaluate( 'email_eq', $user, false ) );
	}

	public function test_email_domain_equals_does_not_match_different_domain(): void {
		$flag_id = $this->make_flag( 'email_eq_miss', status: 0 );
		$this->add_target( $flag_id, 'email_domain', 'equals', 'acme.com' );

		$user = new WP_User( $this->factory->user->create( [ 'user_email' => 'bob@other.com' ] ) );
		$this->assertFalse( Evaluator::evaluate( 'email_eq_miss', $user, false ) );
	}

	public function test_email_domain_not_equals_matches_different_domain(): void {
		$flag_id = $this->make_flag( 'email_ne', status: 0 );
		$this->add_target( $flag_id, 'email_domain', 'not_equals', 'acme.com' );

		$user = new WP_User( $this->factory->user->create( [ 'user_email' => 'bob@other.com' ] ) );
		$this->assertTrue( Evaluator::evaluate( 'email_ne', $user, false ) );
	}

	public function test_email_domain_not_equals_does_not_match_same_domain(): void {
		$flag_id = $this->make_flag( 'email_ne_miss', status: 0 );
		$this->add_target( $flag_id, 'email_domain', 'not_equals', 'acme.com' );

		$user = new WP_User( $this->factory->user->create( [ 'user_email' => 'carol@acme.com' ] ) );
		$this->assertFalse( Evaluator::evaluate( 'email_ne_miss', $user, false ) );
	}

	public function test_email_domain_contains_matches_partial_domain(): void {
		$flag_id = $this->make_flag( 'email_co', status: 0 );
		$this->add_target( $flag_id, 'email_domain', 'contains', 'acme' );

		$user = new WP_User( $this->factory->user->create( [ 'user_email' => 'dave@mail.acme.com' ] ) );
		$this->assertTrue( Evaluator::evaluate( 'email_co', $user, false ) );
	}

	public function test_email_domain_contains_does_not_match_unrelated_domain(): void {
		$flag_id = $this->make_flag( 'email_co_miss', status: 0 );
		$this->add_target( $flag_id, 'email_domain', 'contains', 'acme' );

		$user = new WP_User( $this->factory->user->create( [ 'user_email' => 'eve@example.com' ] ) );
		$this->assertFalse( Evaluator::evaluate( 'email_co_miss', $user, false ) );
	}

	// -------------------------------------------------------------------------
	// Disabled targeting rule is skipped
	// -------------------------------------------------------------------------

	public function test_disabled_rule_is_not_evaluated(): void {
		// Disabled rules are filtered out by FlagRepository::get_targets() (WHERE enabled=1).
		// With status=0 and the only rule disabled, evaluate() must return the fallback.
		$flag_id = $this->make_flag( 'disabled_rule', status: 0 );
		$this->add_target( $flag_id, 'role', 'equals', 'administrator', sort_order: 0, enabled: 0 );

		$admin = new WP_User( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertFalse( Evaluator::evaluate( 'disabled_rule', $admin, false ) );
	}

	// -------------------------------------------------------------------------
	// Multiple rules — first-match-wins by sort_order
	// -------------------------------------------------------------------------

	public function test_multiple_rules_first_sort_order_wins(): void {
		// sort_order=0: subscriber matches → returns true before sort_order=10 is reached.
		$flag_id = $this->make_flag( 'multi_first', status: 0 );
		$this->add_target( $flag_id, 'role', 'equals', 'subscriber', sort_order: 0 );
		$this->add_target( $flag_id, 'role', 'equals', 'editor', sort_order: 10 );

		$sub = new WP_User( $this->factory->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertTrue( Evaluator::evaluate( 'multi_first', $sub, false ) );
	}

	public function test_multiple_rules_second_fires_when_first_does_not_match(): void {
		// sort_order=0: administrator rule doesn't match subscriber.
		// sort_order=10: subscriber rule matches.
		$flag_id = $this->make_flag( 'multi_second', status: 0 );
		$this->add_target( $flag_id, 'role', 'equals', 'administrator', sort_order: 0 );
		$this->add_target( $flag_id, 'role', 'equals', 'subscriber', sort_order: 10 );

		$sub = new WP_User( $this->factory->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertTrue( Evaluator::evaluate( 'multi_second', $sub, false ) );
	}

	public function test_multiple_rules_no_match_falls_through_to_status(): void {
		// Neither rule matches the subscriber; status=1/100% means flag is on.
		$flag_id = $this->make_flag( 'multi_fallthrough', status: 1, rollout: 100 );
		$this->add_target( $flag_id, 'role', 'equals', 'administrator', sort_order: 0 );
		$this->add_target( $flag_id, 'role', 'equals', 'editor', sort_order: 10 );

		$sub = new WP_User( $this->factory->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertTrue( Evaluator::evaluate( 'multi_fallthrough', $sub, false ) );
	}

	// -------------------------------------------------------------------------
	// Anonymous users skip targeting
	// -------------------------------------------------------------------------

	public function test_anonymous_user_skips_targeting_and_uses_status(): void {
		// Targeting rule for administrator exists, but null user skips targeting.
		// status=1 / 100% → true.
		$flag_id = $this->make_flag( 'anon_on', status: 1, rollout: 100 );
		$this->add_target( $flag_id, 'role', 'equals', 'administrator' );

		$this->assertTrue( Evaluator::evaluate( 'anon_on', null, false ) );
	}

	public function test_anonymous_user_gets_fallback_when_flag_is_disabled(): void {
		$flag_id = $this->make_flag( 'anon_off', status: 0 );
		$this->add_target( $flag_id, 'role', 'equals', 'administrator' );

		$this->assertFalse( Evaluator::evaluate( 'anon_off', null, false ) );
	}

	// -------------------------------------------------------------------------
	// Circuit breaker fires before targeting (step 1 in evaluation order)
	// -------------------------------------------------------------------------

	public function test_tripped_circuit_breaker_returns_fallback_before_targeting(): void {
		$flag_id = $this->make_flag( 'cb', status: 1, rollout: 100 );
		$this->add_target( $flag_id, 'role', 'equals', 'administrator' );

		StateWriter::upsert_environment_state(
			'cb',
			self::ENVIRONMENT,
			[ 'circuit_breaker_tripped' => 1 ],
			ChangedVia::Api
		);

		$admin = new WP_User( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		// Flag is on, admin matches, but circuit breaker fires first — fallback returned.
		$this->assertFalse( Evaluator::evaluate( 'cb', $admin, false ) );
	}

	public function test_tripped_circuit_breaker_returns_custom_fallback(): void {
		$flag_id = $this->make_flag( 'cb_true', status: 1, rollout: 100 );

		StateWriter::upsert_environment_state(
			'cb_true',
			self::ENVIRONMENT,
			[ 'circuit_breaker_tripped' => 1 ],
			ChangedVia::Api
		);

		// Fallback is true — circuit breaker still returns it, not the flag state.
		$this->assertTrue( Evaluator::evaluate( 'cb_true', null, true ) );
	}

	// -------------------------------------------------------------------------
	// Partial rollout — hash-based bucket assignment (1–99%)
	// -------------------------------------------------------------------------

	public function test_partial_rollout_is_deterministic_at_50_percent(): void {
		$flag_key = 'pct_det';
		$this->make_flag( $flag_key, status: 1, rollout: 50 );
		$user_id  = $this->factory->user->create();
		$user     = new WP_User( $user_id );

		// Compute expected result using the same hash the evaluator uses.
		$expected = Evaluator::hash_identifier( $flag_key, (string) $user_id ) < 50;

		$this->assertSame( $expected, Evaluator::evaluate( $flag_key, $user, false ) );
	}

	public function test_partial_rollout_boundary_excludes_then_includes_on_increment(): void {
		$flag_key = 'pct_flip';
		$user_id  = $this->factory->user->create();
		$user     = new WP_User( $user_id );
		$bucket   = Evaluator::hash_identifier( $flag_key, (string) $user_id );

		// At the exact bucket value, is_in_percentage_bucket returns ($bucket < $bucket) = false.
		// Guard: if bucket=0, set_rollout=0 is a short-circuit — adjust to bucket=1 via a 2nd user.
		if ( 0 === $bucket ) {
			// Create a second user whose bucket is > 0. This is always possible because
			// at least one of (user_id, user_id+1, ...) will hash to bucket > 0.
			$user_id = $this->factory->user->create();
			$user    = new WP_User( $user_id );
			$bucket  = Evaluator::hash_identifier( $flag_key, (string) $user_id );
			if ( 0 === $bucket ) {
				$this->markTestSkipped( 'Two consecutive users both hashed to bucket 0 — skip.' );
			}
		}

		// Excluded: rollout = bucket → bucket < bucket is false.
		$this->make_flag( $flag_key, status: 1, rollout: $bucket );
		$this->assertFalse( Evaluator::evaluate( $flag_key, $user, false ), 'Should be excluded at rollout = bucket.' );

		if ( $bucket < 99 ) {
			// Included: rollout = bucket+1 → bucket < bucket+1 is true.
			StateWriter::upsert_environment_state( $flag_key, self::ENVIRONMENT, [ 'rollout_percentage' => $bucket + 1 ], ChangedVia::Api );
			$this->assertTrue( Evaluator::evaluate( $flag_key, $user, false ), 'Should be included at rollout = bucket+1.' );
		}
	}

	// -------------------------------------------------------------------------
	// Unregistered flag short-circuits to fallback
	// -------------------------------------------------------------------------

	public function test_unregistered_flag_returns_false_fallback(): void {
		$this->assertFalse( Evaluator::evaluate( 'ghost_flag', null, false ) );
	}

	public function test_unregistered_flag_returns_true_fallback(): void {
		$this->assertTrue( Evaluator::evaluate( 'ghost_flag', null, true ) );
	}
}
