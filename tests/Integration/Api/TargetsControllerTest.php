<?php

declare( strict_types=1 );

namespace Myrk\Tests\Integration\Api;

use Myrk\Database\FlagRepository;
use Myrk\Database\FlagWriter;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

class TargetsControllerTest extends WP_UnitTestCase {

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
		$wpdb->query( "DELETE FROM {$wpdb->prefix}myrk_flag_targets" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}myrk_state_log" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}myrk_flags" );
		parent::tear_down();
	}

	public function test_get_targets_returns_empty_array_initially(): void {
		wp_set_current_user( $this->admin_id );

		FlagWriter::create( [ 'flag_key' => 'targets_flag', 'label' => 'Targets Flag' ] );

		$request  = new WP_REST_Request( 'GET', '/myrk/v1/flags/targets_flag/targets' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [], $response->get_data() );
	}

	public function test_create_target_returns_201(): void {
		wp_set_current_user( $this->admin_id );

		FlagWriter::create( [ 'flag_key' => 'target_flag', 'label' => 'Target Flag' ] );

		$request = new WP_REST_Request( 'POST', '/myrk/v1/flags/target_flag/targets' );
		$request->set_param( 'env', 'production' );
		$request->set_param( 'type', 'role' );
		$request->set_param( 'operator', 'equals' );
		$request->set_param( 'value', 'administrator' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'id', $data );
		$this->assertSame( 'role', $data['type'] );
		$this->assertSame( 'equals', $data['operator'] );
		$this->assertSame( 'administrator', $data['value'] );
		$this->assertTrue( $data['enabled'] );
	}

	public function test_patch_target_updates_enabled_state(): void {
		wp_set_current_user( $this->admin_id );

		$flag_id   = FlagWriter::create( [ 'flag_key' => 'patch_target_flag', 'label' => 'Patch Target' ] );
		$target_id = FlagWriter::create_target(
			$flag_id,
			'production',
			[ 'type' => 'role', 'operator' => 'equals', 'value' => 'editor' ]
		);

		$request = new WP_REST_Request( 'PATCH', "/myrk/v1/flags/patch_target_flag/targets/{$target_id}" );
		$request->set_param( 'enabled', false );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['enabled'] );
	}

	public function test_delete_target_returns_204(): void {
		wp_set_current_user( $this->admin_id );

		$flag_id   = FlagWriter::create( [ 'flag_key' => 'del_target_flag', 'label' => 'Del Target' ] );
		$target_id = FlagWriter::create_target(
			$flag_id,
			'staging',
			[ 'type' => 'user_id', 'operator' => 'equals', 'value' => '1' ]
		);

		$request  = new WP_REST_Request( 'DELETE', "/myrk/v1/flags/del_target_flag/targets/{$target_id}" );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 204, $response->get_status() );
		$this->assertNull( FlagRepository::get_target_by_id( $target_id ) );
	}

	public function test_delete_target_rejects_wrong_flag_ownership(): void {
		wp_set_current_user( $this->admin_id );

		$flag_a_id = FlagWriter::create( [ 'flag_key' => 'flag_owner_a', 'label' => 'Flag A' ] );
		FlagWriter::create( [ 'flag_key' => 'flag_owner_b', 'label' => 'Flag B' ] );

		// Target belongs to flag_a.
		$target_id = FlagWriter::create_target(
			$flag_a_id,
			'production',
			[ 'type' => 'role', 'operator' => 'equals', 'value' => 'subscriber' ]
		);

		// Try to delete it through flag_b URL.
		$request  = new WP_REST_Request( 'DELETE', "/myrk/v1/flags/flag_owner_b/targets/{$target_id}" );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
		// Target must still exist.
		$this->assertNotNull( FlagRepository::get_target_by_id( $target_id ) );
	}

	public function test_get_targets_filters_by_env(): void {
		wp_set_current_user( $this->admin_id );

		$flag_id = FlagWriter::create( [ 'flag_key' => 'multi_env_targets', 'label' => 'Multi Env' ] );
		FlagWriter::create_target( $flag_id, 'production', [ 'type' => 'role', 'operator' => 'equals', 'value' => 'editor' ] );
		FlagWriter::create_target( $flag_id, 'staging',    [ 'type' => 'role', 'operator' => 'equals', 'value' => 'author' ] );

		$request = new WP_REST_Request( 'GET', '/myrk/v1/flags/multi_env_targets/targets' );
		$request->set_param( 'env', 'production' );
		$response = $this->server->dispatch( $request );

		$data = $response->get_data();
		$this->assertCount( 1, $data );
		$this->assertSame( 'editor', $data[0]['value'] );
	}
}
