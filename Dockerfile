# syntax=docker/dockerfile:1.7

FROM composer:2 AS composer_deps
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-progress --optimize-autoloader --no-scripts

FROM node:22-alpine AS node_build
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources ./resources
COPY public ./public
COPY vite.config.js ./vite.config.js
RUN npm run build

FROM php:8.4-cli-alpine AS runtime
WORKDIR /var/www/html

RUN apk add --no-cache bash icu-dev libzip-dev oniguruma-dev sqlite sqlite-dev \
    && docker-php-ext-install pdo pdo_sqlite mbstring intl

COPY --from=composer_deps /app/vendor ./vendor
COPY --from=node_build /app/public/build ./public/build
COPY . .

RUN mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views database \
    && touch database/database.sqlite \
    && chown -R www-data:www-data storage bootstrap/cache database

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 8000
ENTRYPOINT ["/entrypoint.sh"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
