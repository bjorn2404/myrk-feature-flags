<?php
/**
 * WP-CLI commands for managing Myrk environments.
 *
 * @package Myrk
 */

declare( strict_types=1 );

namespace Myrk\Cli;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_CLI;
use WP_CLI_Command;

/**
 * Manage Myrk environments.
 *
 * ## EXAMPLES
 *
 *     wp myrk envs current
 */
class EnvsCommand extends WP_CLI_Command {

	/**
	 * Show the current WordPress environment type.
	 *
	 * ## EXAMPLES
	 *
	 *     wp myrk envs current
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments (unused).
	 * @when after_wp_load
	 */
	public function current( array $args, array $assoc_args ): void {
		WP_CLI::line( wp_get_environment_type() );
	}
}
