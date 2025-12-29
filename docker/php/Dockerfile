FROM composer:2 AS composer

FROM php:8.4-cli AS php_base

ARG APP_UID=1000
ARG APP_GID=1000

ENV TZ=America/Toronto

RUN apt-get update \
  && apt-get install -y --no-install-recommends \
    git \
    unzip \
    default-mysql-client \
    libzip-dev \
    libicu-dev \
    libonig-dev \
    libsqlite3-dev \
  && docker-php-ext-install -j$(nproc) \
    pdo_mysql \
    pdo_sqlite \
    mbstring \
    zip \
    intl \
  && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

# Non-root user (matches common host uid/gid)
RUN groupadd -g ${APP_GID} app \
  && useradd -m -u ${APP_UID} -g ${APP_GID} app

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html

USER app


