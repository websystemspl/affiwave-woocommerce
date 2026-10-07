<?php
/**
 * Reports every paid order — the first one and each subscription renewal (WooCommerce Subscriptions, SUMO and any
 * plugin that creates a regular order per renewal) — to AffiWave as a server-to-server conversion.
 *
 * - amount: net (order total minus its tax), in the order currency;
 * - external_order_id: "<prefix><order id>", so AffiWave de-duplicates repeats (200 duplicate);
 * - sent in the background (Action Scheduler), never during checkout; a stamp on the order stops repeats;
 * - network or 5xx errors are retried by the hourly catch-up (and `wp affiwave report`); a 4xx is final and noted.
 * - refunds: a fully refunded order that was reported is refunded in AffiWave too.
 *
 * @package AffiWave\WooCommerce
 */

namespace AffiWave\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class ConversionReporter {

	public const ACTION        = 'affiwave_wc_report_order';
	public const REFUND_ACTION = 'affiwave_wc_refund_order';
	public const GROUP         = 'affiwave';

	public const REPORTED_AT   = '_affiwave_reported_at';
	public const CONVERSION_ID = '_affiwave_conversion_id';
	public const FAILED        = '_affiwave_failed';
	public const REFUNDED_AT   = '_affiwave_refunded_at';

	public function __construct( private Settings $settings, private ApiClient $api, private Attribution $attribution ) {
	}

	public function register(): void {
		add_action( 'woocommerce_payment_complete', array( $this, 'queue' ) );
		add_action( 'woocommerce_order_status_processing', array( $this, 'queue' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'queue' ) );
		add_action( 'woocommerce_order_fully_refunded', array( $this, 'queue_refund' ) );
		add_action( self::ACTION, array( $this, 'report' ) );
		add_action( self::REFUND_ACTION, array( $this, 'refund' ) );
	}

	public function queue( $order_id ): void {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 || ! $this->settings->is_enabled() ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || '' !== (string) $order->get_meta( self::REPORTED_AT ) ) {
			return;
		}
		$this->enqueue( self::ACTION, $order_id );
	}

	public function queue_refund( $order_id ): void {
		$order_id = (int) $order_id;
		if ( $order_id > 0 && $this->settings->is_enabled() ) {
			$this->enqueue( self::REFUND_ACTION, $order_id );
		}
	}

	/**
	 * Report one order now. Returns what happened: reported, duplicate, skipped:<reason>, failed:<reason>, retry:<reason>.
	 */
	public function report( $order_id ): string {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order || $order instanceof \WC_Order_Refund ) {
			return 'skipped:not_an_order';
		}
		if ( ! $this->settings->is_enabled() ) {
			return 'skipped:disabled';
		}
		if ( '' !== (string) $order->get_meta( self::REPORTED_AT ) ) {
			return 'skipped:already_reported';
		}
		if ( '' !== (string) $order->get_meta( self::FAILED ) ) {
			return 'skipped:failed_before';
		}
		if ( ! $order->is_paid() ) {
			return 'skipped:not_paid';
		}

		$referral = $this->attribution->for_order( $order );
		if ( null === $referral ) {
			return 'skipped:no_referral';
		}
		$paid_at = $order->get_date_paid() ?? $order->get_date_created();
		if ( null !== $referral['since'] && null !== $paid_at && $paid_at->getTimestamp() < $referral['since'] ) {
			return 'skipped:paid_before_referral';
		}

		$amount = Money::net( $order->get_total(), $order->get_total_tax() );
		if ( '0.00' === $amount ) {
			return 'skipped:zero_amount';
		}

		$payload = array(
			$referral['type']   => $referral['value'],
			'amount'            => $amount,
			'currency'          => strtoupper( $order->get_currency() ),
			'external_order_id' => $this->external_id( $order ),
		);
		if ( null !== $paid_at ) {
			$payload['occurred_at'] = $paid_at->format( DATE_ATOM );
		}
		$email = trim( (string) $order->get_billing_email() );
		if ( '' !== $email ) {
			$payload['customer_email'] = $email;
		}

		/**
		 * Filters the conversion sent to AffiWave (`POST /api/conversion`), e.g. to change the amount basis.
		 *
		 * @param array     $payload Conversion payload.
		 * @param \WC_Order $order   The paid order.
		 */
		$payload = apply_filters( 'affiwave_wc_conversion_payload', $payload, $order );

		$response = $this->api->post_conversion( $payload );
		if ( $response->ok() ) {
			$duplicate = ! empty( $response->data['duplicate'] );
			$order->update_meta_data( self::REPORTED_AT, gmdate( DATE_ATOM ) );
			if ( isset( $response->data['conversion_id'] ) ) {
				$order->update_meta_data( self::CONVERSION_ID, (string) $response->data['conversion_id'] );
			}
			$order->save();
			$order->add_order_note(
				sprintf(
					/* translators: 1: net amount with currency, 2: AffiWave conversion id */
					__( 'AffiWave: conversion reported (%1$s net), conversion #%2$s.', 'affiwave-woocommerce' ),
					$payload['amount'] . ' ' . $payload['currency'],
					(string) ( $response->data['conversion_id'] ?? '?' )
				)
			);

			return $duplicate ? 'duplicate' : 'reported';
		}

		$this->log( 'Conversion for order ' . $order->get_id() . ' not accepted: ' . $response->summary() );
		if ( $response->retryable() ) {
			return 'retry:' . $response->summary();
		}
		$order->update_meta_data( self::FAILED, $response->summary() );
		$order->save();
		$order->add_order_note(
			sprintf(
				/* translators: %s: error from AffiWave, e.g. "HTTP 422 unknown_click_token" */
				__( 'AffiWave: conversion rejected (%s). It will not be retried.', 'affiwave-woocommerce' ),
				$response->summary()
			)
		);

		return 'failed:' . $response->summary();
	}

	public function refund( $order_id ): string {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order || '' === (string) $order->get_meta( self::REPORTED_AT ) || '' !== (string) $order->get_meta( self::REFUNDED_AT ) ) {
			return 'skipped';
		}
		$response = $this->api->post_refund( $this->external_id( $order ) );
		if ( ! $response->ok() ) {
			$this->log( 'Refund for order ' . $order->get_id() . ' not accepted: ' . $response->summary() );

			return 'failed:' . $response->summary();
		}
		$order->update_meta_data( self::REFUNDED_AT, gmdate( DATE_ATOM ) );
		$order->save();
		$order->add_order_note( __( 'AffiWave: conversion refunded.', 'affiwave-woocommerce' ) );

		return 'refunded';
	}

	/**
	 * Catch-up: paid orders of the last $days days that were not reported yet (the hourly job and WP-CLI).
	 *
	 * @return array<int, string> order id => outcome
	 */
	public function catch_up( int $days = 60, int $limit = 100 ): array {
		$ids = wc_get_orders(
			array(
				'status'       => array_map( static fn( $s ) => 'wc-' . $s, wc_get_is_paid_statuses() ),
				'date_created' => '>' . ( time() - $days * DAY_IN_SECONDS ),
				'meta_query'   => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array( 'key' => self::REPORTED_AT, 'compare' => 'NOT EXISTS' ),
					array( 'key' => self::FAILED, 'compare' => 'NOT EXISTS' ),
				),
				'orderby'      => 'date',
				'order'        => 'ASC',
				'limit'        => $limit,
				'return'       => 'ids',
				'type'         => 'shop_order',
			)
		);

		$outcome = array();
		foreach ( $ids as $id ) {
			$outcome[ (int) $id ] = $this->report( (int) $id );
		}

		return $outcome;
	}

	public function external_id( \WC_Order $order ): string {
		return $this->settings->order_prefix() . $order->get_id();
	}

	private function enqueue( string $hook, int $order_id ): void {
		$args = array( 'order_id' => $order_id );
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			if ( ! as_has_scheduled_action( $hook, $args, self::GROUP ) ) {
				as_enqueue_async_action( $hook, $args, self::GROUP );
			}

			return;
		}
		do_action( $hook, $order_id );
	}

	private function log( string $message ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning( $message, array( 'source' => 'affiwave' ) );
		}
	}
}
