<?php
/**
 * Enum representing automatic rewind strategies for feature flags.
 *
 * @package Myrk
 */

declare( strict_types=1 );

namespace Myrk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

enum RewindStrategy: string {
	case Stepwise  = 'stepwise';
	case Immediate = 'immediate';
}
