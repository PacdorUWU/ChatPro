# Multi-stage Dockerfile for deploying this Symfony app on Railway

FROM node:18 AS node_builder
WORKDIR /app
COPY package.json package-lock.json webpack.config.js assets/ ./
RUN npm ci --silent || true
RUN npm run build || true
# Ensure build directory exists even if npm failed
RUN mkdir -p /app/public/build

FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libzip-dev zip unzip git zlib1g-dev libpng-dev libonig-dev libxml2-dev libpq-dev curl \
  && docker-php-ext-install pdo pdo_mysql pdo_pgsql zip opcache \
    && a2enmod rewrite headers deflate \
    && a2dismod mpm_event mpm_worker || true \
    && a2enmod mpm_prefork \
  && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

# Copy project files
COPY . /var/www/html

# Copy built frontend assets (if present)
COPY --from=node_builder /app/public/build /var/www/html/public/build/

# Copy composer binary
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist --no-scripts 2>&1 || { \
    echo "WARNING: Composer install had issues, continuing anyway..."; \
    }

# Create var directory if it doesn't exist
RUN mkdir -p /var/www/html/var/cache /var/www/html/var/log

# Configure Apache document root
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri "s!DocumentRoot /var/www/html!DocumentRoot ${APACHE_DOCUMENT_ROOT}!g" /etc/apache2/sites-available/*.conf && \
    sed -ri "s!<Directory /var/www/html>!<Directory ${APACHE_DOCUMENT_ROOT}>!g" /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Avoid startup warning about missing ServerName
RUN printf "ServerName localhost\n" > /etc/apache2/conf-available/servername.conf && a2enconf servername

# Set proper permissions - do this after var/ directory exists
RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 775 /var/www/html/var && \
    chmod -R 755 /var/www/html/public && \
    find /var/www/html/var -type f 2>/dev/null -exec chmod 664 {} \; || true && \
    find /var/www/html/public -type f 2>/dev/null -exec chmod 644 {} \; || true

# Create startup script with better error handling
RUN mkdir -p /usr/local/bin && cat > /usr/local/bin/startup.sh << 'SCRIPT'
#!/bin/bash
set -e

echo "===== ChatPro Starting ====="
echo "APP_ENV=${APP_ENV:-prod}"
echo "PHP_VERSION=$(php -v | head -n1)"
echo ""

# Set environment to production
export APP_ENV=${APP_ENV:-prod}

# Check if DATABASE_URL is set
if [ -z "$DATABASE_URL" ]; then
    echo "ERROR: DATABASE_URL environment variable is not set!"
    echo "Please set DATABASE_URL in your Railway Variables"
    sleep 5
    exit 1
fi

# Mask password in output
DISPLAY_URL=$(echo "$DATABASE_URL" | sed 's/:[^@]*@/:***@/')
echo "Database URL: $DISPLAY_URL"
echo ""

# Run database migrations if in production
if [ "$APP_ENV" = "prod" ]; then
    echo "=== Running database migrations ==="
    if ! php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration 2>&1; then
        echo "WARNING: Migration failed, but continuing anyway..."
    fi
    
    echo ""
    echo "=== Clearing cache ==="
    if ! php bin/console cache:clear --env=prod --no-warmup 2>&1; then
        echo "WARNING: Cache clear failed, but continuing..."
    fi
    
    echo ""
    echo "=== Warming up cache ==="
    if ! php bin/console cache:warmup --env=prod 2>&1; then
        echo "WARNING: Cache warmup failed, but continuing..."
    fi
fi

echo ""
echo "===== Application Ready ====="
echo "Starting Apache on port 80..."
echo ""

# Ensure only one MPM is enabled (some base images enable multiple)
if command -v a2dismod >/dev/null 2>&1; then
    a2dismod mpm_event mpm_worker >/dev/null 2>&1 || true
    a2enmod mpm_prefork >/dev/null 2>&1 || true
fi

# Log active MPMs for debugging
if command -v apache2ctl >/dev/null 2>&1; then
    apache2ctl -M 2>/dev/null | grep -E "mpm_(event|worker|prefork)" || true
fi

# Enable error logging
export APACHE_LOG_DIR=/var/log/apache2
mkdir -p $APACHE_LOG_DIR

exec apache2-foreground
SCRIPT

RUN chmod +x /usr/local/bin/startup.sh

# Verify PHP configuration and extensions
RUN echo "=== PHP Version ===" && php -v && \
    echo "" && \
    echo "=== Checking Extensions ===" && \
    (php -m | grep -E "pdo|mysql|pgsql|zip|xml|json" || echo "Some extensions might be missing") && \
    echo "" && \
    echo "=== Symfony Console ===" && \
    php bin/console --version || echo "WARNING: Symfony console not fully ready (will be at runtime)"

# Health check - using simple PHP endpoint without curl first
HEALTHCHECK --interval=30s --timeout=10s --start-period=15s --retries=3 \
    CMD curl -f http://localhost:80/api/health 2>/dev/null || exit 1

EXPOSE 80
CMD ["/usr/local/bin/startup.sh"]
