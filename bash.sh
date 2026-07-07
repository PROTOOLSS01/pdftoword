#!/usr/bin/env bash
set -e

echo "======================================"
echo "Starting Render Build"
echo "======================================"

export DEBIAN_FRONTEND=noninteractive

echo "Updating packages..."
apt-get update

echo "Installing required packages..."

apt-get install -y \
    libreoffice \
    poppler-utils \
    tesseract-ocr \
    ghostscript \
    imagemagick \
    unzip \
    zip \
    curl \
    wget \
    fonts-dejavu \
    libzip-dev \
    libxml2-dev \
    libonig-dev \
    libpng-dev

echo "Creating project folders..."

mkdir -p uploads
mkdir -p output
mkdir -p temp

chmod -R 777 uploads
chmod -R 777 output
chmod -R 777 temp

echo "Installing Composer packages..."

composer install \
    --no-dev \
    --prefer-dist \
    --optimize-autoloader \
    --no-interaction

echo "Checking installations..."

echo "PHP Version:"
php -v

echo "LibreOffice:"
libreoffice --version || soffice --version

echo "Poppler:"
pdftotext -v | head -n 1

echo "Tesseract:"
tesseract --version | head -n 1

echo "Ghostscript:"
gs --version

echo "ImageMagick:"
convert --version | head -n 1 || magick -version | head -n 1

echo "======================================"
echo "Build Completed Successfully"
echo "======================================"
