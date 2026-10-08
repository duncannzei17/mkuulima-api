# Multi-stage build for Laravel backend
FROM php:8.2-fpm-alpine AS base

# Install system dependencies
RUN apk add --no-cache \
    git \
    curl \
    libpng \
    oniguruma \
    libxml2 \
    libpq \
    postgresql-client \
    zip \
    unzip \
    supervisor \
    nginx

# Install PHP extensions
RUN apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        libpng-dev \
        oniguruma-dev \
        libxml2-dev \
        linux-headers \
        postgresql-dev \
    && docker-php-ext-install pdo_pgsql mbstring exif pcntl bcmath gd sockets \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Create application directory
WORKDIR /var/www/html

# Copy composer files
COPY composer.json composer.lock ./

# Install dependencies
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

# Copy application code
COPY . .

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/storage \
    && chmod -R 755 /var/www/html/bootstrap/cache

# Copy configurations
COPY docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini
COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf
COPY docker/entrypoint.sh /usr/local/bin/farmos-entrypoint

# Create log directories
RUN mkdir -p /var/log/supervisor \
    && mkdir -p /var/log/nginx \
    && mkdir -p /var/www/html/storage/logs

# Expose port
EXPOSE 8000

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD wget -q -O /dev/null http://localhost:8000/api/health/live || exit 1

# Start services
ENTRYPOINT ["farmos-entrypoint"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]

# Production stage
FROM base AS production

# Runtime environment values are cached by the entrypoint, not baked into the image.
RUN composer dump-autoload --no-dev --classmap-authoritative

# Remove unnecessary packages
RUN apk del git curl && rm -rf /var/cache/apk/*

# Development stage is last so legacy Docker builders can target production efficiently.
FROM base AS development

RUN composer install
RUN apk add --no-cache --virtual .xdebug-build-deps $PHPIZE_DEPS linux-headers \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && apk del .xdebug-build-deps
COPY docker/php/xdebug.ini /usr/local/etc/php/conf.d/xdebug.ini
