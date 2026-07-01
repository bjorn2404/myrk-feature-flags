<?php
// Fixture file for CleanupTest — contains procedural alias patterns.

function show_new_checkout_banner(): bool {
	return myrk_is_enabled( 'new_checkout' );
}

function show_checkout_for_user( \WP_User $user ): bool {
	return myrk_is_enabled_for( 'new_checkout', $user );
}
