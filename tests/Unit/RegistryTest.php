<?php

declare( strict_types=1 );

namespace Myrk\Tests\Unit;

use Myrk\Flag;
use Myrk\Registry;
use PHPUnit\Framework\TestCase;

class RegistryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Registry::reset();
	}

	protected function tearDown(): void {
		Registry::reset();
		parent::tearDown();
	}

	public function test_registers_and_retrieves_flag(): void {
		$flag = new Flag( flag_key: 'new_checkout', label: 'New Checkout' );
		Registry::register( 'new_checkout', $flag );

		$this->assertSame( $flag, Registry::get( 'new_checkout' ) );
	}

	public function test_returns_null_for_unknown_flag(): void {
		$this->assertNull( Registry::get( 'nonexistent' ) );
	}

	public function test_has_returns_true_for_registered_flag(): void {
		$flag = new Flag( flag_key: 'my_flag', label: 'My Flag' );
		Registry::register( 'my_flag', $flag );
		$this->assertTrue( Registry::has( 'my_flag' ) );
	}

	public function test_has_returns_false_for_unknown_flag(): void {
		$this->assertFalse( Registry::has( 'nonexistent' ) );
	}

	public function test_all_returns_all_registered_flags(): void {
		Registry::register( 'flag_a', new Flag( flag_key: 'flag_a', label: 'A' ) );
		Registry::register( 'flag_b', new Flag( flag_key: 'flag_b', label: 'B' ) );
		$this->assertCount( 2, Registry::all() );
	}

	public function test_reset_clears_all_flags(): void {
		Registry::register( 'flag_a', new Flag( flag_key: 'flag_a', label: 'A' ) );
		Registry::reset();
		$this->assertCount( 0, Registry::all() );
	}
}
