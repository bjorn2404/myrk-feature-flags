<?php

declare( strict_types=1 );

namespace Myrk\Tests\Integration\Api;

use Myrk\Api\FlagsController;
use Myrk\Database\FlagRepository;
use Myrk\Database\FlagWriter;
use Myrk\Database\StateWriter;
use Myrk\ChangedVia;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

class FlagsControllerTest extends WP_UnitTestCase {

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
		$wpdb->query( "DELETE FROM {$wpdb->prefix}myrk_flag_environments" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}myrk_state_log" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}myrk_flags" );
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// GET /myrk/v1/flags
	// -------------------------------------------------------------------------

	public function test_list_requires_authentication(): void {
		$request  = new WP_REST_Request( 'GET', '/myrk/v1/flags' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_list_returns_empty_array_when_no_flags(): void {
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'GET', '/myrk/v1/flags' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [], $response->get_data() );
	}

	public function test_list_returns_flags_with_pagination_headers(): void {
		wp_set_current_user( $this->admin_id );

		FlagWriter::create( [ 'flag_key' => 'flag_a', 'label' => 'Flag A' ] );
		FlagWriter::create( [ 'flag_key' => 'flag_b', 'label' => 'Flag B' ] );

		$request = new WP_REST_Request( 'GET', '/myrk/v1/flags' );
		$request->set_param( 'per_page', 10 );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 2, $response->get_data() );
		$this->assertSame( '2', $response->get_headers()['X-WP-Total'] );
	}

	public function test_list_filters_by_status_enabled(): void {
		wp_set_current_user( $this->admin_id );

		FlagWriter::create( [ 'flag_key' => 'enabled_flag', 'label' => 'On' ] );
		FlagWriter::create( [ 'flag_key' => 'disabled_flag', 'label' => 'Off' ] );

		StateWriter::upsert_environment_state(
			flag_key:    'enabled_flag',
			environment: 'production',
			changes:     [ 'status' => 1, 'rollout_percentage' => 100 ],
			changed_via: ChangedVia::Admin
		);
		StateWriter::upsert_environment_state(
			flag_key:    'disabled_flag',
			environment: 'production',
			changes:     [ 'status' => 0 ],
			changed_via: ChangedVia::Admin
		);

		$request = new WP_REST_Request( 'GET', '/myrk/v1/flags' );
		$request->set_param( 'status', 'enabled' );
		$request->set_param( 'env', 'production' );
		$response = $this->server->dispatch( $request );

		$data = $response->get_data();
		$this->assertCount( 1, $data );
		$this->assertSame( 'enabled_flag', $data[0]['flag_key'] );
	}

	public function test_list_filters_by_stale(): void {
		wp_set_current_user( $this->admin_id );

		FlagWriter::create( [ 'flag_key' => 'stale_flag', 'label' => 'Old' ] );
		FlagWriter::create( [ 'flag_key' => 'fresh_flag', 'label' => 'New' ] );

		global $wpdb;
		// Make stale_flag look fully rolled out and last updated 40 days ago.
		StateWriter::upsert_environment_state(
			flag_key:    'stale_flag',
			environment: 'production',
			changes:     [ 'status' => 1, 'rollout_percentage' => 100 ],
			changed_via: ChangedVia::Admin
		);
		$stale_flag_id = FlagRepository::get_flag_id( 'stale_flag' );
		$old_date      = gmdate( 'Y-m-d H:i:s', strtotime( '-95 days' ) );
		$wpdb->update(
			$wpdb->prefix . 'myrk_flag_environments',
			[ 'updated_at' => $old_date ],
			[ 'flag_id' => $stale_flag_id, 'environment' => 'production' ]
		);

		// fresh_flag has a current env row.
		StateWriter::upsert_environment_state(
			flag_key:    'fresh_flag',
			environment: 'production',
			changes:     [ 'status' => 1, 'rollout_percentage' => 50 ],
			changed_via: ChangedVia::Admin
		);

		$request = new WP_REST_Request( 'GET', '/myrk/v1/flags' );
		$request->set_param( 'stale', true );
		$request->set_param( 'env', 'production' );
		$response = $this->server->dispatch( $request );

		$data = $response->get_data();
		$this->assertCount( 1, $data );
		$this->assertSame( 'stale_flag', $data[0]['flag_key'] );
		$this->assertTrue( $data[0]['is_stale'] );
	}

	// -------------------------------------------------------------------------
	// POST /myrk/v1/flags
	// -------------------------------------------------------------------------

	public function test_create_flag_returns_201(): void {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', '/myrk/v1/flags' );
		$request->set_param( 'flag_key', 'my_new_flag' );
		$request->set_param( 'label', 'My New Flag' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'my_new_flag', $data['flag_key'] );
		$this->assertSame( 'My New Flag', $data['label'] );
	}

	public function test_create_returns_409_for_duplicate_key(): void {
		wp_set_current_user( $this->admin_id );

		FlagWriter::create( [ 'flag_key' => 'dupe_flag', 'label' => 'Dupe' ] );

		$request = new WP_REST_Request( 'POST', '/myrk/v1/flags' );
		$request->set_param( 'flag_key', 'dupe_flag' );
		$request->set_param( 'label', 'Dupe' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 409, $response->get_status() );
	}

	public function test_create_rejects_invalid_flag_key_format(): void {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', '/myrk/v1/flags' );
		$request->set_param( 'flag_key', '1starts_with_digit' );
		$request->set_param( 'label', 'Bad key' );
		$response = $this->server->dispatch( $request );

		// WP REST wraps validate_callback errors in a new WP_Error with status 400.
		$this->assertSame( 400, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// GET /myrk/v1/flags/{flag_key}
	// -------------------------------------------------------------------------

	public function test_get_returns_404_for_unknown_flag(): void {
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'GET', '/myrk/v1/flags/nonexistent' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_get_includes_all_environment_states(): void {
		wp_set_current_user( $this->admin_id );

		FlagWriter::create( [ 'flag_key' => 'multi_env', 'label' => 'Multi Env' ] );
		StateWriter::upsert_environment_state( 'multi_env', 'production', [ 'status' => 1 ], ChangedVia::Admin );
		StateWriter::upsert_environment_state( 'multi_env', 'staging', [ 'status' => 0 ], ChangedVia::Admin );

		$request  = new WP_REST_Request( 'GET', '/myrk/v1/flags/multi_env' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'environments', $data );
		$this->assertArrayHasKey( 'production', $data['environments'] );
		$this->assertArrayHasKey( 'staging', $data['environments'] );
		$this->assertSame( 'enabled', $data['environments']['production']['status'] );
		$this->assertSame( 'disabled', $data['environments']['staging']['status'] );
	}

	// -------------------------------------------------------------------------
	// PATCH /myrk/v1/flags/{flag_key}
	// -------------------------------------------------------------------------

	public function test_patch_updates_label_only(): void {
		wp_set_current_user( $this->admin_id );

		FlagWriter::create( [ 'flag_key' => 'patchable', 'label' => 'Original' ] );

		$request = new WP_REST_Request( 'PATCH', '/myrk/v1/flags/patchable' );
		$request->set_param( 'label', 'Updated Label' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'Updated Label', $data['label'] );
		$this->assertSame( 'patchable', $data['flag_key'] );
	}

	// -------------------------------------------------------------------------
	// DELETE /myrk/v1/flags/{flag_key}
	// -------------------------------------------------------------------------

	public function test_delete_requires_confirm_parameter(): void {
		wp_set_current_user( $this->admin_id );

		FlagWriter::create( [ 'flag_key' => 'to_delete', 'label' => 'Delete Me' ] );

		$request  = new WP_REST_Request( 'DELETE', '/myrk/v1/flags/to_delete' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		// Flag must still exist.
		$this->assertNotNull( FlagRepository::get_flag_id( 'to_delete' ) );
	}

	public function test_delete_removes_all_related_rows(): void {
		wp_set_current_user( $this->admin_id );

		$flag_id = FlagWriter::create( [ 'flag_key' => 'full_delete', 'label' => 'Delete All' ] );
		StateWriter::upsert_environment_state( 'full_delete', 'production', [ 'status' => 1 ], ChangedVia::Admin );

		$flag_id_check = FlagRepository::get_flag_id( 'full_delete' );
		$this->assertNotNull( $flag_id_check );

		$request = new WP_REST_Request( 'DELETE', '/myrk/v1/flags/full_delete' );
		$request->set_param( 'confirm', true );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 204, $response->get_status() );
		$this->assertNull( FlagRepository::get_flag_id( 'full_delete' ) );

		// Env state row must also be gone.
		global $wpdb;
		$env_count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}myrk_flag_environments WHERE flag_id = %d",
				$flag_id_check
			)
		);
		$this->assertSame( '0', $env_count );
	}
}
