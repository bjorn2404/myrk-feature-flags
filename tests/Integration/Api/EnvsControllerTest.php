<?php

declare( strict_types=1 );

namespace Myrk\Tests\Integration\Api;

use Myrk\ChangedVia;
use Myrk\Database\FlagRepository;
use Myrk\Database\FlagWriter;
use Myrk\Database\StateWriter;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

class EnvsControllerTest extends WP_UnitTestCase {

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

	public function test_copy_writes_env_states_to_target(): void {
		wp_set_current_user( $this->admin_id );

		FlagWriter::create( [ 'flag_key' => 'copy_flag', 'label' => 'Copy Me' ] );
		StateWriter::upsert_environment_state(
			'copy_flag', 'staging',
			[ 'status' => 1, 'rollout_percentage' => 75 ],
			ChangedVia::Admin
		);

		$request = new WP_REST_Request( 'POST', '/myrk/v1/envs/copy' );
		$request->set_param( 'from', 'staging' );
		$request->set_param( 'to', 'production' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertFalse( $data['dry_run'] );
		$this->assertCount( 1, $data['applied'] );
		$this->assertSame( 'copy_flag', $data['applied'][0]['flag_key'] );

		// Verify state was actually written to production.
		$prod_state = FlagRepository::get_environment_state( 'copy_flag', 'production' );
		$this->assertNotNull( $prod_state );
		$this->assertSame( '1', $prod_state->status );
		$this->assertSame( '75', $prod_state->rollout_percentage );
	}

	public function test_dry_run_does_not_write_state(): void {
		wp_set_current_user( $this->admin_id );

		FlagWriter::create( [ 'flag_key' => 'dry_flag', 'label' => 'Dry Run' ] );
		StateWriter::upsert_environment_state(
			'dry_flag', 'staging',
			[ 'status' => 1, 'rollout_percentage' => 100 ],
			ChangedVia::Admin
		);

		$request = new WP_REST_Request( 'POST', '/myrk/v1/envs/copy' );
		$request->set_param( 'from', 'staging' );
		$request->set_param( 'to', 'production' );
		$request->set_param( 'dry_run', true );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['dry_run'] );

		// Production state must NOT have been created.
		$prod_state = FlagRepository::get_environment_state( 'dry_flag', 'production' );
		$this->assertNull( $prod_state );
	}

	public function test_copy_filters_by_flags_array(): void {
		wp_set_current_user( $this->admin_id );

		FlagWriter::create( [ 'flag_key' => 'include_flag', 'label' => 'Include' ] );
		FlagWriter::create( [ 'flag_key' => 'exclude_flag', 'label' => 'Exclude' ] );

		StateWriter::upsert_environment_state( 'include_flag', 'staging', [ 'status' => 1 ], ChangedVia::Admin );
		StateWriter::upsert_environment_state( 'exclude_flag', 'staging', [ 'status' => 1 ], ChangedVia::Admin );

		$request = new WP_REST_Request( 'POST', '/myrk/v1/envs/copy' );
		$request->set_param( 'from', 'staging' );
		$request->set_param( 'to', 'production' );
		$request->set_param( 'flags', [ 'include_flag' ] );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();

		$applied_keys = array_column( $data['applied'], 'flag_key' );
		$this->assertContains( 'include_flag', $applied_keys );
		$this->assertNotContains( 'exclude_flag', $applied_keys );
		$this->assertContains( 'exclude_flag', $data['skipped'] );
	}

	public function test_copy_rejects_same_environment(): void {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', '/myrk/v1/envs/copy' );
		$request->set_param( 'from', 'staging' );
		$request->set_param( 'to', 'staging' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 422, $response->get_status() );
	}
}
