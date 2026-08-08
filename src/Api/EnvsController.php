<?php
/**
 * REST controller for cross-environment flag state operations.
 *
 * @package Myrk
 */

declare( strict_types=1 );

namespace Myrk\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Myrk\ChangedVia;
use Myrk\Database\StateWriter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST controller for cross-environment operations.
 *
 * Routes:
 *   POST /myrk/v1/envs/copy
 */
class EnvsController extends AbstractController {

	/**
	 * Register routes for cross-environment copy operations.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/envs/copy',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'copy' ],
				'permission_callback' => [ $this, 'require_manage_flags' ],
				'args'                => [
					'from'    => [
						'required' => true,
						'type'     => 'string',
						'enum'     => self::VALID_ENVIRONMENTS,
					],
					'to'      => [
						'required' => true,
						'type'     => 'string',
						'enum'     => self::VALID_ENVIRONMENTS,
					],
					'flags'   => [
						'type'  => 'array',
						'items' => [ 'type' => 'string' ],
					],
					'dry_run' => [
						'type'    => 'boolean',
						'default' => false,
					],
				],
			]
		);
	}

	// -------------------------------------------------------------------------
	// POST /myrk/v1/envs/copy
	// -------------------------------------------------------------------------

	/**
	 * Copy flag states from one environment to another.
	 *
	 * @param WP_REST_Request $request The incoming REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function copy( $request ): WP_REST_Response|WP_Error {
		$from    = $request->get_param( 'from' );
		$to      = $request->get_param( 'to' );
		$dry_run = (bool) $request->get_param( 'dry_run' );

		if ( $from === $to ) {
			return new WP_Error(
				'myrk_same_environment',
				__( '"from" and "to" environments must differ.', 'myrk' ),
				[ 'status' => 422 ]
			);
		}

		// Optional list of flag keys to filter the copy operation.
		$flag_filter = $request->get_param( 'flags' );

		global $wpdb;

		// Fetch all env state rows for the source environment.
		$source_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT f.flag_key, fle.status, fle.rollout_percentage, fle.anonymous_strategy
				 FROM {$wpdb->prefix}myrk_flag_environments fle
				 INNER JOIN {$wpdb->prefix}myrk_flags f ON f.id = fle.flag_id
				 WHERE fle.environment = %s",
				$from
			)
		);

		if ( ! is_array( $source_rows ) ) {
			$source_rows = [];
		}

		$applied = [];
		$skipped = [];
		$uid     = get_current_user_id();
		$user_id = $uid ? $uid : null;

		foreach ( $source_rows as $row ) {
			if ( null !== $flag_filter && ! in_array( $row->flag_key, $flag_filter, true ) ) {
				$skipped[] = $row->flag_key;
				continue;
			}

			if ( ! $dry_run ) {
				StateWriter::upsert_environment_state(
					flag_key:    $row->flag_key,
					environment: $to,
					changes:     [
						'status'             => (int) $row->status,
						'rollout_percentage' => (int) $row->rollout_percentage,
						'anonymous_strategy' => $row->anonymous_strategy,
					],
					changed_via: ChangedVia::Api,
					changed_by:  $user_id,
					note:        "Copied from {$from} environment."
				);
			}

			$applied[] = [
				'flag_key'   => $row->flag_key,
				'status'     => 1 === (int) $row->status ? 'enabled' : 'disabled',
				'percentage' => (int) $row->rollout_percentage,
			];
		}

		return rest_ensure_response(
			[
				'dry_run' => $dry_run,
				'from'    => $from,
				'to'      => $to,
				'applied' => $applied,
				'skipped' => $skipped,
			]
		);
	}
}
