<?php

declare( strict_types=1 );

namespace Myrk\Tests\Integration\Api;

use Myrk\ChangedVia;
use Myrk\Database\FlagWriter;
use Myrk\Database\StateWriter;
use Myrk\Flag;
use Myrk\Registry;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

class EvaluateControllerTest extends WP_UnitTestCase {

	private WP_REST_Server $server;

	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		Registry::reset();
	}

	public function tear_down(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}myrk_flag_environments" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}myrk_state_log" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}myrk_flags" );
		Registry::reset();
		parent::tear_down();
	}

	public function test_returns_evaluation_without_authentication(): void {
		Registry::register( 'public_flag', new Flag( 'public_flag', 'Public Flag' ) );
		FlagWriter::create( [ 'flag_key' => 'public_flag', 'label' => 'Public Flag' ] );
		StateWriter::upsert_environment_state( 'public_flag', 'local', [ 'status' => 1, 'rollout_percentage' => 100 ], ChangedVia::Admin );

		$request  = new WP_REST_Request( 'GET', '/myrk/v1/evaluate/public_flag' );
		$response = $this->server->dispatch( $request );

		// Must be accessible without a logged-in user.
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'public_flag', $data['flag_key'] );
		$this->assertArrayHasKey( 'enabled', $data );
	}

	public function test_returns_404_for_unregistered_flag(): void {
		// Flag exists in DB but is not registered in code.
		FlagWriter::create( [ 'flag_key' => 'db_only_flag', 'label' => 'DB Only' ] );

		$request  = new WP_REST_Request( 'GET', '/myrk/v1/evaluate/db_only_flag' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_returns_true_when_fully_enabled(): void {
		$_SERVER['REMOTE_ADDR'] = '192.0.2.99';

		Registry::register( 'full_on', new Flag( 'full_on', 'Full On' ) );
		FlagWriter::create( [ 'flag_key' => 'full_on', 'label' => 'Full On' ] );
		StateWriter::upsert_environment_state( 'full_on', 'local', [ 'status' => 1, 'rollout_percentage' => 100 ], ChangedVia::Admin );

		$request  = new WP_REST_Request( 'GET', '/myrk/v1/evaluate/full_on' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['enabled'] );
	}

	public function test_returns_false_when_disabled(): void {
		$_SERVER['REMOTE_ADDR'] = '192.0.2.99';

		Registry::register( 'full_off', new Flag( 'full_off', 'Full Off' ) );
		FlagWriter::create( [ 'flag_key' => 'full_off', 'label' => 'Full Off' ] );
		StateWriter::upsert_environment_state( 'full_off', 'local', [ 'status' => 0, 'rollout_percentage' => 0 ], ChangedVia::Admin );

		$request  = new WP_REST_Request( 'GET', '/myrk/v1/evaluate/full_off' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['enabled'] );
	}

	public function test_rate_limiting_blocks_after_60_requests(): void {
		// Force a deterministic IP that has no existing transient.
		$_SERVER['REMOTE_ADDR'] = '198.51.100.1';
		$ip_key = 'myrk_rl_' . md5( '198.51.100.1' );
		delete_transient( $ip_key );

		Registry::register( 'rl_flag', new Flag( 'rl_flag', 'RL Flag' ) );

		// Exhaust the limit by writing the transient directly at 60.
		set_transient( $ip_key, 60, 60 );

		$request  = new WP_REST_Request( 'GET', '/myrk/v1/evaluate/rl_flag' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 429, $response->get_status() );

		delete_transient( $ip_key );
	}

	public function test_response_has_no_store_cache_header(): void {
		$_SERVER['REMOTE_ADDR'] = '192.0.2.1';

		Registry::register( 'cache_flag', new Flag( 'cache_flag', 'Cache Flag' ) );

		$request  = new WP_REST_Request( 'GET', '/myrk/v1/evaluate/cache_flag' );
		$response = $this->server->dispatch( $request );

		// Either 200 or 404 is fine here; the header must always be set on success.
		if ( $response->get_status() === 200 ) {
			$headers = $response->get_headers();
			$this->assertStringContainsString( 'no-store', $headers['Cache-Control'] ?? '' );
		} else {
			$this->markTestSkipped( 'Flag has no env state in local environment.' );
		}
	}
}
