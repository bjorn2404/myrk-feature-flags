<?php

declare( strict_types=1 );

namespace Myrk\Pro;

// Override the free-tier fallback. This file must be included before the
// `if ( ! function_exists( 'myrk_is_licensed' ) )` guard in myrk-feature-flags.php.
// In the pro distribution, composer.json adds pro/ to autoload files or
// myrk-feature-flags.php explicitly requires this file before the guard.
if ( ! function_exists( 'myrk_is_licensed' ) ) {
	function myrk_is_licensed(): bool {
		return License::is_valid();
	}
}

class Plugin {

	public function boot(): void {
		License::maybe_refresh();

		// TODO: Register pro REST controllers, CLI commands, admin screens.
		// TODO: Register wp_recovery_mode_begin hook for Rewind integration.
		// TODO: Register scheduled changes dispatcher.
	}
}
