# AffiWave for WooCommerce

Official WordPress plugin connecting a WooCommerce store to an [AffiWave](https://affiwave.com) affiliate program.

| What | How |
| --- | --- |
| Partner click | AffiWave redirects partner links with `?aw_click=<token>` (program attribution parameter **`aw_click`**). The plugin keeps it in a first-party cookie (default 30 days), marks the page as not cacheable (WP Rocket, LiteSpeed, W3TC, Super Cache) and stores it on the order and — first referral wins — on the customer account. |
| Conversion | **Every paid order** (status processing/completed, including subscription renewals of WooCommerce Subscriptions, SUMO and similar) → `POST /api/conversion` with `click_token` or `coupon`, **net** amount (order total − order tax), order currency, `external_order_id` = prefix + order id, `occurred_at`, `customer_email`. Sent in the background (Action Scheduler); a stamp on the order prevents repeats; AffiWave de-duplicates too. Only payments after the customer's referral are reported. |
| Retries | Network/5xx/429 → retried by the hourly job and `wp affiwave report`. 4xx (e.g. unknown token) → final, order note. |
| Refund | A fully refunded, reported order → `POST /api/conversion/refund`. |
| Partner coupons | Webhooks `coupon.created` / `coupon.updated` → `POST /wp-json/affiwave/v1/webhook` (signature `X-AffiWave-Signature`, 300 s window) create/update a WooCommerce coupon (percentage → percent, fixed → fixed cart, active → published, disabled/expired → draft). Hourly safety-net sync: `GET /api/coupon_codes?changedSince=`. With a **Program ID** set, coupons of other programs of the same AffiWave account are ignored. Codes made outside AffiWave are never overwritten (409). |

## Setup

1. AffiWave: program → attribution parameter `aw_click`; API key with scopes `conversions:write` and `reports:read`;
   webhook with events `coupon.created`, `coupon.updated` pointing to the URL shown in the plugin settings.
2. WordPress: **WooCommerce → AffiWave** — address, API key, webhook secret, program ID, order prefix, cookie days;
   *Test connection*. Empty address or key = nothing is sent.

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
composer install && vendor/bin/phpunit          # unit tests (signature, money, coupon mapping)
cd dev && docker compose up -d --build && ./setup.sh   # shop at http://affiwave-wc.localhost (admin/admin)
```

`dev/` expects the Web Systems dev machine networks (`narzedzia-proxy`, `traefik-public`, `mailpit`); a local AffiWave
is reachable from the shop as `http://affiwave-internal`.

License: GPL-2.0-or-later.
