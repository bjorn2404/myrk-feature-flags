<?php
/**
 * Integration tests for GroupsController REST endpoints.
 *
 * @package Myrk\Tests\Integration\Api
 */

declare( strict_types=1 );

namespace Myrk\Tests\Integration\Api;

use Myrk\Database\FlagWriter;
use Myrk\Database\GroupRepository;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

class GroupsControllerTest extends WP_UnitTestCase {

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

		$wpdb->query( "DELETE FROM {$wpdb->prefix}myrk_flag_targets" );      // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}myrk_flag_environments" );  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}myrk_state_log" );          // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}myrk_flags" );              // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}myrk_flag_groups" );        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// GET /myrk/v1/groups
	// -------------------------------------------------------------------------

	public function test_list_requires_authentication(): void {
		$request  = new WP_REST_Request( 'GET', '/myrk/v1/groups' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_list_returns_403_for_non_admin_authenticated_user(): void {
		$subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		$request  = new WP_REST_Request( 'GET', '/myrk/v1/groups' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_list_returns_empty_array_when_no_groups(): void {
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'GET', '/myrk/v1/groups' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [], $response->get_data() );
	}

	public function test_list_returns_groups_with_flag_count(): void {
		wp_set_current_user( $this->admin_id );

		$group_id = GroupRepository::create( [ 'name' => 'Release 1.0' ] );
		FlagWriter::create( [ 'flag_key' => 'flag_a', 'label' => 'A', 'group_id' => $group_id ] );
		FlagWriter::create( [ 'flag_key' => 'flag_b', 'label' => 'B', 'group_id' => $group_id ] );

		$request  = new WP_REST_Request( 'GET', '/myrk/v1/groups' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertCount( 1, $data );
		$this->assertSame( 'Release 1.0', $data[0]['name'] );
		$this->assertSame( 2, $data[0]['flag_count'] );
	}

	public function test_list_returns_groups_ordered_by_name(): void {
		wp_set_current_user( $this->admin_id );

		GroupRepository::create( [ 'name' => 'Zeta' ] );
		GroupRepository::create( [ 'name' => 'Alpha' ] );
		GroupRepository::create( [ 'name' => 'Beta' ] );

		$request  = new WP_REST_Request( 'GET', '/myrk/v1/groups' );
		$response = $this->server->dispatch( $request );

		$data = $response->get_data();
		$this->assertSame( [ 'Alpha', 'Beta', 'Zeta' ], array_column( $data, 'name' ) );
	}

	// -------------------------------------------------------------------------
	// POST /myrk/v1/groups
	// -------------------------------------------------------------------------

	public function test_create_requires_authentication(): void {
		$request = new WP_REST_Request( 'POST', '/myrk/v1/groups' );
		$request->set_body_params( [ 'name' => 'Sprint 1' ] );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_create_requires_name_field(): void {
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'POST', '/myrk/v1/groups' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_create_returns_201_with_new_group(): void {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', '/myrk/v1/groups' );
		$request->set_body_params(
			[
				'name'             => 'Sprint 1',
				'description'      => 'First sprint flags',
				'external_ref'     => 'PROJ-42',
				'external_ref_url' => 'https://jira.example.com/PROJ-42',
			]
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'Sprint 1', $data['name'] );
		$this->assertSame( 'First sprint flags', $data['description'] );
		$this->assertSame( 'PROJ-42', $data['external_ref'] );
		$this->assertIsInt( $data['id'] );
	}

	public function test_create_returns_409_when_name_already_exists(): void {
		wp_set_current_user( $this->admin_id );

		GroupRepository::create( [ 'name' => 'Duplicate' ] );

		$request = new WP_REST_Request( 'POST', '/myrk/v1/groups' );
		$request->set_body_params( [ 'name' => 'Duplicate' ] );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 409, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// GET /myrk/v1/groups/{id}
	// -------------------------------------------------------------------------

	public function test_get_item_requires_authentication(): void {
		$id       = GroupRepository::create( [ 'name' => 'Auth Test' ] );
		$request  = new WP_REST_Request( 'GET', "/myrk/v1/groups/{$id}" );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_get_item_returns_404_for_missing_group(): void {
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'GET', '/myrk/v1/groups/99999' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_get_item_returns_group_with_flags(): void {
		wp_set_current_user( $this->admin_id );

		$group_id = GroupRepository::create( [ 'name' => 'Q4 Release', 'description' => 'Oct flags' ] );
		FlagWriter::create( [ 'flag_key' => 'checkout_v3', 'label' => 'Checkout V3', 'group_id' => $group_id ] );

		$request  = new WP_REST_Request( 'GET', "/myrk/v1/groups/{$group_id}" );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'Q4 Release', $data['name'] );
		$this->assertSame( 'Oct flags', $data['description'] );
		$this->assertArrayHasKey( 'flags', $data );
		$this->assertCount( 1, $data['flags'] );
		$this->assertSame( 'checkout_v3', $data['flags'][0]['flag_key'] );
	}

	public function test_get_item_returns_empty_flags_array_for_group_with_no_flags(): void {
		wp_set_current_user( $this->admin_id );

		$group_id = GroupRepository::create( [ 'name' => 'Empty Group' ] );
		$request  = new WP_REST_Request( 'GET', "/myrk/v1/groups/{$group_id}" );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [], $response->get_data()['flags'] );
	}

	// -------------------------------------------------------------------------
	// PATCH /myrk/v1/groups/{id}
	// -------------------------------------------------------------------------

	public function test_update_requires_authentication(): void {
		$id = GroupRepository::create( [ 'name' => 'Patch Auth' ] );

		$request = new WP_REST_Request( 'PATCH', "/myrk/v1/groups/{$id}" );
		$request->set_body_params( [ 'name' => 'Updated' ] );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_update_returns_404_for_missing_group(): void {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'PATCH', '/myrk/v1/groups/99999' );
		$request->set_body_params( [ 'name' => 'Ghost' ] );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_update_changes_name_and_description(): void {
		wp_set_current_user( $this->admin_id );

		$id = GroupRepository::create( [ 'name' => 'Old Name', 'description' => 'Old desc' ] );

		$request = new WP_REST_Request( 'PATCH', "/myrk/v1/groups/{$id}" );
		$request->set_body_params( [ 'name' => 'New Name', 'description' => 'New desc' ] );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'New Name', $data['name'] );
		$this->assertSame( 'New desc', $data['description'] );
	}

	public function test_update_returns_current_state_for_empty_patch(): void {
		wp_set_current_user( $this->admin_id );

		$id = GroupRepository::create( [ 'name' => 'No Change' ] );

		$request  = new WP_REST_Request( 'PATCH', "/myrk/v1/groups/{$id}" );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'No Change', $response->get_data()['name'] );
	}

	// -------------------------------------------------------------------------
	// DELETE /myrk/v1/groups/{id}
	// -------------------------------------------------------------------------

	public function test_delete_requires_authentication(): void {
		$id       = GroupRepository::create( [ 'name' => 'Delete Auth' ] );
		$request  = new WP_REST_Request( 'DELETE', "/myrk/v1/groups/{$id}" );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_delete_returns_404_for_missing_group(): void {
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'DELETE', '/myrk/v1/groups/99999' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_delete_returns_204_and_removes_group(): void {
		wp_set_current_user( $this->admin_id );

		$id       = GroupRepository::create( [ 'name' => 'Temporary Group' ] );
		$request  = new WP_REST_Request( 'DELETE', "/myrk/v1/groups/{$id}" );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 204, $response->get_status() );
		$this->assertNull( GroupRepository::get_by_id( $id ) );
	}

	public function test_delete_nulls_group_id_on_member_flags(): void {
		wp_set_current_user( $this->admin_id );

		$group_id = GroupRepository::create( [ 'name' => 'Doomed Group' ] );
		FlagWriter::create( [ 'flag_key' => 'orphan_flag', 'label' => 'Orphan', 'group_id' => $group_id ] );

		$request = new WP_REST_Request( 'DELETE', "/myrk/v1/groups/{$group_id}" );
		$this->server->dispatch( $request );

		// Flag must still exist but with group_id = NULL.
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT group_id FROM {$wpdb->prefix}myrk_flags WHERE flag_key = %s",
				'orphan_flag'
			)
		);

		$this->assertNotNull( $row );
		$this->assertNull( $row->group_id );
	}
}
