<?php

declare( strict_types=1 );

namespace Myrk\Pro\Api;

class LogController extends \WP_REST_Controller {

	protected $namespace = 'myrk/v1';
	protected $rest_base = 'log';

	public function register_routes(): void {
		// TODO: Register GET /log, GET /log/export.
	}
}
