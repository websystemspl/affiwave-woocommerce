<?php
/**
 * WP-CLI: `wp affiwave status|report|sync-coupons`.
 *
 * @package AffiWave\WooCommerce
 */

namespace AffiWave\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Cli {

	public function __construct( private Plugin $plugin ) {
	}

	/**
	 * Shows the configuration (without secrets) and the webhook URL to enter in AffiWave.
	 *
	 * @subcommand status
	 */
	public function status(): void {
		$s = $this->plugin->settings;
		\WP_CLI::line( 'Enabled:        ' . ( $s->is_enabled() ? 'yes' : 'no (API URL or key missing)' ) );
		\WP_CLI::line( 'API URL:        ' . $s->api_url() );
		\WP_CLI::line( 'API key:        ' . ( '' !== $s->get( 'api_key' ) ? 'set' : 'missing' ) );
		\WP_CLI::line( 'Webhook secret: ' . ( '' !== $s->get( 'webhook_secret' ) ? 'set' : 'missing (coupon webhooks answer 503)' ) );
		\WP_CLI::line( 'Program id:     ' . ( $s->program_id() ?? 'any (no filter)' ) );
		\WP_CLI::line( 'Order prefix:   ' . $s->order_prefix() );
		\WP_CLI::line( 'Webhook URL:    ' . WebhookController::url() );
		\WP_CLI::line( 'Coupons synced: ' . get_option( CouponSync::SYNCED_AT, 'never' ) );
	}

	/**
	 * Reports paid orders that were not reported yet (or one order).
	 *
	 * ## OPTIONS
	 *
	 * [--order=<id>]
	 * : Report this order only.
	 *
	 * [--days=<days>]
	 * : Look back this many days. Default: 60.
	 *
	 * [--limit=<limit>]
	 * : Maximum number of orders. Default: 100.
	 *
	 * @subcommand report
	 */
	public function report( array $args, array $assoc ): void {
		if ( isset( $assoc['order'] ) ) {
			\WP_CLI::line( (int) $assoc['order'] . ': ' . $this->plugin->reporter->report( (int) $assoc['order'] ) );

			return;
		}
		$outcome = $this->plugin->reporter->catch_up( (int) ( $assoc['days'] ?? 60 ), (int) ( $assoc['limit'] ?? 100 ) );
		foreach ( $outcome as $id => $result ) {
			\WP_CLI::line( $id . ': ' . $result );
		}
		\WP_CLI::success( count( $outcome ) . ' order(s) checked.' );
	}

	/**
	 * Pulls partner coupons changed in AffiWave since the last sync (or since --since).
	 *
	 * ## OPTIONS
	 *
	 * [--since=<iso8601>]
	 * : Start point, e.g. 2026-01-01T00:00:00+00:00. Default: the last sync.
	 *
	 * @subcommand sync-coupons
	 */
	public function sync_coupons( array $args, array $assoc ): void {
		$stats = $this->plugin->coupons->sync( $assoc['since'] ?? null );
		$line  = sprintf( 'fetched %d, saved %d, ignored %d, errors %d', $stats['fetched'], $stats['saved'], $stats['ignored'], $stats['errors'] );
		$stats['errors'] > 0 ? \WP_CLI::warning( $line ) : \WP_CLI::success( $line );
	}
}
