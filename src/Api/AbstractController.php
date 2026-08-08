<?php
/**
 * Base class for Myrk REST API controllers.
 *
 * @package Myrk
 */

declare( strict_types=1 );

namespace Myrk\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;

/**
 * Provides shared permission checks, formatting helpers, and error responses for all Myrk REST controllers.
 */
abstract class AbstractController extends WP_REST_Controller {

	/**
	 * REST API namespace for all Myrk endpoints.
	 *
	 * @var string
	 */
	protected $namespace = 'myrk/v1';

	// -------------------------------------------------------------------------
	// Shared permission check
	// -------------------------------------------------------------------------

	/**
	 * Permission callback for all authenticated endpoints.
	 *
	 * @param WP_REST_Request $request The incoming REST request.
	 * @return bool|\WP_Error True when authorised, WP_Error on failure.
	 */
	public function require_manage_flags( WP_REST_Request $request ): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'myrk_forbidden',
				__( 'You do not have permission to manage feature flags.', 'myrk' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	// -------------------------------------------------------------------------
	// Date / time formatting
	// -------------------------------------------------------------------------

	/**
	 * Convert a MySQL UTC datetime to ISO 8601 with Z suffix.
	 * Dates stored via current_time('mysql', true) are already UTC.
	 *
	 * @param string|null $mysql_date MySQL datetime string, or null.
	 * @return string|null
	 */
	protected function format_datetime( ?string $mysql_date ): ?string {
		if ( ! $mysql_date || '0000-00-00 00:00:00' === $mysql_date ) {
			return null;
		}
		return str_replace( ' ', 'T', $mysql_date ) . 'Z';
	}

	// -------------------------------------------------------------------------
	// Common error responses
	// -------------------------------------------------------------------------

	/**
	 * Return a 404 WP_Error for a flag that does not exist.
	 *
	 * @param string $flag_key The flag key that was not found.
	 * @return \WP_Error
	 */
	protected function error_flag_not_found( string $flag_key ): WP_Error {
		return new WP_Error(
			'myrk_flag_not_found',
			/* translators: %s: flag key */
			sprintf( __( 'Flag "%s" not found.', 'myrk' ), $flag_key ),
			[ 'status' => 404 ]
		);
	}

	/**
	 * Return a 422 WP_Error for an unrecognized environment name.
	 *
	 * @param string $env The invalid environment name.
	 * @return \WP_Error
	 */
	protected function error_invalid_environment( string $env ): WP_Error {
		return new WP_Error(
			'myrk_invalid_environment',
			/* translators: %s: environment name */
			sprintf( __( 'Invalid environment "%s". Must be one of: production, staging, development, local.', 'myrk' ), $env ),
			[ 'status' => 422 ]
		);
	}

	// -------------------------------------------------------------------------
	// Environment validation
	// -------------------------------------------------------------------------

	protected const VALID_ENVIRONMENTS = [ 'production', 'staging', 'development', 'local' ];

	/**
	 * Return true when the given environment name is one of the valid values.
	 *
	 * @param string $env Environment name to validate.
	 * @return bool
	 */
	protected function is_valid_environment( string $env ): bool {
		return in_array( $env, self::VALID_ENVIRONMENTS, true );
	}

	// -------------------------------------------------------------------------
	// Response helper
	// -------------------------------------------------------------------------

	/**
	 * Format a target row for API responses.
	 *
	 * @param object $row Target database row.
	 * @return array<string, mixed>
	 */
	protected function format_target( object $row ): array {
		return [
			'id'         => (int) $row->id,
			'type'       => $row->type,
			'operator'   => $row->operator,
			'value'      => $row->value,
			'enabled'    => (bool) $row->enabled,
			'sort_order' => (int) $row->sort_order,
		];
	}

	/**
	 * Format an environment state for API responses.
	 *
	 * @param object|null $env_row  Environment state row, or null when no row exists.
	 * @param string      $env_name Environment name to include in the response.
	 * @return array<string, mixed>
	 */
	protected function format_env_state( ?object $env_row, string $env_name ): array {
		if ( null === $env_row ) {
			return [
				'name'                    => $env_name,
				'status'                  => 'disabled',
				'percentage'              => 0,
				'anonymous_strategy'      => 'ip',
				'circuit_breaker_tripped' => false,
				'updated_at'              => null,
			];
		}
		return [
			'name'                    => $env_name,
			'status'                  => 1 === (int) $env_row->status ? 'enabled' : 'disabled',
			'percentage'              => (int) $env_row->rollout_percentage,
			'anonymous_strategy'      => $env_row->anonymous_strategy,
			'circuit_breaker_tripped' => (bool) $env_row->circuit_breaker_tripped,
			'updated_at'              => $this->format_datetime(
				property_exists( $env_row, 'env_updated_at' ) ? $env_row->env_updated_at : $env_row->updated_at
			),
		];
	}
}
