<?php
/**
 * Who referred an order.
 *
 * 1. Landing: AffiWave redirects a partner link to the shop with `?aw_click=<32 hex>` (the program's attribution
 *    parameter must be `aw_click`). The token goes to a first-party cookie; the page is excluded from page cache.
 * 2. Account / checkout: the token (and an AffiWave partner coupon used in the order) is stored on the order and,
 *    once, on the customer — so subscription renewals, created later without any cookie, stay attributed.
 *    The first referral of a customer is kept; a later partner link does not take the customer over.
 * 3. Reporting asks for(): order token > order coupon > customer token > customer coupon.
 *
 * @package AffiWave\WooCommerce
 */

namespace AffiWave\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Attribution {

	public const COOKIE = 'affiwave_click';
	public const QUERY  = 'aw_click';

	public const ORDER_TOKEN       = '_affiwave_click_token';
	public const ORDER_COUPON      = '_affiwave_coupon';
	public const USER_TOKEN        = 'affiwave_click_token';
	public const USER_TOKEN_AT     = 'affiwave_click_at';
	public const USER_COUPON       = 'affiwave_coupon';
	public const USER_COUPON_AT    = 'affiwave_coupon_at';
	public const COUPON_ID_META    = '_affiwave_coupon_id';
	public const COUPON_CODE_META  = '_affiwave_code';

	public function __construct( private Settings $settings ) {
	}

	public function register(): void {
		add_action( 'init', array( $this, 'capture_click' ), 1 );
		add_action( 'user_register', array( $this, 'on_user_register' ) );
		add_action( 'woocommerce_checkout_order_created', array( $this, 'tag_order' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'tag_order' ) );
	}

	public static function is_token( string $value ): bool {
		return 1 === preg_match( '/^[a-f0-9]{32}$/', $value );
	}

	public function capture_click(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public tracking parameter, validated below.
		$token = isset( $_GET[ self::QUERY ] ) ? strtolower( trim( sanitize_text_field( wp_unslash( $_GET[ self::QUERY ] ) ) ) ) : '';
		if ( ! self::is_token( $token ) ) {
			return;
		}

		// A response that sets a cookie must never be served to other visitors from page cache.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // WP Rocket, W3TC, WP Super Cache, LiteSpeed Cache.
		}
		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'X-LiteSpeed-Cache-Control: no-cache' );
			setcookie(
				self::COOKIE,
				$token,
				array(
					'expires'  => time() + $this->settings->cookie_days() * DAY_IN_SECONDS,
					'path'     => '/',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}
		$_COOKIE[ self::COOKIE ] = $token;

		if ( is_user_logged_in() ) {
			$this->remember_token( get_current_user_id(), $token );
		}
	}

	public function cookie_token(): ?string {
		$token = isset( $_COOKIE[ self::COOKIE ] ) ? strtolower( sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) ) : '';

		return self::is_token( $token ) ? $token : null;
	}

	public function on_user_register( int $user_id ): void {
		$token = $this->cookie_token();
		if ( null !== $token ) {
			$this->remember_token( $user_id, $token );
		}
	}

	/** Store the referral on a new order (classic and block checkout). */
	public function tag_order( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$changed = false;

		$token = $this->cookie_token();
		if ( null !== $token && '' === (string) $order->get_meta( self::ORDER_TOKEN ) ) {
			$order->update_meta_data( self::ORDER_TOKEN, $token );
			$changed = true;
		}

		$coupon = $this->affiwave_coupon_in( $order );
		if ( null !== $coupon && '' === (string) $order->get_meta( self::ORDER_COUPON ) ) {
			$order->update_meta_data( self::ORDER_COUPON, $coupon );
			$changed = true;
		}

		if ( $changed ) {
			$order->save();
		}

		$customer_id = (int) $order->get_customer_id();
		if ( $customer_id > 0 ) {
			if ( null !== $token ) {
				$this->remember_token( $customer_id, $token );
			} elseif ( null !== $coupon ) {
				$this->remember_coupon( $customer_id, $coupon );
			}
		}
	}

	/**
	 * Referral to report for an order, or null.
	 *
	 * @return array{type: 'click_token'|'coupon', value: string, since: int|null}|null
	 */
	public function for_order( \WC_Order $order ): ?array {
		$token = (string) $order->get_meta( self::ORDER_TOKEN );
		if ( self::is_token( $token ) ) {
			return array( 'type' => 'click_token', 'value' => $token, 'since' => null );
		}
		$coupon = (string) $order->get_meta( self::ORDER_COUPON );
		if ( '' === $coupon ) {
			$coupon = (string) $this->affiwave_coupon_in( $order );
		}
		if ( '' !== $coupon ) {
			return array( 'type' => 'coupon', 'value' => $coupon, 'since' => null );
		}

		$referral = null;
		$user_id  = (int) $order->get_customer_id();
		if ( $user_id > 0 ) {
			$token = (string) get_user_meta( $user_id, self::USER_TOKEN, true );
			if ( self::is_token( $token ) ) {
				$referral = array( 'type' => 'click_token', 'value' => $token, 'since' => (int) get_user_meta( $user_id, self::USER_TOKEN_AT, true ) ?: null );
			} else {
				$coupon = (string) get_user_meta( $user_id, self::USER_COUPON, true );
				if ( '' !== $coupon ) {
					$referral = array( 'type' => 'coupon', 'value' => $coupon, 'since' => (int) get_user_meta( $user_id, self::USER_COUPON_AT, true ) ?: null );
				}
			}
		}

		/**
		 * Filters the referral of an order. Use it for orders without a customer account (e.g. guest renewals of a
		 * subscription plugin): return the referral of the parent order.
		 *
		 * @param array|null $referral Referral or null.
		 * @param \WC_Order  $order    The paid order.
		 */
		$referral = apply_filters( 'affiwave_wc_order_referral', $referral, $order );

		return is_array( $referral ) ? $referral : null;
	}

	/** AffiWave code (exact case as in AffiWave) of a partner coupon used in the order, if any. */
	public function affiwave_coupon_in( \WC_Order $order ): ?string {
		foreach ( $order->get_coupon_codes() as $code ) {
			$coupon_id = wc_get_coupon_id_by_code( $code );
			if ( $coupon_id > 0 && '' !== (string) get_post_meta( $coupon_id, self::COUPON_ID_META, true ) ) {
				$original = (string) get_post_meta( $coupon_id, self::COUPON_CODE_META, true );

				return '' !== $original ? $original : $code;
			}
		}

		return null;
	}

	private function remember_token( int $user_id, string $token ): void {
		if ( '' !== (string) get_user_meta( $user_id, self::USER_TOKEN, true ) || '' !== (string) get_user_meta( $user_id, self::USER_COUPON, true ) ) {
			return; // first referral wins
		}
		update_user_meta( $user_id, self::USER_TOKEN, $token );
		update_user_meta( $user_id, self::USER_TOKEN_AT, time() );
	}

	private function remember_coupon( int $user_id, string $code ): void {
		if ( '' !== (string) get_user_meta( $user_id, self::USER_TOKEN, true ) || '' !== (string) get_user_meta( $user_id, self::USER_COUPON, true ) ) {
			return;
		}
		update_user_meta( $user_id, self::USER_COUPON, $code );
		update_user_meta( $user_id, self::USER_COUPON_AT, time() );
	}
}
