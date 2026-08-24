<?php
/**
 * Flag status enum.
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

enum FlagStatus: string {
	case Enabled  = 'enabled';
	case Disabled = 'disabled';
}
