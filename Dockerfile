# Multi-stage Dockerfile for deploying this Symfony app on Railway

FROM node:18 AS node_builder
WORKDIR /app
COPY package.json package-lock.json yarn.lock webpack.config.js assets/ ./
RUN npm ci --silent || true
RUN npm run build || true

FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libzip-dev zip unzip git zlib1g-dev libpng-dev libonig-dev libxml2-dev libpq-dev \
  && docker-php-ext-install pdo pdo_mysql zip opcache \
  && a2enmod rewrite

WORKDIR /var/www/html

# Copy project files
COPY . /var/www/html

# Copy built frontend assets (if present)
COPY --from=node_builder /app/public/build /var/www/html/public/build

# Copy composer binary
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist || true

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!DocumentRoot /var/www/html!DocumentRoot ${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri 's!<Directory /var/www/html>!<Directory ${APACHE_DOCUMENT_ROOT}>!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

RUN chown -R www-data:www-data var public || true

EXPOSE 80
CMD ["apache2-foreground"]
