<?php
/**
 * REST controller for flag groups.
 *
 * @package Myrk
 */

declare( strict_types=1 );

namespace Myrk\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Myrk\Database\GroupRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST controller for flag group CRUD.
 *
 * Routes:
 *   GET    /myrk/v1/groups
 *   POST   /myrk/v1/groups
 *   GET    /myrk/v1/groups/{id}
 *   PATCH  /myrk/v1/groups/{id}
 *   DELETE /myrk/v1/groups/{id}
 */
class GroupsController extends AbstractController {

	/**
	 * REST base for group endpoints.
	 *
	 * @var string
	 */
	protected $rest_base = 'groups';

	/**
	 * Register all routes for group CRUD operations.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => [ $this, 'require_manage_flags' ],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_item' ],
					'permission_callback' => [ $this, 'require_manage_flags' ],
					'args'                => [
						'name'             => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'description'      => [
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_textarea_field',
						],
						'external_ref'     => [
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'external_ref_url' => [
							'type'              => 'string',
							'sanitize_callback' => 'esc_url_raw',
						],
					],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_item' ],
					'permission_callback' => [ $this, 'require_manage_flags' ],
				],
				[
					'methods'             => 'PATCH',
					'callback'            => [ $this, 'update_item' ],
					'permission_callback' => [ $this, 'require_manage_flags' ],
					'args'                => [
						'name'             => [
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'description'      => [
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
						],
						'external_ref'     => [
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'external_ref_url' => [
							'type'              => 'string',
							'sanitize_callback' => 'esc_url_raw',
						],
					],
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_item' ],
					'permission_callback' => [ $this, 'require_manage_flags' ],
				],
			]
		);
	}

	// -------------------------------------------------------------------------
	// GET /myrk/v1/groups
	// -------------------------------------------------------------------------

	/**
	 * Return all groups with flag counts.
	 *
	 * @param WP_REST_Request $request The incoming REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ): WP_REST_Response|WP_Error {
		$rows  = GroupRepository::get_all_with_flag_counts();
		$items = array_map( [ $this, 'prepare_group_for_response' ], $rows );
		return rest_ensure_response( $items );
	}

	// -------------------------------------------------------------------------
	// POST /myrk/v1/groups
	// -------------------------------------------------------------------------

	/**
	 * Create a new group.
	 *
	 * @param WP_REST_Request $request The incoming REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ): WP_REST_Response|WP_Error {
		$name = $request->get_param( 'name' );

		if ( null !== GroupRepository::get_by_name( $name ) ) {
			return new WP_Error(
				'myrk_group_exists',
				/* translators: %s: group name */
				sprintf( __( 'Group "%s" already exists.', 'myrk' ), $name ),
				[ 'status' => 409 ]
			);
		}

		$id = GroupRepository::create(
			[
				'name'             => $name,
				'description'      => (string) $request->get_param( 'description' ),
				'external_ref'     => $request->get_param( 'external_ref' ),
				'external_ref_url' => $request->get_param( 'external_ref_url' ),
			]
		);

		if ( null === $id ) {
			return new WP_Error(
				'myrk_create_failed',
				__( 'Failed to create group.', 'myrk' ),
				[ 'status' => 500 ]
			);
		}

		$response = rest_ensure_response( $this->prepare_group_for_response( GroupRepository::get_by_id( $id ) ) );
		$response->set_status( 201 );

		return $response;
	}

	// -------------------------------------------------------------------------
	// GET /myrk/v1/groups/{id}
	// -------------------------------------------------------------------------

	/**
	 * Return a single group with its flags.
	 *
	 * @param WP_REST_Request $request The incoming REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ): WP_REST_Response|WP_Error {
		$id  = (int) $request->get_param( 'id' );
		$row = GroupRepository::get_by_id( $id );

		if ( null === $row ) {
			return $this->error_group_not_found( $id );
		}

		$flags = GroupRepository::get_flags_for_group( $id );
		$data  = $this->prepare_group_for_response( $row );

		$data['flags'] = array_map(
			static function ( object $f ): array {
				return [
					'flag_key'  => $f->flag_key,
					'label'     => $f->label,
					'lifecycle' => $f->lifecycle,
					'tags'      => $f->tags ? explode( ',', $f->tags ) : [],
				];
			},
			$flags
		);

		return rest_ensure_response( $data );
	}

	// -------------------------------------------------------------------------
	// PATCH /myrk/v1/groups/{id}
	// -------------------------------------------------------------------------

	/**
	 * Update mutable fields on a group.
	 *
	 * @param WP_REST_Request $request The incoming REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ): WP_REST_Response|WP_Error {
		$id = (int) $request->get_param( 'id' );

		if ( null === GroupRepository::get_by_id( $id ) ) {
			return $this->error_group_not_found( $id );
		}

		$allowed = [ 'name', 'description', 'external_ref', 'external_ref_url' ];
		$changes = [];

		foreach ( $allowed as $key ) {
			$value = $request->get_param( $key );
			if ( null !== $value ) {
				$changes[ $key ] = $value;
			}
		}

		if ( ! empty( $changes ) ) {
			GroupRepository::update( $id, $changes );
		}

		return rest_ensure_response( $this->prepare_group_for_response( GroupRepository::get_by_id( $id ) ) );
	}

	// -------------------------------------------------------------------------
	// DELETE /myrk/v1/groups/{id}
	// -------------------------------------------------------------------------

	/**
	 * Delete a group. Flags in the group have their group_id nulled.
	 *
	 * @param WP_REST_Request $request The incoming REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ): WP_REST_Response|WP_Error {
		$id = (int) $request->get_param( 'id' );

		if ( null === GroupRepository::get_by_id( $id ) ) {
			return $this->error_group_not_found( $id );
		}

		if ( ! GroupRepository::delete( $id ) ) {
			return new WP_Error(
				'myrk_delete_failed',
				__( 'Failed to delete group.', 'myrk' ),
				[ 'status' => 500 ]
			);
		}

		return new WP_REST_Response( null, 204 );
	}

	// -------------------------------------------------------------------------
	// Response formatting
	// -------------------------------------------------------------------------

	/**
	 * Format a group DB row for API responses.
	 *
	 * @param object $row Group database row.
	 * @return array<string, mixed>
	 */
	private function prepare_group_for_response( object $row ): array {
		return [
			'id'               => (int) $row->id,
			'name'             => $row->name,
			'description'      => $row->description,
			'external_ref'     => $row->external_ref,
			'external_ref_url' => $row->external_ref_url,
			'flag_count'       => isset( $row->flag_count ) ? (int) $row->flag_count : null,
			'created_at'       => $this->format_datetime( $row->created_at ),
			'updated_at'       => $this->format_datetime( $row->updated_at ),
		];
	}

	/**
	 * Return a 404 WP_Error for a group that does not exist.
	 *
	 * @param int $id The group ID that was not found.
	 * @return \WP_Error
	 */
	private function error_group_not_found( int $id ): WP_Error {
		return new WP_Error(
			'myrk_group_not_found',
			/* translators: %d: group ID */
			sprintf( __( 'Group %d not found.', 'myrk' ), $id ),
			[ 'status' => 404 ]
		);
	}
}
