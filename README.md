# AffiWave for WooCommerce

Official WordPress plugin connecting a WooCommerce store to an [AffiWave](https://affiwave.com) affiliate program.

| What | How |
| --- | --- |
| Partner click | AffiWave redirects partner links with `?aw_click=<token>` (program attribution parameter **`aw_click`**). The plugin keeps it in a first-party cookie (default 30 days), marks the page as not cacheable (WP Rocket, LiteSpeed, W3TC, Super Cache) and stores it on the order and — first referral wins — on the customer account. |
| Conversion | **Every paid order** (status processing/completed, including subscription renewals of WooCommerce Subscriptions, SUMO and similar) → `POST /api/conversion` with `click_token` or `coupon`, **net** amount (order total − order tax), order currency, `external_order_id` = prefix + order id, `occurred_at`, `customer_email`. Sent in the background (Action Scheduler); a stamp on the order prevents repeats; AffiWave de-duplicates too. Only payments after the customer's referral are reported. |
| Retries | Network/5xx/429 → retried by the hourly job and `wp affiwave report`. 4xx (e.g. unknown token) → final, order note. |
| Refund | A fully refunded, reported order → `POST /api/conversion/refund`. |
| Partner coupons | Webhooks `coupon.created` / `coupon.updated` → `POST /wp-json/affiwave/v1/webhook` (signature `X-AffiWave-Signature`, 300 s window) create/update a WooCommerce coupon (percentage → percent, fixed → fixed cart, active → published, disabled/expired → draft). Hourly safety-net sync: `GET /api/coupon_codes?changedSince=`. With a **Program ID** set, coupons of other programs of the same AffiWave account are ignored. Codes made outside AffiWave are never overwritten (409). |

## Installation

Download **[affiwave-woocommerce.zip](https://github.com/websystemspl/affiwave-woocommerce/releases/latest/download/affiwave-woocommerce.zip)**
(latest release) and upload it in **Plugins → Add New → Upload Plugin**. WooCommerce is required.

## Updates

The plugin updates itself from [GitHub Releases](https://github.com/websystemspl/affiwave-woocommerce/releases):
a new release appears in **Dashboard → Updates** and on the plugin list (with *View details* and the release notes),
works with one-click and automatic updates and with `wp plugin update`. The latest release is checked at most every
6 hours (anonymous GitHub API); *Check again* in Dashboard → Updates checks right away. Versions before 0.3.0 have no
updater — install 0.3.0 once by hand.

## Setup

1. AffiWave: **Integrations → WordPress → Configure** — choose the program, enter the shop address and generate an
   **integration key**. AffiWave creates an API key (`conversions:write`, `reports:read`), a coupon webhook
   (`coupon.created`, `coupon.updated`) to the shop and sets the program attribution parameter to `aw_click`.
2. WordPress: **WooCommerce → AffiWave** — paste the integration key, save, *Test connection*.
   Empty address or key = nothing is sent.

Setting it up by hand instead: in AffiWave set the program attribution parameter `aw_click`, create the API key and the
webhook to the URL shown in the plugin settings, then fill in address, API key, webhook secret and program ID.

Every setting can be fixed in `wp-config.php` (the field is then locked in wp-admin):

```php
define( 'AFFIWAVE_API_URL', 'https://affiwave.com' );
define( 'AFFIWAVE_API_KEY', 'ak_xxxx.yyyy' );
define( 'AFFIWAVE_WEBHOOK_SECRET', 'whsec_...' );
define( 'AFFIWAVE_PROGRAM_ID', 1 );
define( 'AFFIWAVE_ORDER_PREFIX', 'shop-' );   // default: shop host + "-"
define( 'AFFIWAVE_COOKIE_DAYS', 30 );
```

## WP-CLI

```bash
wp affiwave status                    # configuration (no secrets) + webhook URL
wp affiwave report [--order=<id>] [--days=60] [--limit=100]
wp affiwave sync-coupons [--since=2026-01-01T00:00:00+00:00]
```

## Hooks

- `affiwave_wc_conversion_payload` (array $payload, WC_Order $order) — change what is sent.
- `affiwave_wc_order_referral` (array|null $referral, WC_Order $order) — referral of orders without a customer account
  (e.g. guest renewals: return the parent order's referral).

## Notes

- Fixed-amount coupons come from AffiWave as **gross** amounts in the program currency. With several shop currencies
  (WPML/WCML) check how your multi-currency plugin converts fixed coupons.
- Customers who sign up outside WordPress (an external app writing straight to `wp_users`) get their referral at their
  first checkout, while the click cookie is still valid.
- Action Scheduler runs through WP-Cron; on low-traffic sites trigger `wp-cron.php` from a system cron.

## Development

```bash
composer install && vendor/bin/phpunit          # unit tests (signature, money, coupon mapping, integration key, release)
bin/build-zip.sh                                # build/affiwave-woocommerce.zip for a release
```

Release: bump the version (plugin header, `AFFIWAVE_WC_VERSION`, `readme.txt` Stable tag, `CHANGELOG.md`), then

```bash
git tag vX.Y.Z && git push origin main vX.Y.Z
gh release create vX.Y.Z build/affiwave-woocommerce.zip --title "AffiWave for WooCommerce X.Y.Z" --notes "..."
```

The zip asset must keep its name (`affiwave-woocommerce.zip`) — the updater and the AffiWave download link use it.
Before publishing in the wordpress.org directory remove `src/Updater.php` and the `Update URI` header.

Dev shop:

```bash
cd dev && docker compose up -d --build && ./setup.sh   # shop at http://affiwave-wc.localhost (admin/admin)
```

`dev/` expects the Web Systems dev machine networks (`narzedzia-proxy`, `traefik-public`, `mailpit`); a local AffiWave
is reachable from the shop as `http://affiwave-internal`.

License: GPL-2.0-or-later.
