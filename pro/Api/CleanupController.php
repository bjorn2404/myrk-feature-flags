<?php

declare( strict_types=1 );

namespace Myrk\Pro\Api;

class CleanupController extends \WP_REST_Controller {

	protected $namespace = 'myrk/v1';

	public function register_routes(): void {
		// TODO: Register GET /cleanup/candidates, GET /cleanup/refs/{key}.
	}
}
