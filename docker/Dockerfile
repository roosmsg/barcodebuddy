# syntax=docker/dockerfile:1

# This image is built directly from the roosmsg/barcodebuddy fork. It does not
# inherit application code or runtime configuration from f0rc3/barcodebuddy.
FROM alpine:3.24.1

ARG VERSION=master

LABEL org.opencontainers.image.title="Barcode Buddy" \
      org.opencontainers.image.source="https://github.com/roosmsg/barcodebuddy" \
      org.opencontainers.image.version="${VERSION}" \
      org.opencontainers.image.description="Self-contained Barcode Buddy image built directly from the roosmsg fork"

# Official Alpine packages only. PHP 8.3 is retained for application
# compatibility while Alpine 3.24 provides a supported operating-system base.
RUN set -eux; \
    apk add --no-cache \
        bash \
        ca-certificates \
        coreutils \
        curl \
        evtest \
        nginx \
        php83 \
        php83-ctype \
        php83-curl \
        php83-fileinfo \
        php83-fpm \
        php83-gettext \
        php83-mbstring \
        php83-openssl \
        php83-pdo \
        php83-pdo_sqlite \
        php83-pecl-redis \
        php83-session \
        php83-simplexml \
        php83-sockets \
        php83-sqlite3 \
        php83-xml \
        php83-xmlwriter \
        redis \
        screen \
        shadow \
        sudo \
        supervisor \
        tzdata; \
    addgroup -S -g 1000 barcodebuddy; \
    adduser -S -D -H -u 1000 -G barcodebuddy barcodebuddy; \
    install -d -m 0755 \
        /app/bbuddy \
        /config/data \
        /etc/supervisor.d \
        /run/nginx \
        /run/php; \
    install -d -m 0755 -o barcodebuddy -g barcodebuddy /home/barcodebuddy; \
    ln -sf /usr/bin/php83 /usr/bin/php; \
    ln -s /config /data; \
    sed -i \
        -e 's/^user = .*/user = barcodebuddy/' \
        -e 's/^group = .*/group = barcodebuddy/' \
        -e 's/^[;]*clear_env = .*/clear_env = no/' \
        -e 's/^pm.max_children = .*/pm.max_children = 20/' \
        /etc/php83/php-fpm.d/www.conf; \
    grep -Fq 'user = barcodebuddy' /etc/php83/php-fpm.d/www.conf; \
    grep -Fq 'group = barcodebuddy' /etc/php83/php-fpm.d/www.conf; \
    grep -Fq 'clear_env = no' /etc/php83/php-fpm.d/www.conf; \
    grep -Fq 'pm.max_children = 20' /etc/php83/php-fpm.d/www.conf; \
    printf 'Set disable_coredump false\n' > /etc/sudo.conf

COPY docker/runtime/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/runtime/php.ini /etc/php83/conf.d/99-barcodebuddy.ini
COPY docker/runtime/supervisord.conf /etc/supervisord.conf
COPY docker/runtime/scanner.conf /usr/local/share/barcodebuddy/scanner.conf
COPY docker/runtime/cron-loop.sh /usr/local/bin/barcodebuddy-cron
COPY docker/runtime/entrypoint.sh /usr/local/bin/barcodebuddy-entrypoint

COPY --chown=barcodebuddy:barcodebuddy . /app/bbuddy/

# Apply the Docker-specific application settings, prove that every replacement
# succeeded, and lint the complete PHP application during the image build.
RUN set -eux; \
    sed -i 's/[[:blank:]]*const[[:blank:]]*IS_DOCKER[[:blank:]]*=[[:blank:]]*false;/const IS_DOCKER = true;/g' /app/bbuddy/config-dist.php; \
    sed -i 's/SCRIPT_LOCATION=.*/SCRIPT_LOCATION="\/app\/bbuddy\/index.php"/g' /app/bbuddy/example/grabInput.sh; \
    sed -i 's/WWW_USER=.*/WWW_USER="barcodebuddy"/g' /app/bbuddy/example/grabInput.sh; \
    sed -i 's/IS_DOCKER=.*/IS_DOCKER=true/g' /app/bbuddy/example/grabInput.sh; \
    sed -i 's/const DEFAULT_USE_REDIS =.*/const DEFAULT_USE_REDIS = "1";/g' /app/bbuddy/incl/db.inc.php; \
    grep -Fq 'const IS_DOCKER = true;' /app/bbuddy/config-dist.php; \
    grep -Fq 'SCRIPT_LOCATION="/app/bbuddy/index.php"' /app/bbuddy/example/grabInput.sh; \
    grep -Fq 'WWW_USER="barcodebuddy"' /app/bbuddy/example/grabInput.sh; \
    grep -Fq 'IS_DOCKER=true' /app/bbuddy/example/grabInput.sh; \
    grep -Fq 'const DEFAULT_USE_REDIS = "1";' /app/bbuddy/incl/db.inc.php; \
    rm -rf /app/bbuddy/docker /app/bbuddy/.dockerignore; \
    ln -s /config/data /app/bbuddy/data; \
    chmod 0755 \
        /usr/local/bin/barcodebuddy-cron \
        /usr/local/bin/barcodebuddy-entrypoint; \
    bash -n /usr/local/bin/barcodebuddy-entrypoint; \
    sh -n /usr/local/bin/barcodebuddy-cron; \
    nginx -t; \
    php-fpm83 -t; \
    find /app/bbuddy -type f -name '*.php' -exec php83 -l '{}' \;; \
    php83 -r 'foreach (["ctype", "curl", "mbstring", "openssl", "pdo_sqlite", "redis", "session", "sockets"] as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR, "Missing PHP extension: $extension\n"); exit(1); } }'

EXPOSE 80
VOLUME ["/config"]

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -fsS http://127.0.0.1/login.php > /dev/null || exit 1

STOPSIGNAL SIGTERM

ENTRYPOINT ["/usr/local/bin/barcodebuddy-entrypoint"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
