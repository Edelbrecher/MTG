# Use official PHP 8.2 with Apache
FROM php:8.2-apache

# Set working directory
WORKDIR /var/www/html

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    nano \
    vim \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# Enable Apache mod_rewrite and create socket directory
RUN a2enmod rewrite \
    && mkdir -p /var/run/apache \
    && chown www-data:www-data /var/run/apache

# Copy existing application directory contents
COPY . /var/www/html

# Create necessary directories and set permissions
RUN mkdir -p /var/www/html/logs \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Configure Apache
COPY docker/apache-config.conf /etc/apache2/sites-available/000-default.conf

# Remove default port 80 listen directive and add socket configuration
RUN echo "# Socket configuration for Nginx reverse proxy" > /etc/apache2/ports.conf \
    && echo "# Apache will listen on Unix socket instead of TCP port" >> /etc/apache2/ports.conf

# Expose socket volume (no port needed)
VOLUME ["/var/run/apache"]

# Start Apache in foreground
CMD ["apache2-foreground"]
