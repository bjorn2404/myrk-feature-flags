<?php

declare( strict_types=1 );

namespace Myrk\Tests\Integration\Api;

use Myrk\ChangedVia;
use Myrk\Database\FlagWriter;
use Myrk\Database\StateWriter;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

class EnvironmentsControllerTest extends WP_UnitTestCase {

	private WP_REST_Server $server;
	private int $admin_id;

	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$this->admin_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
	}

	public function tear_down(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}myrk_flag_environments" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}myrk_state_log" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}myrk_flags" );
		parent::tear_down();
	}

	public function test_get_environments_returns_all_states(): void {
		wp_set_current_user( $this->admin_id );

		FlagWriter::create( [ 'flag_key' => 'env_flag', 'label' => 'Env Flag' ] );
		StateWriter::upsert_environment_state( 'env_flag', 'production', [ 'status' => 1 ], ChangedVia::Admin );
		StateWriter::upsert_environment_state( 'env_flag', 'staging',    [ 'status' => 0 ], ChangedVia::Admin );

		$request  = new WP_REST_Request( 'GET', '/myrk/v1/flags/env_flag/environments' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'production', $data );
		$this->assertArrayHasKey( 'staging', $data );
		$this->assertSame( 'enabled', $data['production']['status'] );
		$this->assertSame( 'disabled', $data['staging']['status'] );
	}

	public function test_get_environments_returns_404_for_missing_flag(): void {
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'GET', '/myrk/v1/flags/no_such_flag/environments' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_patch_enables_flag_in_environment(): void {
		wp_set_current_user( $this->admin_id );

		FlagWriter::create( [ 'flag_key' => 'patch_env_flag', 'label' => 'Patch Env' ] );

		$request = new WP_REST_Request( 'PATCH', '/myrk/v1/flags/patch_env_flag/environments/staging' );
		$request->set_param( 'status', 'enabled' );
		$request->set_param( 'percentage', 50 );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'enabled', $data['status'] );
		$this->assertSame( 50, $data['percentage'] );
	}

	public function test_patch_validates_environment_name(): void {
		wp_set_current_user( $this->admin_id );

		FlagWriter::create( [ 'flag_key' => 'env_val_flag', 'label' => 'Env Val' ] );

		$request = new WP_REST_Request( 'PATCH', '/myrk/v1/flags/env_val_flag/environments/notreal' );
		$request->set_param( 'status', 'enabled' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 422, $response->get_status() );
	}

	public function test_patch_writes_state_log_entry(): void {
		wp_set_current_user( $this->admin_id );

		FlagWriter::create( [ 'flag_key' => 'log_env_flag', 'label' => 'Log Env' ] );

		$request = new WP_REST_Request( 'PATCH', '/myrk/v1/flags/log_env_flag/environments/production' );
		$request->set_param( 'status', 'enabled' );
		$request->set_param( 'note', 'Testing via API' );
		$this->server->dispatch( $request );

		global $wpdb;
		$log = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}myrk_state_log WHERE flag_key = %s ORDER BY id DESC LIMIT 1",
				'log_env_flag'
			)
		);

		$this->assertNotNull( $log );
		$this->assertSame( 'Testing via API', $log->note );
		$this->assertSame( ChangedVia::Api->value, $log->changed_via );
	}

	public function test_patch_requires_at_least_one_field(): void {
		wp_set_current_user( $this->admin_id );

		FlagWriter::create( [ 'flag_key' => 'no_changes_flag', 'label' => 'No Changes' ] );

		$request  = new WP_REST_Request( 'PATCH', '/myrk/v1/flags/no_changes_flag/environments/production' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 422, $response->get_status() );
	}
}
