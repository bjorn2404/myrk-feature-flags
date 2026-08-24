<?php
/**
 * REST controller for per-environment flag state.
 *
 * @package   Myrk
 * @author    Bjorn Holine <bjorn@myrk.build>
 * @license   GPL-2.0-or-later
 * @link      https://myrk.build/
 * @copyright 2026 Bjorn Holine
 */

declare( strict_types=1 );

namespace Myrk\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Myrk\ChangedVia;
use Myrk\Database\FlagRepository;
use Myrk\Database\StateWriter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST controller for per-environment flag state.
 *
 * Routes:
 *   GET   /myrk/v1/flags/{flag_key}/environments
 *   PATCH /myrk/v1/flags/{flag_key}/environments/{env}
 */
class EnvironmentsController extends AbstractController {

	/**
	 * Register routes for listing and updating environment state.
	 */
	public function register_routes(): void {
		$flag_segment = '/flags/(?P<flag_key>[a-zA-Z0-9_-]+)';

		register_rest_route(
			$this->namespace,
			$flag_segment . '/environments',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => [ $this, 'require_manage_flags' ],
			]
		);

		register_rest_route(
			$this->namespace,
			$flag_segment . '/environments/(?P<env>[a-z]+)',
			[
				'methods'             => 'PATCH',
				'callback'            => [ $this, 'update_item' ],
				'permission_callback' => [ $this, 'require_manage_flags' ],
				'args'                => [
					'status'             => [
						'type' => 'string',
						'enum' => [ 'enabled', 'disabled' ],
					],
					'percentage'         => [
						'type'    => 'integer',
						'minimum' => 0,
						'maximum' => 100,
					],
					'anonymous_strategy' => [
						'type' => 'string',
						'enum' => [ 'ip', 'session', 'device' ],
					],
					'note'               => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					],
					'git_sha'            => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	// -------------------------------------------------------------------------
	// GET /myrk/v1/flags/{flag_key}/environments
	// -------------------------------------------------------------------------

	/**
	 * Return all environment states for a flag.
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

		$env_rows = FlagRepository::get_with_all_environments( $flag_key );
		$result   = [];

		if ( null !== $env_rows ) {
			foreach ( $env_rows['environments'] as $env_row ) {
				$result[ $env_row->environment ] = $this->format_env_state( $env_row, $env_row->environment );
			}
		}

		return rest_ensure_response( $result );
	}

	// -------------------------------------------------------------------------
	// PATCH /myrk/v1/flags/{flag_key}/environments/{env}
	// -------------------------------------------------------------------------

	/**
	 * Update the state of one environment for a flag.
	 *
	 * @param WP_REST_Request $request The incoming REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ): WP_REST_Response|WP_Error {
		$flag_key = $request->get_param( 'flag_key' );
		$env      = $request->get_param( 'env' );

		if ( ! $this->is_valid_environment( $env ) ) {
			return $this->error_invalid_environment( $env );
		}

		if ( null === FlagRepository::get_flag_id( $flag_key ) ) {
			return $this->error_flag_not_found( $flag_key );
		}

		$changes = [];
		$status  = $request->get_param( 'status' );
		if ( null !== $status ) {
			$changes['status'] = 'enabled' === $status ? 1 : 0;
		}

		$percentage = $request->get_param( 'percentage' );
		if ( null !== $percentage ) {
			$changes['rollout_percentage'] = max( 0, min( 100, (int) $percentage ) );
		}

		$strategy = $request->get_param( 'anonymous_strategy' );
		if ( null !== $strategy ) {
			$changes['anonymous_strategy'] = $strategy;
		}

		if ( empty( $changes ) ) {
			return new WP_Error(
				'myrk_no_changes',
				__( 'No valid fields provided to update.', 'myrk-feature-flags' ),
				[ 'status' => 422 ]
			);
		}

		$uid = get_current_user_id();
		StateWriter::upsert_environment_state(
			flag_key:    $flag_key,
			environment: $env,
			changes:     $changes,
			changed_via: ChangedVia::Api,
			changed_by:  $uid ? $uid : null,
			note:        $request->get_param( 'note' ),
			git_sha:     $request->get_param( 'git_sha' )
		);

		$env_state = FlagRepository::get_environment_state( $flag_key, $env );
		return rest_ensure_response( $this->format_env_state( $env_state, $env ) );
	}
}
