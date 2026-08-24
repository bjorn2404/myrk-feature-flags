<?php
/**
 * Enum representing automatic rewind strategies for feature flags.
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

enum RewindStrategy: string {
	case Stepwise  = 'stepwise';
	case Immediate = 'immediate';
}
