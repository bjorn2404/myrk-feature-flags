<?php

declare( strict_types=1 );

namespace Myrk\Pro\Cli;

/**
 * Query the Myrk state change audit log.
 *
 * ## EXAMPLES
 *
 *     wp myrk log list --flag=new_checkout
 *     wp myrk log export --output=myrk-log.json
 */
class LogCommand extends \WP_CLI_Command {

	// TODO: Implement list, export subcommands.
}
