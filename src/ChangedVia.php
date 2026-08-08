<?php
/**
 * Enum representing the actor that changed a flag state.
 *
 * @package Myrk
 */

declare( strict_types=1 );

namespace Myrk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

enum ChangedVia: string {
	case Admin          = 'admin';
	case Cli            = 'cli';
	case Api            = 'api';
	case Rewind         = 'rewind';
	case Scheduler      = 'scheduler';
	case CircuitBreaker = 'circuit_breaker';
}
