<?php

declare( strict_types=1 );

namespace Myrk\Tests\Unit;

use Myrk\Evaluator;
use PHPUnit\Framework\TestCase;

class EvaluatorTest extends TestCase {

	// -------------------------------------------------------------------------
	// evaluate() — requires WP + DB; covered by integration tests.
	// -------------------------------------------------------------------------

	public function test_returns_default_when_flag_not_registered(): void {
		$this->markTestIncomplete( 'Evaluator::evaluate() requires WordPress — see Integration tests.' );
	}

	public function test_returns_default_when_circuit_breaker_tripped(): void {
		$this->markTestIncomplete( 'Evaluator::evaluate() requires WordPress — see Integration tests.' );
	}

	public function test_evaluation_order_checks_circuit_breaker_first(): void {
		$this->markTestIncomplete( 'Evaluator::evaluate() requires WordPress — see Integration tests.' );
	}

	public function test_role_targeting_matches_correct_user(): void {
		$this->markTestIncomplete( 'WP_User requires WordPress bootstrap — see Integration tests.' );
	}

	public function test_ip_hash_used_for_anonymous_users(): void {
		$this->markTestIncomplete( 'Evaluator::evaluate() requires WordPress — see Integration tests.' );
	}

	public function test_explicit_default_returned_on_disabled_flag(): void {
		$this->markTestIncomplete( 'Evaluator::evaluate() requires WordPress — see Integration tests.' );
	}

	// -------------------------------------------------------------------------
	// hash_identifier() — pure PHP, no WordPress required.
	// -------------------------------------------------------------------------

	public function test_percentage_rollout_is_deterministic(): void {
		$result1 = Evaluator::hash_identifier( 'new_checkout', 'user_123' );
		$result2 = Evaluator::hash_identifier( 'new_checkout', 'user_123' );
		$this->assertSame( $result1, $result2 );
	}

	public function test_hash_returns_value_in_0_to_99_range(): void {
		for ( $i = 0; $i < 50; $i++ ) {
			$bucket = Evaluator::hash_identifier( 'my_flag', "user_{$i}" );
			$this->assertGreaterThanOrEqual( 0, $bucket );
			$this->assertLessThan( 100, $bucket );
		}
	}

	public function test_flag_identifier_prevents_cross_flag_correlation(): void {
		// The same user must land in different buckets for different flag keys.
		$bucket_a = Evaluator::hash_identifier( 'flag_a', 'user_1' );
		$bucket_b = Evaluator::hash_identifier( 'flag_b', 'user_1' );
		$this->assertNotSame( $bucket_a, $bucket_b );
	}

	// -------------------------------------------------------------------------
	// is_in_percentage_bucket() — pure PHP, no WordPress required.
	// -------------------------------------------------------------------------

	public function test_percentage_rollout_distributes_correctly(): void {
		$enabled = 0;
		for ( $i = 0; $i < 1000; $i++ ) {
			if ( Evaluator::is_in_percentage_bucket( 'new_checkout', "user_{$i}", 25 ) ) {
				++$enabled;
			}
		}
		// Allow ±5% variance around expected 250.
		$this->assertGreaterThan( 200, $enabled );
		$this->assertLessThan( 300, $enabled );
	}

	public function test_100_percent_always_returns_true(): void {
		for ( $i = 0; $i < 20; $i++ ) {
			$this->assertTrue( Evaluator::is_in_percentage_bucket( 'any_flag', "user_{$i}", 100 ) );
		}
	}

	public function test_0_percent_always_returns_false(): void {
		for ( $i = 0; $i < 20; $i++ ) {
			$this->assertFalse( Evaluator::is_in_percentage_bucket( 'any_flag', "user_{$i}", 0 ) );
		}
	}

	// -------------------------------------------------------------------------
	// resolve_anonymous_identifier() — pure PHP, no WordPress required.
	// -------------------------------------------------------------------------

	public function test_anonymous_identifier_varies_by_flag_key(): void {
		$_SERVER['REMOTE_ADDR'] = '192.0.2.1';

		$id_a = Evaluator::resolve_anonymous_identifier( 'flag_a' );
		$id_b = Evaluator::resolve_anonymous_identifier( 'flag_b' );

		$this->assertNotSame( $id_a, $id_b, 'Flag key must prevent cross-flag correlation.' );
	}

	public function test_anonymous_identifier_is_deterministic_for_same_ip(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.42';

		$id1 = Evaluator::resolve_anonymous_identifier( 'my_flag' );
		$id2 = Evaluator::resolve_anonymous_identifier( 'my_flag' );

		$this->assertSame( $id1, $id2 );
	}
}
