<?php

declare( strict_types=1 );

namespace Myrk\Pro;

class Scheduler {

	// TODO: Implement dispatch gate pattern (checked on page load, not WP-Cron).
	// TODO: Execute pending scheduled changes from wp_myrk_scheduled_changes.
	// TODO: Record execution in state log with changed_via = 'scheduler'.
}
