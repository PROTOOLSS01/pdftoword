#!/bin/bash

echo "====================================="
echo "📄 PDF to Word Converter Startup Script"
echo "====================================="

# Check if we're in the correct directory
if [ ! -f "index.php" ]; then
    echo "❌ Error: index.php not found in current directory"
    exit 1
fi

echo "✅ Working directory: $(pwd)"

# Check PHP version
PHP_VERSION=$(php -v | head -n 1 | cut -d' ' -f2)
echo "✅ PHP Version: $PHP_VERSION"

# Check for required commands
echo "Checking required binaries..."

# Check PHP
if ! command -v php &> /dev/null; then
    echo "❌ PHP is not installed"
    exit 1
else
    echo "✅ PHP found: $(which php)"
fi

# Check Composer
if ! command -v composer &> /dev/null; then
    echo "⚠️  Composer not found, installing..."
    curl -sS https://getcomposer.org/installer | php
    mv composer.phar /usr/local/bin/composer
    echo "✅ Composer installed"
else
    echo "✅ Composer found: $(which composer)"
fi

# Check LibreOffice
if ! command -v libreoffice &> /dev/null; then
    echo "⚠️  LibreOffice not found, some features may be limited"
else
    echo "✅ LibreOffice found: $(which libreoffice)"
fi

# Check Ghostscript
if ! command -v gs &> /dev/null; then
    echo "⚠️  Ghostscript not found, OCR features may be limited"
else
    echo "✅ Ghostscript found: $(which gs)"
fi

# Check Tesseract
if ! command -v tesseract &> /dev/null; then
    echo "⚠️  Tesseract not found, OCR features may be limited"
else
    echo "✅ Tesseract found: $(which tesseract)"
    TESSERACT_VERSION=$(tesseract --version | head -n 1)
    echo "   Version: $TESSERACT_VERSION"
fi

# Check if composer dependencies are installed
if [ ! -d "vendor" ] || [ ! -f "vendor/autoload.php" ]; then
    echo "📦 Installing Composer dependencies..."
    composer install --no-dev --optimize-autoloader --no-interaction
else
    echo "✅ Composer dependencies found"
fi

# Create necessary directories with proper permissions
echo "📁 Creating necessary directories..."
mkdir -p uploads outputs
chmod 777 uploads outputs

# Check directory permissions
if [ -w "uploads" ] && [ -w "outputs" ]; then
    echo "✅ Upload and output directories are writable"
else
    echo "❌ Upload or output directories are not writable"
    exit 1
fi

# Configure PHP settings for the application
echo "⚙️  Configuring PHP settings..."
export PHP_MEMORY_LIMIT=512M
export PHP_MAX_EXECUTION_TIME=300
export PHP_UPLOAD_MAX_FILESIZE=100M
export PHP_POST_MAX_SIZE=100M

# Create a .htaccess file for additional security and configuration
echo "🔒 Creating .htaccess file..."
cat > .htaccess << 'EOF'
<FilesMatch "\.(json|env)$">
    Order allow,deny
    Deny from all
</FilesMatch>

<FilesMatch "\.(log|txt|sql|md)$">
    Order allow,deny
    Deny from all
</FilesMatch>

Options -Indexes

<Limit GET POST PUT DELETE>
    Order allow,deny
    Allow from all
</Limit>

<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [QSA,L]
</IfModule>
EOF

echo "✅ .htaccess file created"

# Check for existing temporary files and clean them
echo "🧹 Cleaning old temporary files..."
if [ -d "uploads" ]; then
    find uploads -type f -mmin +60 -delete 2>/dev/null || true
fi
if [ -d "outputs" ]; then
    find outputs -type f -mmin +60 -delete 2>/dev/null || true
fi
echo "✅ Temporary files cleaned"

# Display application information
echo ""
echo "====================================="
echo "🚀 Application is ready to start!"
echo "====================================="
echo "📄 PDF to Word Converter"
echo "🌐 Listening on port 80"
echo "📁 Upload limit: 100MB"
echo "⏱️  Max execution time: 300s"
echo "💾 Memory limit: 512M"
echo "====================================="

# Start Apache in foreground
echo "🔄 Starting Apache..."
apache2-foreground
