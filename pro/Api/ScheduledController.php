<?php

declare( strict_types=1 );

namespace Myrk\Pro\Api;

class ScheduledController extends \WP_REST_Controller {

	protected $namespace = 'myrk/v1';
	protected $rest_base = 'scheduled';

	public function register_routes(): void {
		// TODO: Register GET /scheduled, POST /scheduled, DELETE /scheduled/{id}.
	}
}
