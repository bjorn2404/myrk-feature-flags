<?php
/**
 * REST controller for public flag evaluation.
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

use Myrk\Evaluator;
use Myrk\Registry;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST controller for public flag evaluation.
 *
 * Routes:
 *   GET /myrk/v1/evaluate/{flag_key}  — unauthenticated, 60 req/min per IP
 */
class EvaluateController extends AbstractController {

	/**
	 * REST base for the evaluate endpoint.
	 *
	 * @var string
	 */
	protected $rest_base = 'evaluate';

	/** Maximum requests per IP per rate-limit window. */
	private const RATE_LIMIT = 60;

	/** Rate-limit window in seconds. */
	private const WINDOW = 60;

	/**
	 * Register the public evaluate endpoint.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<flag_key>[a-zA-Z0-9_-]+)',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_item' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	// -------------------------------------------------------------------------
	// GET /myrk/v1/evaluate/{flag_key}
	// -------------------------------------------------------------------------

	/**
	 * Evaluate a flag for the current request context and return the result.
	 *
	 * @param WP_REST_Request $request The incoming REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ): WP_REST_Response|WP_Error {
		// Rate limit first so malicious callers can't enumerate registered flags.
		$rate_check = $this->check_rate_limit();
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$flag_key = $request->get_param( 'flag_key' );

		if ( ! Registry::has( $flag_key ) ) {
			return new WP_Error(
				'myrk_flag_not_found',
				/* translators: %s: flag key */
				sprintf( __( 'Flag "%s" is not registered.', 'myrk-feature-flags' ), $flag_key ),
				[ 'status' => 404 ]
			);
		}

		$user    = null;
		$current = wp_get_current_user();
		if ( $current->ID > 0 ) {
			$user = $current;
		}

		$enabled = Evaluator::evaluate( $flag_key, $user, false );

		$response = rest_ensure_response(
			[
				'flag_key' => $flag_key,
				'enabled'  => $enabled,
			]
		);

		// Public endpoint — tell caches not to store this per-user response.
		$response->header( 'Cache-Control', 'no-store, no-cache' );

		return $response;
	}

	// -------------------------------------------------------------------------
	// Rate limiting
	// -------------------------------------------------------------------------

	/**
	 * Transient-based rate limit: max RATE_LIMIT requests per IP per WINDOW seconds.
	 * Uses integer counter stored as a transient; new window opens when transient expires.
	 *
	 * Note: transient-backed rate limiting has race conditions under high concurrency.
	 * For production high-traffic sites, replace with a Redis-backed counter.
	 */
	private function check_rate_limit(): bool|WP_Error {
		$ip  = $this->client_ip();
		$key = 'myrk_rl_' . md5( $ip );

		$count = (int) get_transient( $key );

		if ( $count >= self::RATE_LIMIT ) {
			return new WP_Error(
				'myrk_rate_limit_exceeded',
				__( 'Rate limit exceeded. Maximum 60 requests per minute.', 'myrk-feature-flags' ),
				[ 'status' => 429 ]
			);
		}

		// First request in this window: set with TTL. Subsequent requests: increment.
		if ( 0 === $count ) {
			set_transient( $key, 1, self::WINDOW );
		} else {
			set_transient( $key, $count + 1, self::WINDOW );
		}

		return true;
	}

	/**
	 * Return the client IP, respecting common reverse-proxy headers.
	 * Trusts X-Forwarded-For only when the connecting IP is in the local/loopback range
	 * so the header cannot be spoofed from the public internet.
	 */
	private function client_ip(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		if ( $this->is_trusted_proxy( $remote ) ) {
			$forwarded = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) : '';
			if ( '' !== $forwarded ) {
				$parts = array_map( 'trim', explode( ',', $forwarded ) );
				return $parts[0];
			}
		}

		return $remote;
	}

	/**
	 * Return true when the connecting IP belongs to a trusted local/proxy range.
	 *
	 * @param string $ip IP address to check.
	 * @return bool
	 */
	private function is_trusted_proxy( string $ip ): bool {
		return '127.0.0.1' === $ip
			|| '::1' === $ip
			|| str_starts_with( $ip, '10.' )
			|| str_starts_with( $ip, '172.16.' )
			|| str_starts_with( $ip, '192.168.' );
	}
}
