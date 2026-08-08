<?php
/**
 * Flag status enum.
 *
 * @package Myrk
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
