#!/bin/bash
# Installs WordPress + WooCommerce + this plugin into the dev stack. Idempotent.
#   cd dev && docker compose up -d --build && ./setup.sh
set -euo pipefail
cd "$(dirname "$0")"
URL="${URL:-http://affiwave-wc.localhost}"
# error_reporting without E_DEPRECATED: wp-cli itself is noisy on PHP 8.5
wp() { docker compose exec -T -u www-data php php -d error_reporting=24575 /usr/local/bin/wp "$@"; }

docker compose exec -T php sh -c "mkdir -p /var/www/html/wp-content/plugins /var/www/html/wp-content/themes /var/www/html/wp-content/upgrade /var/www/html/wp-content/uploads && chown www-data:www-data /var/www/html /var/www/html/wp-content /var/www/html/wp-content/plugins /var/www/html/wp-content/themes /var/www/html/wp-content/upgrade /var/www/html/wp-content/uploads"
if ! wp core is-installed 2>/dev/null; then
    wp core download --skip-content --force
    wp config create --dbname=wordpress --dbuser=wordpress --dbpass=wordpress --dbhost=db --skip-check --force \
        --extra-php <<'PHP'
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
PHP
    wp core install --url="$URL" --title="AffiWave WC dev" --admin_user=admin --admin_password=admin \
        --admin_email=admin@example.com --skip-email
fi
wp theme is-installed twentytwentyfive || wp theme install twentytwentyfive --activate
wp plugin is-installed woocommerce || wp plugin install woocommerce
wp plugin activate woocommerce
wp plugin activate affiwave-woocommerce
wp rewrite structure '/%postname%/' --hard
wp option update woocommerce_currency EUR
wp option update woocommerce_default_country PL
wp option update woocommerce_calc_taxes yes
wp option update woocommerce_prices_include_tax yes
wp option update woocommerce_coming_soon no
# 23 % standard rate, cash on delivery for test orders
if [ "$(wp wc tax list --user=admin --format=count)" = "0" ]; then
    wp wc tax create --rate=23 --name=VAT --country=PL --user=admin
fi
wp wc payment_gateway update cod --enabled=true --user=admin
if [ -z "$(wp post list --post_type=product --name=plan-pro --format=ids)" ]; then
    wp wc product create --name="Plan Pro" --slug=plan-pro --regular_price=43.05 --virtual=true --user=admin
fi
echo "Shop: $URL  (admin / admin)"
