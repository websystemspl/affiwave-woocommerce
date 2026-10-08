=== AffiWave for WooCommerce ===
Contributors: affiwave
Tags: affiliate, woocommerce, referral, coupons, subscriptions
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WooCommerce to an AffiWave affiliate program: partner click attribution, server-to-server conversions for every paid order and renewal, partner coupons.

== Description ==

* Remembers partner clicks (`?aw_click=`) in a first-party cookie and on the customer account.
* Reports every paid order — including subscription renewals — to AffiWave as a conversion (net amount, order currency), in the background, without duplicates; refunds included.
* Creates and updates WooCommerce coupons from AffiWave partner coupons (signed webhooks + hourly sync).
* Settings page, wp-config.php constants, WP-CLI commands, HPOS and block checkout support.

This plugin sends order data (order id, net amount, currency, payment date, customer e-mail, partner click token or coupon code) to the AffiWave server configured in its settings (default https://affiwave.com). Nothing is sent until an API key is set. AffiWave terms: https://affiwave.com/terms — privacy policy: https://affiwave.com/privacy

== Installation ==

1. Upload and activate the plugin (WooCommerce required).
2. In AffiWave open Integrations → WordPress → Configure, choose the program, enter the shop address and generate an integration key.
3. Paste the integration key in WooCommerce → AffiWave, save and click Test connection.

== Changelog ==

= 0.2.0 =
* Integration key generated in AffiWave fills in the whole configuration.

= 0.1.0 =
* First version.
