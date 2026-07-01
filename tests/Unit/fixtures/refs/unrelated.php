<?php
// Fixture file — this file references a different flag key.
// It should NOT appear in refs for 'new_checkout'.

function show_new_editor(): bool {
	return Myrk::is_enabled( 'new_editor_experience' );
}
