FROM php:8.3-apache

# Install system packages
RUN apt-get update && apt-get install -y \
    git \
    curl \
    unzip \
    pkg-config \
    libreoffice \
    libreoffice-writer \
    poppler-utils \
    tesseract-ocr \
    tesseract-ocr-eng \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libzip-dev \
    libxml2-dev \
    libonig-dev \
    && rm -rf /var/lib/apt/lists/*

# Configure GD
RUN docker-php-ext-configure gd --with-freetype --with-jpeg

# Install PHP Extensions
RUN docker-php-ext-install -j$(nproc) gd
RUN docker-php-ext-install -j$(nproc) zip
RUN docker-php-ext-install -j$(nproc) mbstring
RUN docker-php-ext-install -j$(nproc) xml
RUN docker-php-ext-install -j$(nproc) pcntl

# Enable Apache Rewrite
RUN a2enmod rewrite

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy Composer files
COPY composer.json composer.lock ./

# Install PHP packages
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Copy project
COPY . .

# Create directories
RUN mkdir -p \
    uploads \
    output \
    temp

# Permissions
RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 775 uploads output temp

# PHP Settings
RUN echo "upload_max_filesize=100M" > /usr/local/etc/php/conf.d/uploads.ini && \
    echo "post_max_size=100M" >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "memory_limit=512M" >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "max_execution_time=600" >> /usr/local/etc/php/conf.d/uploads.ini

EXPOSE 80

CMD ["apache2-foreground"]
