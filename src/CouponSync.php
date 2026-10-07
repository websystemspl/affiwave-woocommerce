<?php
/**
 * Partner coupons: a coupon created or changed in AffiWave becomes a WooCommerce coupon with the same code.
 *
 * Sources: the `coupon.created` / `coupon.updated` webhooks (WebhookController) and, as a safety net, the hourly
 * `GET /api/coupon_codes?changedSince=` sync (also `wp affiwave sync-coupons`). Both are idempotent — the WooCommerce
 * coupon is found by the AffiWave coupon id stored on it.
 *
 * Mapping: percentage -> "percent", fixed -> "fixed_cart" (the AffiWave amount is gross, in the program currency);
 * status active -> published, expired/disabled -> draft (WooCommerce rejects unpublished coupons); usage limit and
 * expiry date 1:1. Coupons of other programs of the same AffiWave account are ignored when a program id is set.
 *
 * @package AffiWave\WooCommerce
 */

namespace AffiWave\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class CouponSync {

	public const ACTION      = 'affiwave_wc_sync_coupons';
	public const SYNCED_AT   = 'affiwave_wc_coupons_synced_at';
	public const PROGRAM_META = '_affiwave_program_id';

	/** @var array<int, int|null> campaign id => program id */
	private array $campaign_programs = array();

	public function __construct( private Settings $settings, private ApiClient $api ) {
	}

	public function register(): void {
		add_action( self::ACTION, array( $this, 'sync' ) );
	}

	/**
	 * Normalized coupon from a webhook `data` object or an API resource.
	 *
	 * @param array<string, mixed> $data
	 * @return array{id: int, code: string, type: string, value: string, currency: string, status: string, usage_limit: int|null, expires_at: string|null, program_id: int|null, campaign_id: int|null, affiliate_id: int|null}|null
	 */
	public static function normalize( array $data ): ?array {
		$id   = (int) ( $data['coupon_id'] ?? $data['id'] ?? 0 );
		$code = trim( (string) ( $data['code'] ?? '' ) );
		if ( $id <= 0 || '' === $code ) {
			return null;
		}

		return array(
			'id'           => $id,
			'code'         => $code,
			'type'         => (string) ( $data['discount_type'] ?? $data['discountType'] ?? 'percentage' ),
			'value'        => Money::normalize( (string) ( $data['discount_value'] ?? $data['discountValue'] ?? '0' ) ),
			'currency'     => strtoupper( (string) ( $data['currency'] ?? '' ) ),
			'status'       => (string) ( $data['status'] ?? 'active' ),
			'usage_limit'  => isset( $data['usage_limit'] ) || isset( $data['usageLimit'] ) ? (int) ( $data['usage_limit'] ?? $data['usageLimit'] ) : null,
			'expires_at'   => ( $data['expires_at'] ?? $data['expiresAt'] ?? null ) ?: null,
			'program_id'   => isset( $data['program_id'] ) ? (int) $data['program_id'] : null,
			'campaign_id'  => self::id_of( $data['campaign_id'] ?? $data['campaign'] ?? null ),
			'affiliate_id' => self::id_of( $data['affiliate_id'] ?? $data['affiliate'] ?? null ),
		);
	}

	/** WooCommerce coupon properties for an AffiWave coupon. */
	public static function woo_props( array $coupon, int $now ): array {
		$expires = null !== $coupon['expires_at'] ? strtotime( (string) $coupon['expires_at'] ) : false;
		$active  = 'active' === $coupon['status'] && ( false === $expires || $expires > $now );

		return array(
			'discount_type' => 'fixed' === $coupon['type'] ? 'fixed_cart' : 'percent',
			'amount'        => $coupon['value'],
			'usage_limit'   => null !== $coupon['usage_limit'] && $coupon['usage_limit'] > 0 ? $coupon['usage_limit'] : 0,
			'date_expires'  => false !== $expires ? $expires : null,
			'post_status'   => $active ? 'publish' : 'draft',
		);
	}

	/** Program filter: true when the coupon belongs to the configured program (or no program is configured). */
	public function belongs_to_program( array $coupon ): bool {
		$program_id = $this->settings->program_id();
		if ( null === $program_id ) {
			return true;
		}
		$coupon_program = $coupon['program_id'] ?? null;
		if ( null === $coupon_program && null !== $coupon['campaign_id'] ) {
			$coupon_program = $this->program_of_campaign( (int) $coupon['campaign_id'] );
		}

		return $coupon_program === $program_id;
	}

	/**
	 * Create or update the WooCommerce coupon. Returns the coupon id or a WP_Error (code_taken = the code is used by a
	 * coupon that does not come from AffiWave; it is never overwritten).
	 *
	 * @return int|\WP_Error
	 */
	public function upsert( array $coupon ) {
		$coupon_id = $this->find( (int) $coupon['id'] );
		$by_code   = wc_get_coupon_id_by_code( $coupon['code'] );
		if ( $by_code > 0 && $by_code !== $coupon_id ) {
			if ( (string) get_post_meta( $by_code, Attribution::COUPON_ID_META, true ) === '' ) {
				return new \WP_Error( 'code_taken', sprintf( 'Coupon code "%s" is already used by a coupon that does not come from AffiWave.', $coupon['code'] ) );
			}
		}

		$props = self::woo_props( $coupon, time() );
		$woo   = new \WC_Coupon( $coupon_id );
		$woo->set_code( $coupon['code'] );
		$woo->set_discount_type( $props['discount_type'] );
		$woo->set_amount( $props['amount'] );
		$woo->set_usage_limit( $props['usage_limit'] );
		$woo->set_date_expires( $props['date_expires'] );
		$woo->set_description(
			sprintf(
				/* translators: 1: AffiWave coupon id, 2: partner id */
				__( 'AffiWave partner coupon #%1$d (partner #%2$s). Managed in AffiWave — changes made here are overwritten.', 'affiwave-woocommerce' ),
				$coupon['id'],
				null !== $coupon['affiliate_id'] ? (string) $coupon['affiliate_id'] : '?'
			)
		);
		$woo->update_meta_data( Attribution::COUPON_ID_META, (string) $coupon['id'] );
		$woo->update_meta_data( Attribution::COUPON_CODE_META, $coupon['code'] );
		if ( null !== $coupon['program_id'] ) {
			$woo->update_meta_data( self::PROGRAM_META, (string) $coupon['program_id'] );
		}
		if ( '' !== $coupon['currency'] && 'fixed' === $coupon['type'] && $coupon['currency'] !== get_woocommerce_currency() ) {
			$this->log( sprintf( 'Coupon %s: fixed amount is in %s, the shop currency is %s — check the amount.', $coupon['code'], $coupon['currency'], get_woocommerce_currency() ) );
		}
		$id = $woo->save();

		if ( get_post_status( $id ) !== $props['post_status'] ) {
			wp_update_post( array( 'ID' => $id, 'post_status' => $props['post_status'] ) );
		}

		return (int) $id;
	}

	/**
	 * Safety-net sync from the API since the last stamp (inclusive, so records on the boundary come again).
	 *
	 * @return array{fetched: int, saved: int, ignored: int, errors: int}
	 */
	public function sync( ?string $since = null ): array {
		$stats = array( 'fetched' => 0, 'saved' => 0, 'ignored' => 0, 'errors' => 0 );
		if ( ! $this->settings->is_enabled() ) {
			return $stats;
		}
		$since = $since ?? (string) get_option( self::SYNCED_AT, '1970-01-01T00:00:00+00:00' );
		$max   = $since;

		for ( $page = 1; $page <= 100; $page++ ) {
			$response = $this->api->get_coupon_codes( $since, $page );
			if ( ! $response->ok() || ! is_array( $response->data ) ) {
				$this->log( 'Coupon sync failed: ' . $response->summary() );
				++$stats['errors'];
				break;
			}
			$items = isset( $response->data['hydra:member'] ) ? $response->data['hydra:member'] : ( isset( $response->data['member'] ) ? $response->data['member'] : $response->data );
			if ( array() === $items ) {
				break;
			}
			foreach ( $items as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				++$stats['fetched'];
				$changed = (string) ( $item['updatedAt'] ?? $item['createdAt'] ?? '' );
				if ( '' !== $changed && strtotime( $changed ) > strtotime( $max ) ) {
					$max = $changed;
				}
				$coupon = self::normalize( $item );
				if ( null === $coupon || ! $this->belongs_to_program( $coupon ) ) {
					++$stats['ignored'];
					continue;
				}
				$result = $this->upsert( $coupon );
				if ( is_wp_error( $result ) ) {
					$this->log( 'Coupon sync: ' . $result->get_error_message() );
					++$stats['errors'];
				} else {
					++$stats['saved'];
				}
			}
			if ( count( $items ) < 50 ) {
				break;
			}
		}

		if ( 0 === $stats['errors'] ) {
			update_option( self::SYNCED_AT, $max, false );
		}

		return $stats;
	}

	private function find( int $affiwave_id ): int {
		$ids = get_posts(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => Attribution::COUPON_ID_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => (string) $affiwave_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		return isset( $ids[0] ) ? (int) $ids[0] : 0;
	}

	private function program_of_campaign( int $campaign_id ): ?int {
		if ( ! array_key_exists( $campaign_id, $this->campaign_programs ) ) {
			$response = $this->api->get( '/api/campaigns/' . $campaign_id );
			$this->campaign_programs[ $campaign_id ] = $response->ok() && is_array( $response->data )
				? self::id_of( $response->data['program'] ?? null )
				: null;
		}

		return $this->campaign_programs[ $campaign_id ];
	}

	/** Id from an int, a numeric string, an IRI ("/api/campaigns/7") or an embedded object. */
	private static function id_of( $value ): ?int {
		if ( is_array( $value ) ) {
			$value = $value['id'] ?? $value['@id'] ?? null;
		}
		if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) {
			return (int) $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '#/(\d+)$#', $value, $m ) ) {
			return (int) $m[1];
		}

		return null;
	}

	private function log( string $message ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning( $message, array( 'source' => 'affiwave' ) );
		}
	}
}
