<?php
// Fixture file for CleanupTest — contains various Myrk API call patterns.

function render_hero( array $attributes ): string {
	if ( Myrk::is_enabled( 'new_checkout' ) ) {
		return '<div class="hero hero--new"></div>';
	}
	return '<div class="hero"></div>';
}

function render_checkout( array $attributes, \WP_User $user ): string {
	if ( Myrk::is_enabled( 'new_checkout', $user ) ) {
		return '<div class="checkout checkout--new"></div>';
	}
	return '<div class="checkout"></div>';
}

function render_payment( array $attributes ): string {
	return Myrk::attempt( 'new_checkout', function() {
		return render_new_payment();
	}, function() {
		return render_legacy_payment();
	});
}
