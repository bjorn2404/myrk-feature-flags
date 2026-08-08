<?php
/**
 * REST controller for targeting rules.
 *
 * @package Myrk
 */

declare( strict_types=1 );

namespace Myrk\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Myrk\Database\FlagRepository;
use Myrk\Database\FlagWriter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST controller for targeting rules.
 *
 * Routes:
 *   GET    /myrk/v1/flags/{flag_key}/targets
 *   POST   /myrk/v1/flags/{flag_key}/targets
 *   PATCH  /myrk/v1/flags/{flag_key}/targets/{id}
 *   DELETE /myrk/v1/flags/{flag_key}/targets/{id}
 */
class TargetsController extends AbstractController {

	/**
	 * Register routes for listing, creating, updating, and deleting targeting rules.
	 */
	public function register_routes(): void {
		$flag_segment = '/flags/(?P<flag_key>[a-zA-Z0-9_-]+)';

		register_rest_route(
			$this->namespace,
			$flag_segment . '/targets',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => [ $this, 'require_manage_flags' ],
					'args'                => [
						'env' => [
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_item' ],
					'permission_callback' => [ $this, 'require_manage_flags' ],
					'args'                => [
						'env'        => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'type'       => [
							'required' => true,
							'type'     => 'string',
							'enum'     => [ 'role', 'capability', 'user_id', 'email_domain' ],
						],
						'operator'   => [
							'required' => true,
							'type'     => 'string',
							'enum'     => [ 'equals', 'not_equals', 'contains', 'in_list' ],
						],
						'value'      => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'enabled'    => [
							'type'    => 'boolean',
							'default' => true,
						],
						'sort_order' => [
							'type' => 'integer',
						],
					],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			$flag_segment . '/targets/(?P<id>[\d]+)',
			[
				[
					'methods'             => 'PATCH',
					'callback'            => [ $this, 'update_item' ],
					'permission_callback' => [ $this, 'require_manage_flags' ],
					'args'                => [
						'type'       => [
							'type' => 'string',
							'enum' => [ 'role', 'capability', 'user_id', 'email_domain' ],
						],
						'operator'   => [
							'type' => 'string',
							'enum' => [ 'equals', 'not_equals', 'contains', 'in_list' ],
						],
						'value'      => [
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'enabled'    => [
							'type' => 'boolean',
						],
						'sort_order' => [
							'type' => 'integer',
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
	// GET /myrk/v1/flags/{flag_key}/targets
	// -------------------------------------------------------------------------

	/**
	 * Return all targeting rules for a flag, optionally filtered by environment.
	 *
	 * @param WP_REST_Request $request The incoming REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ): WP_REST_Response|WP_Error {
		$flag_key = $request->get_param( 'flag_key' );
		$flag_id  = FlagRepository::get_flag_id( $flag_key );

		if ( null === $flag_id ) {
			return $this->error_flag_not_found( $flag_key );
		}

		$env     = $request->get_param( 'env' );
		$env     = ( $env && $this->is_valid_environment( $env ) ) ? $env : null;
		$targets = FlagRepository::get_flag_targets( $flag_id, $env );

		$data = array_map( [ $this, 'format_target' ], $targets );

		return rest_ensure_response( $data );
	}

	// -------------------------------------------------------------------------
	// POST /myrk/v1/flags/{flag_key}/targets
	// -------------------------------------------------------------------------

	/**
	 * Create a new targeting rule for a flag.
	 *
	 * @param WP_REST_Request $request The incoming REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ): WP_REST_Response|WP_Error {
		$flag_key = $request->get_param( 'flag_key' );
		$env      = $request->get_param( 'env' );

		if ( ! $this->is_valid_environment( $env ) ) {
			return $this->error_invalid_environment( $env );
		}

		$flag_id = FlagRepository::get_flag_id( $flag_key );
		if ( null === $flag_id ) {
			return $this->error_flag_not_found( $flag_key );
		}

		$target_id = FlagWriter::create_target(
			$flag_id,
			$env,
			[
				'type'       => $request->get_param( 'type' ),
				'operator'   => $request->get_param( 'operator' ),
				'value'      => $request->get_param( 'value' ),
				'enabled'    => (int) (bool) $request->get_param( 'enabled' ),
				'sort_order' => $request->get_param( 'sort_order' ),
			]
		);

		if ( null === $target_id ) {
			return new WP_Error(
				'myrk_target_create_failed',
				__( 'Failed to create targeting rule.', 'myrk' ),
				[ 'status' => 500 ]
			);
		}

		$target   = FlagRepository::get_target_by_id( $target_id );
		$response = rest_ensure_response( $this->format_target( $target ) );
		$response->set_status( 201 );

		return $response;
	}

	// -------------------------------------------------------------------------
	// PATCH /myrk/v1/flags/{flag_key}/targets/{id}
	// -------------------------------------------------------------------------

	/**
	 * Update an existing targeting rule.
	 *
	 * @param WP_REST_Request $request The incoming REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ): WP_REST_Response|WP_Error {
		$flag_key  = $request->get_param( 'flag_key' );
		$target_id = (int) $request->get_param( 'id' );

		$ownership_check = $this->verify_target_ownership( $flag_key, $target_id );
		if ( is_wp_error( $ownership_check ) ) {
			return $ownership_check;
		}

		$changes = [];
		foreach ( [ 'type', 'operator', 'value', 'enabled', 'sort_order' ] as $key ) {
			$value = $request->get_param( $key );
			if ( null !== $value ) {
				$changes[ $key ] = 'enabled' === $key ? (int) (bool) $value : $value;
			}
		}

		FlagWriter::update_target( $target_id, $changes );

		$target = FlagRepository::get_target_by_id( $target_id );
		return rest_ensure_response( $this->format_target( $target ) );
	}

	// -------------------------------------------------------------------------
	// DELETE /myrk/v1/flags/{flag_key}/targets/{id}
	// -------------------------------------------------------------------------

	/**
	 * Delete a targeting rule.
	 *
	 * @param WP_REST_Request $request The incoming REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ): WP_REST_Response|WP_Error {
		$flag_key  = $request->get_param( 'flag_key' );
		$target_id = (int) $request->get_param( 'id' );

		$ownership_check = $this->verify_target_ownership( $flag_key, $target_id );
		if ( is_wp_error( $ownership_check ) ) {
			return $ownership_check;
		}

		FlagWriter::delete_target( $target_id );

		return new WP_REST_Response( null, 204 );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Verify that a target row exists and belongs to the given flag_key.
	 *
	 * @param string $flag_key  The flag key that should own the target.
	 * @param int    $target_id DB row ID of the targeting rule.
	 * @return true|\WP_Error
	 */
	private function verify_target_ownership( string $flag_key, int $target_id ): bool|WP_Error {
		$flag_id = FlagRepository::get_flag_id( $flag_key );
		if ( null === $flag_id ) {
			return $this->error_flag_not_found( $flag_key );
		}

		$target = FlagRepository::get_target_by_id( $target_id );
		if ( null === $target || (int) $target->flag_id !== $flag_id ) {
			return new WP_Error(
				'myrk_target_not_found',
				/* translators: %d: target ID */
				sprintf( __( 'Targeting rule %d not found.', 'myrk' ), $target_id ),
				[ 'status' => 404 ]
			);
		}

		return true;
	}
}
