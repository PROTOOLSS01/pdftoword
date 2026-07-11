# Use official PHP 8.3 image with Apache
FROM php:8.3-apache

# Install system dependencies and tools
RUN apt-get update && apt-get install -y \
    # LibreOffice for PDF to DOCX conversion
    libreoffice \
    libreoffice-writer \
    # Poppler Utils for PDF to image conversion
    poppler-utils \
    # Tesseract OCR
    tesseract-ocr \
    tesseract-ocr-eng \
    # PHP extensions dependencies
    libzip-dev \
    libxml2-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    # Additional tools
    unzip \
    git \
    curl \
    # Clean up
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
    gd \
    zip \
    xml \
    mbstring \
    pcntl

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Install Composer
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy composer files first for better caching
COPY composer.json composer.lock ./

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# Copy application files
COPY . .

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 /var/www/html/uploads \
    && chmod -R 775 /var/www/html/output \
    && chmod -R 775 /var/www/html/temp

# Create directories with proper permissions
RUN mkdir -p /var/www/html/uploads /var/www/html/output /var/www/html/temp \
    && chown -R www-data:www-data /var/www/html/uploads \
    && chown -R www-data:www-data /var/www/html/output \
    && chown -R www-data:www-data /var/www/html/temp \
    && chmod -R 775 /var/www/html/uploads \
    && chmod -R 775 /var/www/html/output \
    && chmod -R 775 /var/www/html/temp

# Configure PHP
RUN echo "upload_max_filesize = 50M" > /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size = 50M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "max_execution_time = 300" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "memory_limit = 256M" >> /usr/local/etc/php/conf.d/uploads.ini

# Configure Apache
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf \
    && echo "<Directory /var/www/html>" >> /etc/apache2/apache2.conf \
    && echo "    Options Indexes FollowSymLinks" >> /etc/apache2/apache2.conf \
    && echo "    AllowOverride All" >> /etc/apache2/apache2.conf \
    && echo "    Require all granted" >> /etc/apache2/apache2.conf \
    && echo "</Directory>" >> /etc/apache2/apache2.conf

# Set proper timezone
RUN echo "date.timezone = UTC" > /usr/local/etc/php/conf.d/timezone.ini

# Clean up
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Expose port 80
EXPOSE 80

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD curl -f http://localhost/ || exit 1

# Start Apache
CMD ["apache2-foreground"]
