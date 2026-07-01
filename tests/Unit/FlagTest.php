<?php

declare( strict_types=1 );

namespace Myrk\Tests\Unit;

use Myrk\Flag;
use Myrk\RewindStrategy;
use PHPUnit\Framework\TestCase;

class FlagTest extends TestCase {

	public function test_flag_stores_provided_properties(): void {
		$flag = new Flag(
			flag_key:        'new_checkout',
			label:           'New Checkout Experience',
			description:     'Rolls out redesigned checkout.',
			default:         false,
			rewind_strategy: RewindStrategy::Immediate,
		);

		$this->assertSame( 'new_checkout', $flag->flag_key );
		$this->assertSame( 'New Checkout Experience', $flag->label );
		$this->assertSame( 'Rolls out redesigned checkout.', $flag->description );
		$this->assertFalse( $flag->default );
		$this->assertSame( RewindStrategy::Immediate, $flag->rewind_strategy );
	}

	public function test_flag_defaults_to_stepwise_rewind_strategy(): void {
		$flag = new Flag( flag_key: 'test', label: 'Test' );
		$this->assertSame( RewindStrategy::Stepwise, $flag->rewind_strategy );
	}

	public function test_flag_defaults_to_disabled(): void {
		$flag = new Flag( flag_key: 'test', label: 'Test' );
		$this->assertFalse( $flag->default );
	}

	public function test_flag_properties_are_readonly(): void {
		$flag = new Flag( flag_key: 'test', label: 'Test' );
		$this->expectException( \Error::class );
		// @phpstan-ignore-next-line
		$flag->flag_key = 'mutated';
	}
}
