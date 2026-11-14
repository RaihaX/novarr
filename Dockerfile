# syntax=docker/dockerfile:1
# (BuildKit syntax: the final stage bind-mounts the composer binary instead of
# copying it into a layer.)

# Stage 1: Node.js Builder - Build frontend assets
FROM node:20-alpine AS node-builder

WORKDIR /app

# Copy package files
COPY package.json package-lock.json* yarn.lock* vite.config.js ./

# Install dependencies (prefer yarn if lockfile exists, fallback to npm install if no lockfile)
RUN if [ -f yarn.lock ]; then yarn install --frozen-lockfile; \
    elif [ -f package-lock.json ]; then npm ci; \
    else npm install; fi

# Copy frontend source files
COPY resources/js/ resources/js/
COPY resources/css/ resources/css/

# Build production assets
RUN if [ -f yarn.lock ]; then yarn build; \
    else npm run build; fi


# Stage 2: Composer Dependencies
FROM composer:2 AS composer-builder

WORKDIR /app

# Copy composer files
COPY composer.json composer.lock ./

# Install dependencies without dev packages. The optimized autoloader is
# generated later in the final stage, where the full app (incl. the
# database/factories classmap dir) is present — scanning it here would fail.
RUN composer install --no-dev --no-interaction --no-scripts --no-progress --no-autoloader


# Stage 3: Final Application Image
FROM php:8.3-fpm-alpine

# Runtime packages. The shared libraries the compiled extensions link
# against must stay installed after the -dev packages are removed below:
#   libzip                          -> zip
#   libpng, libjpeg-turbo, freetype -> gd
#   icu-libs, icu-data-full         -> intl (full locale data, as icu-dev pulled in before)
#   oniguruma                       -> mbstring
#   libxml2                         -> xml
#   libcurl (via curl)              -> curl
# curl is also the container healthcheck client. mariadb-client provides
# mysqldump / mariadb-dump for `php artisan backup:run`.
RUN apk add --no-cache \
    libzip \
    libpng \
    libjpeg-turbo \
    freetype \
    icu-libs \
    icu-data-full \
    oniguruma \
    libxml2 \
    zip \
    unzip \
    gcompat \
    curl \
    mariadb-client \
    && (command -v mysqldump >/dev/null || ln -s "$(command -v mariadb-dump)" /usr/local/bin/mysqldump) \
    && mkdir -p /etc/my.cnf.d \
    && printf '%s\n' \
        '# backup:run dumps the stack-internal MySQL, whose certificate is self-signed;' \
        '# newer MariaDB clients refuse it by default.' \
        '[client]' \
        'skip-ssl-verify-server-cert' \
        > /etc/my.cnf.d/novarr-client.cnf

# Configure and install PHP extensions. Headers, compilers and git are a
# virtual .build-deps group, removed in the same layer.
RUN apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        libzip-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        libxml2-dev \
        curl-dev \
        icu-dev \
        oniguruma-dev \
        freetype-dev \
        linux-headers \
        git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        mbstring \
        xml \
        curl \
        zip \
        gd \
        intl \
        bcmath \
        opcache \
        pcntl \
        sockets \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /tmp/pear

# Install the RoadRunner binary for the architecture being built (BuildKit
# sets TARGETARCH to amd64 or arm64, so the image also builds natively on
# Apple Silicon and ARM boards).
ARG RR_VERSION=2025.1.15
ARG TARGETARCH
RUN RR_ARCH="${TARGETARCH:-amd64}" \
    && wget -q "https://github.com/roadrunner-server/roadrunner/releases/download/v${RR_VERSION}/roadrunner-${RR_VERSION}-linux-${RR_ARCH}.tar.gz" \
    && tar -xzf "roadrunner-${RR_VERSION}-linux-${RR_ARCH}.tar.gz" \
    && mv "roadrunner-${RR_VERSION}-linux-${RR_ARCH}/rr" /usr/local/bin/rr \
    && chmod +x /usr/local/bin/rr \
    && rm -rf "roadrunner-${RR_VERSION}-linux-${RR_ARCH}.tar.gz" "roadrunner-${RR_VERSION}-linux-${RR_ARCH}"

# Tailscale CLI (daemon runs in the sidecar) — used by the in-app Tailscale
# panel to read status and toggle Serve/Funnel.
COPY --from=tailscale/tailscale:stable /usr/local/bin/tailscale /usr/local/bin/tailscale

# Set working directory
WORKDIR /var/www/html

# Copy application code, owned by the runtime user as it lands (a separate
# `chown -R` layer would duplicate the whole tree).
COPY --chown=www-data:www-data . .

# Copy Composer dependencies from builder stage
COPY --chown=www-data:www-data --from=composer-builder /app/vendor ./vendor

# Copy built frontend assets from node builder
COPY --chown=www-data:www-data --from=node-builder /app/public/build ./public/build

# Generate the optimized autoloader now that the full source tree is present.
# The composer binary is bind-mounted from the composer stage for this one
# step, so it is not left in the image (nothing at runtime uses it).
RUN --mount=type=bind,from=composer-builder,source=/usr/bin/composer,target=/usr/local/bin/composer \
    composer dump-autoload --optimize --no-dev --no-interaction --no-scripts \
    && chown -R www-data:www-data vendor/composer

# Configure PHP for production
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Create opcache configuration with PHP 8.3 JIT optimizations
RUN echo "opcache.enable=1" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.memory_consumption=512" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.interned_strings_buffer=16" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.max_accelerated_files=32531" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.validate_timestamps=0" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.save_comments=1" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.jit_buffer_size=256M" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.jit=1255" >> /usr/local/etc/php/conf.d/opcache.ini

# Create custom PHP configuration
RUN echo "memory_limit=512M" >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "max_execution_time=300" >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "upload_max_filesize=100M" >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "post_max_size=100M" >> /usr/local/etc/php/conf.d/custom.ini

# Create the writable directories (they are excluded from the build context)
# and hand them to the runtime user.
RUN mkdir -p storage/app/public \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Copy entrypoint script
COPY docker/docker-entrypoint.sh /docker/docker-entrypoint.sh
RUN chmod +x /docker/docker-entrypoint.sh

# Expose RoadRunner port
EXPOSE 8000

# Liveness of the Octane server (no database/cache dependency, so a DB blip
# does not mark the container unhealthy; the compose stacks probe the fuller
# /api/health). Services that run another command from this image (the
# scheduler, a worker) override or disable it in compose.
HEALTHCHECK --interval=30s --timeout=10s --start-period=40s --retries=3 \
    CMD curl -fsS http://localhost:8000/api/ping || exit 1

# Set user
USER www-data

# Ensure an APP_KEY is present (no-op when APP_KEY is already provided), then
# run the command below. Safe for every service in the stack.
ENTRYPOINT ["/docker/docker-entrypoint.sh"]

# Start Laravel Octane with RoadRunner
CMD ["php", "artisan", "octane:start", "--server=roadrunner", "--host=0.0.0.0", "--port=8000"]
