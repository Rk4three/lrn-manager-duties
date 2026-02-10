# Use an official PHP runtime as a parent image
FROM php:8.2-apache

# Install system dependencies and PostgreSQL driver
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libpng-dev \
    libzip-dev \
    zip \
    unzip \
    && docker-php-ext-install pdo pdo_pgsql pgsql gd zip opcache

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Create Apache configuration for /var/www/html with proper permissions
# We write to a new file 'app-permissions.conf' to avoid overwriting 'docker-php.conf' which enables PHP!
RUN echo '<Directory /var/www/html/>\n\
    Options Indexes FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
    </Directory>' > /etc/apache2/conf-available/app-permissions.conf \
    && a2enconf app-permissions

# Copy application source
COPY . /var/www/html/

# Set working directory
WORKDIR /var/www/html

# Use the default production configuration
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Adjust permissions
RUN mkdir -p /var/www/html/uploads && \
    chown -R www-data:www-data /var/www/html && \
    chmod -R 775 /var/www/html/uploads

