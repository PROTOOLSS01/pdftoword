FROM php:8.3-apache

RUN apt-get update && apt-get install -y \
    libreoffice \
    poppler-utils \
    tesseract-ocr \
    ghostscript \
    unzip \
    zip \
    git \
    curl \
    && docker-php-ext-install zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN composer install --no-dev --optimize-autoloader

RUN mkdir -p uploads output temp \
    && chmod -R 777 uploads output temp

EXPOSE 80
