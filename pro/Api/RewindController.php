<?php

declare( strict_types=1 );

namespace Myrk\Pro\Api;

class RewindController extends \WP_REST_Controller {

	protected $namespace = 'myrk/v1';

	public function register_routes(): void {
		// TODO: Register POST /rewind/{key}, GET /rewind/{key}/status.
	}
}
