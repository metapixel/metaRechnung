# syntax=docker/dockerfile:1
# metaRechnung als schlankes Image: nginx + PHP-FPM in einem Container (Port 8080), Datenbank separat (MariaDB).
# Build:  docker build -t metarechnung .

# ---- Stufe 1: Bibliotheken installieren und verschlanken -------------------------------------------
FROM composer:2 AS vendor
WORKDIR /src
COPY composer.json ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --ignore-platform-reqs --no-scripts
COPY build/slim-vendor.sh build/slim-vendor.sh
RUN sh -c 'apk add --no-cache bash >/dev/null && bash build/slim-vendor.sh'

# ---- Stufe 2: Laufzeit-Image -----------------------------------------------------------------------
FROM php:8.3-fpm-alpine
ARG VERSION=dev
LABEL org.opencontainers.image.title="metaRechnung" \
      org.opencontainers.image.description="Rechnungsprogramm mit ZUGFeRD/Factur-X und XRechnung" \
      org.opencontainers.image.source="https://github.com/metapixel/metaRechnung" \
      org.opencontainers.image.licenses="GPL-2.0-or-later" \
      org.opencontainers.image.version="${VERSION}"

RUN set -eux; \
    apk add --no-cache nginx freetype libjpeg-turbo libpng oniguruma libxml2 libzip; \
    apk add --no-cache --virtual .build $PHPIZE_DEPS freetype-dev libjpeg-turbo-dev libpng-dev oniguruma-dev libxml2-dev libzip-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" gd mbstring pdo_mysql zip opcache; \
    apk del .build; \
    rm -rf /var/cache/apk/* /tmp/* /usr/local/etc/php-fpm.d/zz-docker.conf; \
    mkdir -p /tmp/nginx-body /data; \
    chown -R www-data:www-data /data /var/lib/nginx /var/log/nginx

WORKDIR /app
COPY --chown=root:root index.php status.php cron.php export.php app.js sw.js style.css offline.html manifest.webmanifest VERSION CHANGELOG.md LICENSE composer.json ./
COPY --chown=root:root assets ./assets
COPY --chown=root:root icons ./icons
COPY --chown=root:root src ./src
COPY --chown=root:root docker ./docker
COPY --chown=root:root build/smoke.php ./build/smoke.php
COPY --from=vendor --chown=root:root /src/vendor ./vendor

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-metarechnung.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-metarechnung.conf
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/security-headers.conf /etc/nginx/security-headers.conf

# Schreibbare Daten (Konfiguration, PDFs, Logo, Schriften …) liegen im Volume /data; die App erwartet storage/ im App-Ordner
RUN ln -s /data/storage /app/storage \
    && chmod +x docker/entrypoint.sh \
    && php build/smoke.php vendor \
    && rm -rf build

ENV METARECHNUNG_CONFIG=/data/config.php
VOLUME /data
EXPOSE 8080
# Nginx und PHP-FPM laufen als www-data; nginx.conf lauscht auf 8080 (kein Root nötig)
USER www-data
HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD wget -q -O /dev/null --spider http://127.0.0.1:8080/index.php || exit 1
ENTRYPOINT ["/app/docker/entrypoint.sh"]
CMD ["web"]
