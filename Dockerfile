# PHP + Apache
FROM php:8.2-apache

# Install required packages
RUN apt-get update && apt-get install -y \
    libreoffice \
    poppler-utils \
    tesseract-ocr \
    ghostscript \
    imagemagick \
    unzip \
    zip \
    wget \
    curl \
    fonts-dejavu \
    libzip-dev \
    libxml2-dev \
    libonig-dev \
    libpng-dev \
    && docker-php-ext-install \
        zip \
        mbstring \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache rewrite
RUN a2enmod rewrite

# Set working directory
WORKDIR /var/www/html

# Copy project files
COPY . /var/www/html/

# Set permissions
RUN mkdir -p /var/www/html/uploads \
    /var/www/html/output \
    /var/www/html/temp \
    && chmod -R 777 /var/www/html/uploads \
    /var/www/html/output \
    /var/www/html/temp

# Expose Apache port
EXPOSE 80

# Start Apache
CMD ["apache2-foreground"]
