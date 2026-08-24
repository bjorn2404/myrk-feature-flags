<?php
/**
 * Enum representing the actor that changed a flag state.
 *
 * @package   Myrk
 * @author    Bjorn Holine <bjorn@myrk.build>
 * @license   GPL-2.0-or-later
 * @link      https://myrk.build/
 * @copyright 2026 Bjorn Holine
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
