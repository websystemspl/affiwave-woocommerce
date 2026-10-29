# Changelog

## 0.3.2 — 2026-10-29

- **Stored integration key is visible masked**: the settings screen shows `awi1_•••••••• (ak_1a2b3c4d.••••••••)` and
  "Stored: API key …, connected …" instead of an empty field — the API key prefix is the one AffiWave lists next to the
  shop. API key and webhook secret fields show their masked value too; secrets are never printed.
- **Last coupon sync** shows when the sync last ran (hourly job, button or WP-CLI) with fetched / saved / errors and the
  error message. It used to print the API cursor, which stays at `1970-01-01` until the first coupon arrives — it looked
  as if the sync had never run. New button **Sync coupons now**; `wp affiwave status` shows the same.
- AffiWave card name: Integrations → **WooCommerce (WordPress)** → Configure.

## 0.3.1 — 2026-10-29

- **Stable `external_order_id`**: the id sent with the first report is stored on the order (`_affiwave_external_order_id`)
  and reused by retries and the refund. The default prefix is the host of `home_url()`, which on a shop with a domain per
  language (WPML: `www.botino.eu` / `www.botino.pl`) depends on the request that runs the background job — a refund
  could miss its conversion and a retry could report it twice. Orders reported by 0.3.0 fall back to the current prefix.
- AffiWave generates **one integration key per program**, valid for every approved domain of the program.

## 0.3.0 — 2026-10-08

- **Updates from GitHub Releases**: the plugin shows up in Dashboard → Updates and the plugin list like any other,
  with "View details" (release notes) and one-click / automatic updates (`Update URI` header + `update_plugins_github.com`).
  Latest release cached for 6 h; "Check again" skips the cache. Installed under another directory name, it is updated in place.

## 0.2.0 — 2026-10-08

- **Integration key**: paste one key from AffiWave → Integrations → WordPress → Configure instead of four values;
  AffiWave creates the API key and the coupon webhook for the shop.
- `bin/build-zip.sh` builds the installable `affiwave-woocommerce.zip` attached to every GitHub release.

## 0.1.0 — 2026-10-07

First version (first deployment: Botino).

- `?aw_click=` partner click → first-party cookie (no page cache), stored on the order and once on the customer.
- Every paid order — including subscription renewals — reported to `POST /api/conversion`: net amount (total − tax),
  order currency, `external_order_id` = prefix + order id; in the background (Action Scheduler), idempotent,
  hourly catch-up and `wp affiwave report`. Full refunds → `POST /api/conversion/refund`.
- Partner coupons: `coupon.created` / `coupon.updated` webhooks (HMAC, 300 s window, program filter) and
  `GET /api/coupon_codes?changedSince=` sync → WooCommerce coupons; orders with them report `coupon`.
- Settings page (WooCommerce → AffiWave), wp-config.php constants, connection test, WP-CLI, HPOS and block checkout, PL translation.
