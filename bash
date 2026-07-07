#!/bin/bash
# build.sh

#!/bin/bash
set -e

echo "Starting build process..."

# Update package lists
apt-get update

# Install system dependencies
apt-get install -y \
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
    tesseract-ocr-slk

# Clean up
apt-get clean
rm -rf /var/lib/apt/lists/*

# Install PHP extensions
docker-php-ext-configure gd --with-freetype --with-jpeg
docker-php-ext-install gd mysqli pdo pdo_mysql zip exif bcmath opcache

# Enable Apache modules
a2enmod rewrite headers expires deflate

# Set permissions
chown -R www-data:www-data /var/www/html
chmod -R 755 /var/www/html
chmod -R 775 /var/www/html/uploads
chmod -R 775 /var/www/html/temp
chmod -R 775 /var/www/html/logs

# Install Composer dependencies
composer install --no-interaction --optimize-autoloader --no-dev

echo "Build completed successfully!"
