<?php
/**
 * `POST /wp-json/affiwave/v1/webhook` — AffiWave webhooks. Handled: `coupon.created`, `coupon.updated`;
 * other events are acknowledged (200) and ignored.
 *
 * Responses: 400 bad signature or body, 503 no webhook secret configured, 409 the code is taken by a non-AffiWave
 * coupon, 500 coupon could not be saved (AffiWave retries 4xx/5xx).
 *
 * @package AffiWave\WooCommerce
 */

namespace AffiWave\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class WebhookController {

	public const NAMESPACE = 'affiwave/v1';
	public const ROUTE     = '/webhook';

	public function __construct( private Settings $settings, private CouponSync $coupons ) {
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public static function url(): string {
		return rest_url( self::NAMESPACE . self::ROUTE );
	}

	public function routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true', // authenticated by the HMAC signature
			)
		);
	}

	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$secret = $this->settings->get( 'webhook_secret' );
		if ( '' === $secret ) {
			return new \WP_REST_Response( array( 'error' => 'webhook_secret_not_configured' ), 503 );
		}
		$raw = (string) $request->get_body();
		if ( ! Signature::verify( (string) $request->get_header( 'x_affiwave_signature' ), $raw, $secret, time() ) ) {
			return new \WP_REST_Response( array( 'error' => 'invalid_signature' ), 400 );
		}
		$body = json_decode( $raw, true );
		if ( ! is_array( $body ) ) {
			return new \WP_REST_Response( array( 'error' => 'invalid_body' ), 400 );
		}

		$event = (string) ( $body['event'] ?? $request->get_header( 'x_affiwave_event' ) ?? '' );
		if ( ! in_array( $event, array( 'coupon.created', 'coupon.updated' ), true ) ) {
			return new \WP_REST_Response( array( 'ignored' => $event ), 200 );
		}
		$coupon = CouponSync::normalize( is_array( $body['data'] ?? null ) ? $body['data'] : array() );
		if ( null === $coupon ) {
			return new \WP_REST_Response( array( 'error' => 'invalid_coupon' ), 400 );
		}
		if ( ! $this->coupons->belongs_to_program( $coupon ) ) {
			return new \WP_REST_Response( array( 'ignored' => 'other_program' ), 200 );
		}

		$result = $this->coupons->upsert( $coupon );
		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response( array( 'error' => $result->get_error_code(), 'message' => $result->get_error_message() ), 'code_taken' === $result->get_error_code() ? 409 : 500 );
		}

		return new \WP_REST_Response( array( 'coupon_id' => $result ), 200 );
	}
}
