<?php

declare( strict_types=1 );

namespace Myrk\Pro\Cli;

/**
 * Rewind a feature flag to a previous state.
 *
 * ## EXAMPLES
 *
 *     wp myrk rewind new_checkout
 *     wp myrk rewind new_checkout --steps=2 --dry-run
 */
class RewindCommand extends \WP_CLI_Command {

	// TODO: Implement rewind, status subcommands.
}
