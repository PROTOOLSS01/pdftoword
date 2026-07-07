<?php
// config.php

namespace App;

class Config
{
    private static $instance = null;
    private $config = [];

    private function __construct()
    {
        $this->loadEnvironment();
        $this->config = [
            'app' => [
                'env' => $_ENV['APP_ENV'] ?? 'production',
                'debug' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'url' => $_ENV['APP_URL'] ?? 'http://localhost',
                'name' => 'PDF to Word Converter',
                'version' => '1.0.0',
                'timezone' => 'UTC'
            ],
            'upload' => [
                'max_size' => $this->parseSize($_ENV['UPLOAD_MAX_SIZE'] ?? '100M'),
                'allowed_extensions' => ['pdf'],
                'allowed_mime_types' => ['application/pdf'],
                'directory' => $_ENV['UPLOAD_DIR'] ?? 'uploads',
                'temp_directory' => $_ENV['TEMP_DIR'] ?? 'temp',
                'max_execution_time' => (int)($_ENV['MAX_EXECUTION_TIME'] ?? 300),
                'memory_limit' => $_ENV['MEMORY_LIMIT'] ?? '512M'
            ],
            'security' => [
                'csrf_token_name' => $_ENV['CSRF_TOKEN_NAME'] ?? 'csrf_token',
                'csrf_token_length' => (int)($_ENV['CSRF_TOKEN_LENGTH'] ?? 32),
                'session_lifetime' => (int)($_ENV['SESSION_LIFETIME'] ?? 3600),
                'allowed_origins' => ['*']
            ],
            'logging' => [
                'level' => $_ENV['LOG_LEVEL'] ?? 'error',
                'file' => $_ENV['LOG_FILE'] ?? 'app.log',
                'directory' => $_ENV['LOG_DIR'] ?? 'logs'
            ],
            'cleanup' => [
                'auto_delete_after' => (int)($_ENV['AUTO_DELETE_AFTER'] ?? 3600),
                'max_file_age' => (int)($_ENV['MAX_FILE_AGE'] ?? 86400)
            ],
            'libreoffice' => [
                'path' => $_ENV['LIBREOFFICE_PATH'] ?? '/usr/bin/libreoffice',
                'timeout' => 300,
                'headless' => true,
                'nologo' => true,
                'norestore' => true
            ],
            'poppler' => [
                'pdftoppm_path' => $_ENV['PDFTOPPPM_PATH'] ?? '/usr/bin/pdftoppm',
                'resolution' => 300,
                'format' => 'png'
            ],
            'ghostscript' => [
                'path' => $_ENV['GS_PATH'] ?? '/usr/bin/gs',
                'device' => 'png16m',
                'resolution' => 300
            ],
            'tesseract' => [
                'path' => $_ENV['TESSERACT_PATH'] ?? '/usr/bin/tesseract',
                'language' => $_ENV['TESSERACT_LANG'] ?? 'eng'
            ],
            'paths' => [
                'root' => dirname(__DIR__),
                'public' => dirname(__DIR__) . '/public',
                'uploads' => dirname(__DIR__) . '/uploads',
                'temp' => dirname(__DIR__) . '/temp',
                'logs' => dirname(__DIR__) . '/logs'
            ]
        ];
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function get(string $key, $default = null)
    {
        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $segment) {
            if (!isset($value[$segment])) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    private function loadEnvironment(): void
    {
        $envFile = dirname(__DIR__) . '/.env';
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
                    list($key, $value) = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value);
                    if (!isset($_ENV[$key]) && !isset($_SERVER[$key])) {
                        $_ENV[$key] = $value;
                        $_SERVER[$key] = $value;
                        putenv("$key=$value");
                    }
                }
            }
        }
    }

    private function parseSize(string $size): int
    {
        $unit = strtoupper(substr($size, -1));
        $value = (int)substr($size, 0, -1);

        switch ($unit) {
            case 'G': return $value * 1024 * 1024 * 1024;
            case 'M': return $value * 1024 * 1024;
            case 'K': return $value * 1024;
            default: return (int)$size;
        }
    }

    private function __clone() {}
    public function __wakeup() {}
}
