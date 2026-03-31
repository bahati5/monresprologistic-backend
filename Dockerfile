# PHP + extensions pour Laravel 11 (développement & artisan serve)
# Aligné sur composer.lock (ex. Symfony 8.x peut exiger PHP >= 8.4)
FROM php:8.4-cli-bookworm

# Évite qu’une image cachée ou une mauvaise base ne passe silencieusement
RUN php -r "if (version_compare(PHP_VERSION, '8.4.0', '<')) { fwrite(STDERR, 'Dockerfile requires PHP >= 8.4, got '.PHP_VERSION.PHP_EOL); exit(1); }"

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    curl \
    zip \
    unzip \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libzip-dev \
    libicu-dev \
    libonig-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        zip \
        intl \
        gd \
        opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# OPcache CLI + realpath : indispensable pour des temps de réponse corrects avec
# `php artisan serve` et un projet monté depuis Windows (sinon chaque hit relit tout vendor/).
COPY docker/php/conf.d/zz-laravel-docker.ini /usr/local/etc/php/conf.d/zz-laravel-docker.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /var/www/html

COPY docker/entrypoint-app.sh /usr/local/bin/entrypoint-app.sh
RUN chmod +x /usr/local/bin/entrypoint-app.sh

ENTRYPOINT ["/usr/local/bin/entrypoint-app.sh"]
# --no-reload : sans ça, Laravel ignore PHP_CLI_SERVER_WORKERS (>1) et le serveur reste
# quasi mono-requête → files d’attente, ERR_EMPTY_RESPONSE et lenteurs sous Docker/Windows.
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000", "--no-reload"]
