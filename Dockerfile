FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-dev --prefer-dist --no-progress --no-interaction

FROM php:8.2-apache
WORKDIR /var/www/html

# Install PDO MySQL and enable Apache rewrite
RUN docker-php-ext-install pdo pdo_mysql \
    && a2enmod rewrite

# Copy PHP source and Composer vendor
COPY --from=vendor /app/vendor /var/www/html/vendor
COPY . /var/www/html

# Simple healthcheck script (optional)
HEALTHCHECK --interval=30s --timeout=3s \
  CMD php -r 'echo "OK";' || exit 1

EXPOSE 80

