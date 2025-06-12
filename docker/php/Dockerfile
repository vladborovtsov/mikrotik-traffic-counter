FROM php:7.4-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libzip-dev \
    zip \
    unzip

# Install PHP extensions
RUN docker-php-ext-install \
    pdo_mysql \
    zip \
    pdo_sqlite

# Set working directory
WORKDIR /var/www/html

# Fix permission issues
RUN chown -R www-data:www-data /var/www/html
