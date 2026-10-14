FROM php:8.4-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        librabbitmq-dev \
        libssl-dev \
        libzip-dev \
    && pecl install mongodb amqp \
    && docker-php-ext-enable mongodb amqp \
    && docker-php-ext-install zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock* ./
RUN composer install --no-interaction --no-scripts --no-progress --prefer-dist \
    $(test -f composer.lock || echo "--no-autoloader")

COPY . .
RUN composer dump-autoload --optimize

EXPOSE 8000

CMD ["php", "-S", "0.0.0.0:8000", "-t", "public", "public/index.php"]
