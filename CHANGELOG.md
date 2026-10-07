# Changelog

## 0.1.0 — 2026-10-07

First version (first deployment: Botino).

- `?aw_click=` partner click → first-party cookie (no page cache), stored on the order and once on the customer.
- Every paid order — including subscription renewals — reported to `POST /api/conversion`: net amount (total − tax),
  order currency, `external_order_id` = prefix + order id; in the background (Action Scheduler), idempotent,
  hourly catch-up and `wp affiwave report`. Full refunds → `POST /api/conversion/refund`.
- Partner coupons: `coupon.created` / `coupon.updated` webhooks (HMAC, 300 s window, program filter) and
  `GET /api/coupon_codes?changedSince=` sync → WooCommerce coupons; orders with them report `coupon`.
- Settings page (WooCommerce → AffiWave), wp-config.php constants, connection test, WP-CLI, HPOS and block checkout, PL translation.
