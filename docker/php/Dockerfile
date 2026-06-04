# syntax=docker/dockerfile:1
# PHP CLI image for running the package test-suite against a real ClickHouse server.
# Override PHP_VERSION (8.2 / 8.3 / 8.4) to exercise the supported PHP range:
#   docker compose build --build-arg PHP_VERSION=8.3
ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-cli

# Minimal toolchain: git + unzip for Composer, zip ext for fast archive extraction.
# ext-curl (required by smi2/phpclickhouse) and ext-json are bundled with the base image.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*

# Composer from the official image (v2 line).
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_HOME=/composer \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

WORKDIR /app

CMD ["bash"]
