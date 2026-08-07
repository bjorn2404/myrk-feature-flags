<?php
/**
 * REST controller for flag definitions.
 *
 * @package Myrk
 */

declare( strict_types=1 );

namespace Myrk\Api;

use Myrk\Cleanup;
use Myrk\Database\FlagRepository;
use Myrk\Database\FlagWriter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST controller for flag definitions.
 *
 * Routes:
 *   GET    /myrk/v1/flags
 *   POST   /myrk/v1/flags
 *   GET    /myrk/v1/flags/{flag_key}
 *   PATCH  /myrk/v1/flags/{flag_key}
 *   DELETE /myrk/v1/flags/{flag_key}
 */
class FlagsController extends AbstractController {

	/**
	 * REST base for flag endpoints.
	 *
	 * @var string
	 */
	protected $rest_base = 'flags';

	/**
	 * Register all routes for flag definition CRUD operations.
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
					'args'                => [
						'env'      => [
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'status'   => [
							'type' => 'string',
							'enum' => [ 'enabled', 'disabled' ],
						],
						'stale'    => [
							'type' => 'boolean',
						],
						'group'    => [
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'tag'      => [
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'per_page' => [
							'type'    => 'integer',
							'default' => 20,
							'minimum' => 1,
							'maximum' => 100,
						],
						'page'     => [
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						],
					],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_item' ],
					'permission_callback' => [ $this, 'require_manage_flags' ],
					'args'                => [
						'flag_key'        => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
							'validate_callback' => [ $this, 'validate_flag_key_format' ],
						],
						'label'           => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'description'     => [
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_textarea_field',
						],
						'default_state'   => [
							'type'    => 'boolean',
							'default' => false,
						],
						'rewind_strategy' => [
							'type'    => 'string',
							'enum'    => [ 'stepwise', 'immediate' ],
							'default' => 'stepwise',
						],
						'lifecycle'       => [
							'type'    => 'string',
							'enum'    => [ 'temporary', 'permanent' ],
							'default' => 'temporary',
						],
						'group_id'        => [
							'type'    => [ 'integer', 'null' ],
							'minimum' => 1,
						],
						'tags'            => [
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<flag_key>[a-zA-Z0-9_-]+)',
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
						'label'           => [
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'description'     => [
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
						],
						'rewind_strategy' => [
							'type' => 'string',
							'enum' => [ 'stepwise', 'immediate' ],
						],
						'default_state'   => [
							'type' => 'boolean',
						],
						'lifecycle'       => [
							'type' => 'string',
							'enum' => [ 'temporary', 'permanent' ],
						],
						'group_id'        => [
							'type'    => [ 'integer', 'null' ],
							'minimum' => 1,
						],
						'tags'            => [
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_item' ],
					'permission_callback' => [ $this, 'require_manage_flags' ],
					'args'                => [
						'confirm' => [
							'required' => false,
							'type'     => 'boolean',
							'default'  => false,
						],
					],
				],
			]
		);
	}

	// -------------------------------------------------------------------------
	// GET /myrk/v1/flags
	// -------------------------------------------------------------------------

	/**
	 * Return a paginated list of flags with current-environment state.
	 *
	 * @param WP_REST_Request $request The incoming REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ): WP_REST_Response|WP_Error {
		$env      = $this->resolve_env( $request->get_param( 'env' ) );
		$per_page = (int) $request->get_param( 'per_page' );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$offset   = ( $page - 1 ) * $per_page;

		$filters = [];
		if ( $request->get_param( 'status' ) !== null ) {
			$filters['status']      = $request->get_param( 'status' );
			$filters['environment'] = $env;
		}
		if ( $request->get_param( 'group' ) !== null ) {
			$filters['group'] = $request->get_param( 'group' );
		}
		if ( $request->get_param( 'tag' ) !== null ) {
			$filters['tag'] = $request->get_param( 'tag' );
		}

		$total       = FlagRepository::get_total_count( $filters );
		$total_pages = (int) ceil( $total / $per_page );

		$flags = FlagRepository::get_all_with_env_state(
			$env,
			array_merge(
				$filters,
				[
					'per_page' => $per_page,
					'offset'   => $offset,
				]
			)
		);

		// Bulk-load targets for the current env in one query.
		$all_targets = FlagRepository::get_all_targets_for_env( $env );

		$want_stale = (bool) $request->get_param( 'stale' );
		$items      = [];

		foreach ( $flags as $flag ) {
			$targets   = $all_targets[ (int) $flag->id ] ?? [];
			$env_state = null !== $flag->env_id ? $flag : null;
			$item      = $this->prepare_flag_for_response( $flag, $env, $env_state, $targets );

			if ( $want_stale && ! $item['is_stale'] ) {
				continue;
			}

			$items[] = $item;
		}

		$response = rest_ensure_response( $items );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) $total_pages );

		return $response;
	}

	// -------------------------------------------------------------------------
	// POST /myrk/v1/flags
	// -------------------------------------------------------------------------

	/**
	 * Create a new flag definition.
	 *
	 * @param WP_REST_Request $request The incoming REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ): WP_REST_Response|WP_Error {
		$flag_key = $request->get_param( 'flag_key' );

		if ( null !== FlagRepository::get_flag_id( $flag_key ) ) {
			return new WP_Error(
				'myrk_flag_exists',
				/* translators: %s: flag key */
				sprintf( __( 'Flag "%s" already exists.', 'myrk' ), $flag_key ),
				[ 'status' => 409 ]
			);
		}

		$id = FlagWriter::create(
			[
				'flag_key'        => $flag_key,
				'label'           => $request->get_param( 'label' ),
				'description'     => (string) $request->get_param( 'description' ),
				'default_state'   => (int) (bool) $request->get_param( 'default_state' ),
				'rewind_strategy' => (string) $request->get_param( 'rewind_strategy' ),
				'lifecycle'       => (string) $request->get_param( 'lifecycle' ),
				'group_id'        => $request->get_param( 'group_id' ),
				'tags'            => $request->get_param( 'tags' ),
			]
		);

		if ( null === $id ) {
			return new WP_Error(
				'myrk_create_failed',
				__( 'Failed to create flag.', 'myrk' ),
				[ 'status' => 500 ]
			);
		}

		$flag = FlagRepository::get_by_key( $flag_key );
		$env  = wp_get_environment_type();
		$data = $this->prepare_flag_for_response( $flag, $env, null, [] );

		$response = rest_ensure_response( $data );
		$response->set_status( 201 );

		return $response;
	}

	// -------------------------------------------------------------------------
	// GET /myrk/v1/flags/{flag_key}
	// -------------------------------------------------------------------------

	/**
	 * Return full details for a single flag across all environments.
	 *
	 * @param WP_REST_Request $request The incoming REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ): WP_REST_Response|WP_Error {
		$flag_key = $request->get_param( 'flag_key' );
		$result   = FlagRepository::get_with_all_environments( $flag_key );

		if ( null === $result ) {
			return $this->error_flag_not_found( $flag_key );
		}

		$flag    = $result['flag'];
		$flag_id = (int) $flag->id;
		$targets = FlagRepository::get_flag_targets( $flag_id );

		$environments = [];
		foreach ( $result['environments'] as $env_row ) {
			$environments[ $env_row->environment ] = $this->format_env_state( $env_row, $env_row->environment );
		}

		// Group targets by environment.
		$targets_by_env = [];
		foreach ( $targets as $target ) {
			$targets_by_env[ $target->environment ][] = $this->format_target( $target );
		}

		$data = [
			'flag_key'        => $flag->flag_key,
			'label'           => $flag->label,
			'description'     => $flag->description,
			'default'         => (bool) $flag->default_state,
			'rewind_strategy' => $flag->rewind_strategy,
			'lifecycle'       => $flag->lifecycle ?? 'temporary',
			'group_id'        => $flag->group_id ? (int) $flag->group_id : null,
			'tags'            => $flag->tags ?? '',
			'is_registered'   => (bool) $flag->is_registered,
			'created_at'      => $this->format_datetime( $flag->created_at ),
			'updated_at'      => $this->format_datetime( $flag->updated_at ),
			'environments'    => $environments,
			'targets'         => $targets_by_env,
		];

		return rest_ensure_response( $data );
	}

	// -------------------------------------------------------------------------
	// PATCH /myrk/v1/flags/{flag_key}
	// -------------------------------------------------------------------------

	/**
	 * Update mutable fields on a flag definition.
	 *
	 * @param WP_REST_Request $request The incoming REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ): WP_REST_Response|WP_Error {
		$flag_key = $request->get_param( 'flag_key' );

		if ( null === FlagRepository::get_flag_id( $flag_key ) ) {
			return $this->error_flag_not_found( $flag_key );
		}

		$allowed = [ 'label', 'description', 'rewind_strategy', 'default_state', 'lifecycle', 'group_id', 'tags' ];
		$changes = [];

		foreach ( $allowed as $key ) {
			if ( ! $request->has_param( $key ) ) {
				continue;
			}
			$value           = $request->get_param( $key );
			$changes[ $key ] = 'default_state' === $key ? (int) (bool) $value : $value;
		}

		if ( ! empty( $changes ) ) {
			FlagWriter::update_definition( $flag_key, $changes );
		}

		$flag = FlagRepository::get_by_key( $flag_key );
		$env  = wp_get_environment_type();
		$data = $this->prepare_flag_for_response( $flag, $env, null, [] );

		return rest_ensure_response( $data );
	}

	// -------------------------------------------------------------------------
	// DELETE /myrk/v1/flags/{flag_key}
	// -------------------------------------------------------------------------

	/**
	 * Delete a flag and all its associated state, targets, and environment rows.
	 *
	 * @param WP_REST_Request $request The incoming REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ): WP_REST_Response|WP_Error {
		$flag_key = $request->get_param( 'flag_key' );

		if ( ! (bool) $request->get_param( 'confirm' ) ) {
			return new WP_Error(
				'myrk_confirm_required',
				__( 'Pass confirm=true to permanently delete a flag and all its state.', 'myrk' ),
				[ 'status' => 400 ]
			);
		}

		if ( null === FlagRepository::get_flag_id( $flag_key ) ) {
			return $this->error_flag_not_found( $flag_key );
		}

		if ( ! FlagWriter::delete( $flag_key ) ) {
			return new WP_Error(
				'myrk_delete_failed',
				__( 'Failed to delete flag.', 'myrk' ),
				[ 'status' => 500 ]
			);
		}

		return new WP_REST_Response( null, 204 );
	}

	// -------------------------------------------------------------------------
	// Response formatting
	// -------------------------------------------------------------------------

	/**
	 * Format a flag DB row for the list endpoint (includes current-env state).
	 *
	 * @param object      $flag      Row from wp_myrk_flags (may have joined env columns).
	 * @param string      $env       Environment name being displayed.
	 * @param object|null $env_state Joined env state row, or null when no env row exists.
	 * @param object[]    $targets   Target rows for current env.
	 * @return array<string, mixed>
	 */
	public function prepare_flag_for_response( $flag, string $env, ?object $env_state, array $targets ): array {
		$env_data = $this->format_env_state( $env_state, $env );

		$is_stale = false;
		if ( null !== $env_state ) {
			$stale_input = [
				'status'             => $env_state->status,
				'rollout_percentage' => $env_state->rollout_percentage,
				'updated_at'         => property_exists( $env_state, 'env_updated_at' )
					? $env_state->env_updated_at
					: $env_state->updated_at,
			];
			$is_stale    = Cleanup::is_stale( $stale_input );
		}

		return [
			'flag_key'        => $flag->flag_key,
			'label'           => $flag->label,
			'description'     => $flag->description,
			'default'         => (bool) $flag->default_state,
			'rewind_strategy' => $flag->rewind_strategy,
			'lifecycle'       => $flag->lifecycle ?? 'temporary',
			'group_id'        => $flag->group_id ? (int) $flag->group_id : null,
			'group_name'      => $flag->group_name ?? null,
			'tags'            => $flag->tags ?? '',
			'is_registered'   => (bool) $flag->is_registered,
			'is_stale'        => $is_stale,
			'created_at'      => $this->format_datetime( $flag->created_at ),
			'updated_at'      => $this->format_datetime( $flag->updated_at ),
			'environment'     => $env_data,
			'targets'         => array_map( [ $this, 'format_target' ], $targets ),
		];
	}

	// -------------------------------------------------------------------------
	// Validators & helpers
	// -------------------------------------------------------------------------

	/**
	 * Validate that a flag key matches the required format.
	 *
	 * @param mixed           $value   The value to validate.
	 * @param WP_REST_Request $request The incoming REST request.
	 * @param string          $param   The parameter name being validated.
	 * @return bool|WP_Error
	 */
	public function validate_flag_key_format( mixed $value, WP_REST_Request $request, string $param ): bool|WP_Error {
		if ( ! preg_match( '/^[a-z][a-z0-9_]*$/', (string) $value ) ) {
			return new WP_Error(
				'myrk_invalid_flag_key',
				__( 'Flag key must start with a lowercase letter and contain only lowercase letters, digits, and underscores.', 'myrk' ),
				[ 'status' => 422 ]
			);
		}
		return true;
	}

	/**
	 * Resolve the requested environment, falling back to the current site environment.
	 *
	 * @param string|null $env Environment name from the request, or null.
	 * @return string
	 */
	private function resolve_env( ?string $env ): string {
		if ( null !== $env && $this->is_valid_environment( $env ) ) {
			return $env;
		}
		return wp_get_environment_type();
	}
}
