FROM php:8.2-apache

# Install system dependencies: LibreOffice, Poppler-utils, and common PHP extensions
RUN apt-get update && apt-get install -y \
    libreoffice \
    poppler-utils \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libxml2-dev \
    curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd zip mbstring xml \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Set working directory
WORKDIR /var/www/html

# Copy project files
COPY . /var/www/html/

# Install Composer dependencies
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Set proper permissions for upload/output/temp directories
RUN mkdir -p /var/www/html/uploads /var/www/html/output /var/www/html/temp \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/uploads /var/www/html/output /var/www/html/temp

# Use Render's PORT environment variable (default to 80)
ENV PORT=80
EXPOSE $PORT

# Override Apache port to match $PORT
RUN sed -i "s/Listen 80/Listen ${PORT}/g" /etc/apache2/ports.conf \
    && sed -i "s/:80/:${PORT}/g" /etc/apache2/sites-available/000-default.conf

# Start Apache in foreground
CMD ["apache2-foreground"]
