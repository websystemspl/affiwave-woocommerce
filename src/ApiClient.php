<?php
/**
 * Thin client of the AffiWave REST API (Bearer API key). Never throws: every call returns an ApiResponse.
 *
 * Scopes the key needs: `conversions:write` (conversions, refunds) and `reports:read` (coupon sync).
 *
 * @package AffiWave\WooCommerce
 */

namespace AffiWave\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class ApiClient {

	public function __construct( private Settings $settings ) {
	}

	/** @param array<string, mixed> $payload */
	public function post_conversion( array $payload ): ApiResponse {
		return $this->request( 'POST', '/api/conversion', $payload );
	}

	public function post_refund( string $external_order_id ): ApiResponse {
		return $this->request( 'POST', '/api/conversion/refund', array( 'external_order_id' => $external_order_id ) );
	}

	/** Coupons created or changed since $since (inclusive), 50 per page. */
	public function get_coupon_codes( string $since, int $page ): ApiResponse {
		$query = http_build_query(
			array(
				'changedSince' => $since,
				'page'         => $page,
			)
		);

		return $this->request( 'GET', '/api/coupon_codes?' . $query );
	}

	public function get( string $path ): ApiResponse {
		return $this->request( 'GET', $path );
	}

	/** @param array<string, mixed>|null $body */
	private function request( string $method, string $path, ?array $body = null ): ApiResponse {
		if ( ! $this->settings->is_enabled() ) {
			return new ApiResponse( 0, null, 'disabled' );
		}
		$args = array(
			'method'     => $method,
			'timeout'    => 8,
			'user-agent' => 'AffiWave-WooCommerce/' . AFFIWAVE_WC_VERSION . '; ' . home_url(),
			'headers'    => array(
				'Authorization' => 'Bearer ' . $this->settings->get( 'api_key' ),
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $this->settings->api_url() . $path, $args );
		if ( is_wp_error( $response ) ) {
			return new ApiResponse( 0, null, $response->get_error_message() );
		}
		$status  = (int) wp_remote_retrieve_response_code( $response );
		$raw     = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );

		return new ApiResponse( $status, is_array( $decoded ) ? $decoded : null, $status >= 200 && $status < 300 ? null : mb_substr( $raw, 0, 500 ) );
	}
}
