FROM php:8.3-fpm

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
