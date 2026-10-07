<?php
/**
 * Wiring of the plugin services.
 *
 * @package AffiWave\WooCommerce
 */

namespace AffiWave\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/** Hourly job: catch-up of unreported conversions + coupon sync. */
	public const HOURLY = 'affiwave_wc_hourly';

	private static ?Plugin $instance = null;

	public readonly Settings $settings;
	public readonly ApiClient $api;
	public readonly Attribution $attribution;
	public readonly ConversionReporter $reporter;
	public readonly CouponSync $coupons;

	private function __construct() {
		$this->settings    = new Settings();
		$this->api         = new ApiClient( $this->settings );
		$this->attribution = new Attribution( $this->settings );
		$this->reporter    = new ConversionReporter( $this->settings, $this->api, $this->attribution );
		$this->coupons     = new CouponSync( $this->settings, $this->api );
	}

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function boot(): void {
		load_plugin_textdomain( 'affiwave-woocommerce', false, dirname( plugin_basename( AFFIWAVE_WC_FILE ) ) . '/languages' );

		$this->attribution->register();
		$this->reporter->register();
		$this->coupons->register();
		( new WebhookController( $this->settings, $this->coupons ) )->register();

		add_action( self::HOURLY, array( $this, 'hourly' ) );
		add_action( 'init', array( $this, 'schedule' ) );

		if ( is_admin() ) {
			( new Admin\SettingsPage( $this->settings, $this->coupons ) )->register();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'affiwave', new Cli( $this ) );
		}
	}

	public function schedule(): void {
		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::HOURLY, array(), ConversionReporter::GROUP ) ) {
			as_schedule_recurring_action( time() + 300, HOUR_IN_SECONDS, self::HOURLY, array(), ConversionReporter::GROUP );
		}
	}

	public function hourly(): void {
		if ( ! $this->settings->is_enabled() ) {
			return;
		}
		$this->reporter->catch_up();
		$this->coupons->sync();
	}

	public static function activate(): void {
		// The hourly job is scheduled on the next `init` (Action Scheduler is not loaded yet during activation).
	}

	public static function deactivate(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOURLY, array(), ConversionReporter::GROUP );
		}
	}
}
