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

# Install dependencies without dev packages
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts --no-progress


# Stage 3: Final Application Image
FROM php:8.3-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    libzip-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    libxml2-dev \
    curl-dev \
    icu-dev \
    oniguruma-dev \
    freetype-dev \
    zip \
    unzip \
    git \
    supervisor \
    dcron \
    gcompat \
    curl

# Configure and install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
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
    sockets

# Install Redis extension
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

# Install RoadRunner binary
ARG RR_VERSION=2024.3.5
RUN wget -q https://github.com/roadrunner-server/roadrunner/releases/download/v${RR_VERSION}/roadrunner-${RR_VERSION}-linux-amd64.tar.gz \
    && tar -xzf roadrunner-${RR_VERSION}-linux-amd64.tar.gz \
    && mv roadrunner-${RR_VERSION}-linux-amd64/rr /usr/local/bin/rr \
    && chmod +x /usr/local/bin/rr \
    && rm -rf roadrunner-${RR_VERSION}-linux-amd64.tar.gz roadrunner-${RR_VERSION}-linux-amd64

# Set working directory
WORKDIR /var/www/html

# Copy application code
COPY . .

# Copy Composer dependencies from builder stage
COPY --from=composer-builder /app/vendor ./vendor

# Copy built frontend assets from node builder
COPY --from=node-builder /app/public/build ./public/build

# Publish Voyager assets
RUN php artisan vendor:publish --provider="TCG\Voyager\VoyagerServiceProvider" --force || true
RUN php artisan vendor:publish --provider="Intervention\Image\ImageServiceProviderLaravelRecent" --force || true

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

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 775 storage bootstrap/cache

# Create storage directories if they don't exist
RUN mkdir -p storage/app/public \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

# Copy supervisor configuration
COPY docker/supervisor/supervisord.conf /etc/supervisor/supervisord.conf
COPY docker/supervisor/conf.d/ /etc/supervisor/conf.d/

# Create supervisor log directory and set permissions
RUN mkdir -p /var/log/supervisor \
    && chown -R www-data:www-data /var/log/supervisor

# Copy entrypoint scripts
COPY docker/entrypoint-supervisor.sh /docker/entrypoint-supervisor.sh
RUN chmod +x /docker/entrypoint-supervisor.sh

# Default number of queue workers (can be overridden via environment)
ENV SUPERVISOR_WORKER_PROCESSES=2

# Expose RoadRunner port
EXPOSE 8000

# Set user
USER www-data

# Start Laravel Octane with RoadRunner
CMD ["php", "artisan", "octane:start", "--server=roadrunner", "--host=0.0.0.0", "--port=8000"]
