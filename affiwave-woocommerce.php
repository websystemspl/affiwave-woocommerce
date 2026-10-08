<?php
/**
 * Plugin Name:       AffiWave for WooCommerce
 * Plugin URI:        https://affiwave.com/integrations/woocommerce
 * Description:       Connects your WooCommerce store to an AffiWave affiliate program: remembers partner clicks, reports every paid order (including subscription renewals) as a server-to-server conversion and keeps partner coupon codes in sync.
 * Version:           0.2.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Author:            AffiWave
 * Author URI:        https://affiwave.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       affiwave-woocommerce
 * Domain Path:       /languages
 * WC requires at least: 8.2
 * WC tested up to:   11.1
 *
 * @package AffiWave\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'AFFIWAVE_WC_VERSION', '0.2.0' );
define( 'AFFIWAVE_WC_FILE', __FILE__ );
define( 'AFFIWAVE_WC_DIR', __DIR__ );

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'AffiWave\\WooCommerce\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$file = AFFIWAVE_WC_DIR . '/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

// HPOS (custom order tables) and the block checkout are supported.
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		\AffiWave\WooCommerce\Plugin::instance()->boot();
	}
);

register_activation_hook( __FILE__, array( \AffiWave\WooCommerce\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \AffiWave\WooCommerce\Plugin::class, 'deactivate' ) );
