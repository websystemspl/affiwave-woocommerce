# Dev environment of the plugin: PHP-FPM + WP-CLI. WordPress core and WooCommerce are installed by dev/setup.sh.
FROM php:8.5-fpm-alpine
RUN apk add --no-cache bash less mariadb-client icu-dev icu-data-full libzip-dev freetype-dev libjpeg-turbo-dev libpng-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd intl mysqli pdo_mysql zip bcmath exif
RUN curl -fsSL -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && chmod +x /usr/local/bin/wp
RUN printf 'memory_limit=512M\nsendmail_path="/usr/sbin/sendmail -S mailpit:1025 -t -i"\n' > /usr/local/etc/php/conf.d/dev.ini
WORKDIR /var/www/html
