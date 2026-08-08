<?php
/**
 * Flag status enum.
 *
 * @package Myrk
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

namespace Myrk;

enum FlagStatus: string {
	case Enabled  = 'enabled';
	case Disabled = 'disabled';
}
