#!/bin/bash
set -e

echo "Updating package list..."
apt-get update -qq

echo "Installing LibreOffice..."
apt-get install -y -qq libreoffice

echo "Installing Poppler Utils (pdftotext)..."
apt-get install -y -qq poppler-utils

echo "Creating upload, output, and temp directories..."
mkdir -p /var/www/html/uploads /var/www/html/output /var/www/html/temp

echo "Setting permissions..."
chown -R www-data:www-data /var/www/html
chmod -R 755 /var/www/html/uploads /var/www/html/output /var/www/html/temp

echo "Verifying LibreOffice installation..."
libreoffice --version

echo "Verifying Poppler installation..."
pdftotext -v 2>&1 | head -n 1

echo "All setup completed successfully!"
