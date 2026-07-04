FROM php:8.3-cli

WORKDIR /app

RUN apt-get update && apt-get install -y \
    libreoffice \
    poppler-utils \
    libzip-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libxml2-dev \
    libonig-dev \
    zlib1g-dev \
    curl \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install gd zip mbstring xml \
 && apt-get clean \
 && rm -rf /var/lib/apt/lists/*

COPY . .

EXPOSE 10000

CMD ["php", "-S", "0.0.0.0:10000", "index.php"]
