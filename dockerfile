# Dockerfile
FROM php:8.2-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libreoffice \
    libreoffice-writer \
    poppler-utils \
    ghostscript \
    tesseract-ocr \
    tesseract-ocr-eng \
    tesseract-ocr-fra \
    tesseract-ocr-deu \
    tesseract-ocr-spa \
    tesseract-ocr-ita \
    tesseract-ocr-por \
    tesseract-ocr-rus \
    tesseract-ocr-jpn \
    tesseract-ocr-chi-sim \
    tesseract-ocr-chi-tra \
    tesseract-ocr-ara \
    tesseract-ocr-hin \
    tesseract-ocr-kor \
    tesseract-ocr-tha \
    tesseract-ocr-vie \
    tesseract-ocr-nld \
    tesseract-ocr-swe \
    tesseract-ocr-pol \
    tesseract-ocr-tur \
    tesseract-ocr-heb \
    tesseract-ocr-ces \
    tesseract-ocr-slv \
    tesseract-ocr-hrv \
    tesseract-ocr-ron \
    tesseract-ocr-bul \
    tesseract-ocr-ell \
    tesseract-ocr-hun \
    tesseract-ocr-fin \
    tesseract-ocr-nor \
    tesseract-ocr-dan \
    tesseract-ocr-lit \
    tesseract-ocr-lav \
    tesseract-ocr-est \
    tesseract-ocr-mkd \
    tesseract-ocr-srp \
    tesseract-ocr-slk \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    zip \
    unzip \
    curl \
    git \
    nano \
    supervisor \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd \
    && docker-php-ext-install mysqli pdo pdo_mysql \
    && docker-php-ext-install zip \
    && docker-php-ext-install exif \
    && docker-php-ext-install bcmath \
    && docker-php-ext-install opcache

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Enable Apache modules
RUN a2enmod rewrite \
    && a2enmod headers \
    && a2enmod expires \
    && a2enmod deflate

# Configure PHP
COPY php.ini /usr/local/etc/php/conf.d/custom.ini

# Configure Apache
COPY apache.conf /etc/apache2/sites-available/000-default.conf

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . /var/www/html/

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 /var/www/html/uploads \
    && chmod -R 775 /var/www/html/temp \
    && chmod -R 775 /var/www/html/logs \
    && chmod -R 775 /var/www/html/public

# Create directories
RUN mkdir -p /var/www/html/uploads \
    && mkdir -p /var/www/html/temp \
    && mkdir -p /var/www/html/logs \
    && mkdir -p /var/www/html/public \
    && chown -R www-data:www-data /var/www/html/uploads \
    && chown -R www-data:www-data /var/www/html/temp \
    && chown -R www-data:www-data /var/www/html/logs

# Install PHP dependencies
RUN composer install --no-interaction --optimize-autoloader --no-dev

# Set environment variables
ENV APP_ENV=production \
    APP_DEBUG=false \
    UPLOAD_MAX_SIZE=100M \
    MAX_EXECUTION_TIME=300

# Expose port
EXPOSE 80

# Start Apache
CMD ["apache2-foreground"]
