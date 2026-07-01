<?php

declare( strict_types=1 );

namespace Myrk\Pro;

class License {

	private const CACHE_TTL    = DAY_IN_SECONDS;
	private const GRACE_PERIOD = 30 * DAY_IN_SECONDS;
	private const STORE_URL    = 'https://myrk.build';
	private const ITEM_NAME    = 'Myrk Pro';

	public static function is_valid(): bool {
		$status = get_option( 'myrk_license_status' );

		if ( $status === 'valid' ) {
			return true;
		}

		if ( $status === 'expired' ) {
			$expiry = get_option( 'myrk_license_expiry' );
			if ( $expiry && ( time() - strtotime( (string) $expiry ) ) < self::GRACE_PERIOD ) {
				return true;
			}
		}

		return false;
	}

	public static function is_in_grace_period(): bool {
		if ( get_option( 'myrk_license_status' ) !== 'expired' ) {
			return false;
		}
		$expiry = get_option( 'myrk_license_expiry' );
		return $expiry && ( time() - strtotime( (string) $expiry ) ) < self::GRACE_PERIOD;
	}

	public static function days_remaining_in_grace(): int {
		$expiry = get_option( 'myrk_license_expiry' );
		if ( ! $expiry ) {
			return 0;
		}
		$elapsed = time() - strtotime( (string) $expiry );
		return max( 0, 30 - (int) floor( $elapsed / DAY_IN_SECONDS ) );
	}

	public static function maybe_refresh(): void {
		$checked_at = (int) get_option( 'myrk_license_checked_at', 0 );
		if ( time() - $checked_at < self::CACHE_TTL ) {
			return;
		}
		self::check();
	}

	public static function check(): void {
		$key = get_option( 'myrk_license_key' );
		if ( ! $key ) {
			return;
		}

		$response = wp_remote_get(
			add_query_arg(
				[
					'edd_action' => 'check_license',
					'license'    => $key,
					'item_name'  => self::ITEM_NAME,
					'url'        => home_url(),
				],
				self::STORE_URL
			),
			[ 'timeout' => 15 ]
		);

		if ( is_wp_error( $response ) ) {
			update_option( 'myrk_license_checked_at', time() );
			return;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ) );

		update_option( 'myrk_license_status',    $data->license ?? 'invalid' );
		update_option( 'myrk_license_expiry',     $data->expires ?? '' );
		update_option( 'myrk_license_checked_at', time() );
	}
}
