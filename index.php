<?php
// functions.php

namespace App;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;

// Load Composer autoloader
require_once dirname(__DIR__) . '/vendor/autoload.php';

class PDFToWordConverter
{
    private $config;
    private $logger;
    private $filesystem;
    private $uploadDir;
    private $tempDir;
    private $logDir;

    public function __construct()
    {
        $this->config = Config::getInstance();
        $this->filesystem = new Filesystem();
        $this->uploadDir = $this->config->get('upload.directory');
        $this->tempDir = $this->config->get('upload.temp_directory');
        $this->logDir = $this->config->get('logging.directory');
        
        $this->initializeDirectories();
        $this->initializeLogger();
    }

    private function initializeDirectories(): void
    {
        $dirs = [
            $this->uploadDir,
            $this->tempDir,
            $this->logDir,
            'public'
        ];

        foreach ($dirs as $dir) {
            $path = dirname(__DIR__) . '/' . $dir;
            if (!$this->filesystem->exists($path)) {
                $this->filesystem->mkdir($path, 0775);
            }
            $this->filesystem->chmod($path, 0775);
        }
    }

    private function initializeLogger(): void
    {
        $logLevel = $this->config->get('logging.level', 'error');
        $logLevel = strtoupper($logLevel);
        
        $logPath = dirname(__DIR__) . '/' . $this->logDir . '/' . $this->config->get('logging.file', 'app.log');
        
        $this->logger = new Logger('pdf_converter');
        
        // Add rotating file handler
        $handler = new RotatingFileHandler($logPath, 30, $logLevel);
        $this->logger->pushHandler($handler);
        
        // Add console handler for development
        if ($this->config->get('app.debug', false)) {
            $consoleHandler = new StreamHandler('php://stdout', $logLevel);
            $this->logger->pushHandler($consoleHandler);
        }
    }

    public function convert(string $filePath): array
    {
        $startTime = microtime(true);
        $fileInfo = pathinfo($filePath);
        $baseName = $fileInfo['filename'];
        $tempDir = dirname(__DIR__) . '/' . $this->tempDir . '/' . uniqid('convert_', true);
        
        try {
            $this->logger->info('Starting PDF to Word conversion', ['file' => $filePath]);
            
            // Create temp directory
            $this->filesystem->mkdir($tempDir, 0775);
            
            // Validate file
            $this->validateFile($filePath);
            
            // First attempt: LibreOffice conversion
            $docxPath = $this->convertWithLibreOffice($filePath, $tempDir);
            
            if ($docxPath && file_exists($docxPath) && filesize($docxPath) > 0) {
                $result = $this->handleSuccess($filePath, $docxPath, $startTime);
                $this->cleanup($tempDir);
                return $result;
            }
            
            // Fallback: OCR-based conversion
            $this->logger->info('LibreOffice conversion failed, using OCR fallback');
            $docxPath = $this->convertWithOCR($filePath, $tempDir, $baseName);
            
            if ($docxPath && file_exists($docxPath) && filesize($docxPath) > 0) {
                $result = $this->handleSuccess($filePath, $docxPath, $startTime);
                $this->cleanup($tempDir);
                return $result;
            }
            
            throw new \Exception('All conversion methods failed');
            
        } catch (\Exception $e) {
            $this->logger->error('Conversion failed', [
                'error' => $e->getMessage(),
                'file' => $filePath,
                'trace' => $e->getTraceAsString()
            ]);
            $this->cleanup($tempDir);
            throw $e;
        }
    }

    private function convertWithLibreOffice(string $inputPath, string $outputDir): ?string
    {
        try {
            $outputFile = $outputDir . '/' . pathinfo($inputPath, PATHINFO_FILENAME) . '.docx';
            
            $command = [
                $this->config->get('libreoffice.path'),
                '--headless',
                '--nologo',
                '--norestore',
                '--convert-to',
                'docx',
                '--outdir',
                $outputDir,
                $inputPath
            ];
            
            $process = new Process($command);
            $process->setTimeout($this->config->get('libreoffice.timeout', 300));
            $process->run();
            
            if ($process->isSuccessful() && file_exists($outputFile)) {
                $this->logger->info('LibreOffice conversion successful', ['output' => $outputFile]);
                return $outputFile;
            }
            
            $this->logger->warning('LibreOffice conversion failed', [
                'output' => $process->getOutput(),
                'error' => $process->getErrorOutput()
            ]);
            
            return null;
            
        } catch (\Exception $e) {
            $this->logger->error('LibreOffice conversion exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function convertWithOCR(string $inputPath, string $outputDir, string $baseName): ?string
    {
        try {
            $imagesDir = $outputDir . '/images';
            $this->filesystem->mkdir($imagesDir, 0775);
            
            // Convert PDF to images
            $images = $this->pdfToImages($inputPath, $imagesDir, $baseName);
            
            if (empty($images)) {
                $this->logger->error('No images generated from PDF');
                return null;
            }
            
            // Process each image with OCR
            $texts = [];
            foreach ($images as $imagePath) {
                $text = $this->ocrImage($imagePath);
                if ($text) {
                    $texts[] = $text;
                }
            }
            
            if (empty($texts)) {
                $this->logger->error('No text extracted from images');
                return null;
            }
            
            // Generate DOCX
            $docxPath = $this->generateDocx($texts, $outputDir, $baseName);
            
            if ($docxPath && file_exists($docxPath)) {
                $this->logger->info('OCR conversion successful', ['output' => $docxPath]);
                return $docxPath;
            }
            
            return null;
            
        } catch (\Exception $e) {
            $this->logger->error('OCR conversion exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function pdfToImages(string $pdfPath, string $outputDir, string $baseName): array
    {
        $images = [];
        $outputPattern = $outputDir . '/' . $baseName . '_page_%d.png';
        
        try {
            $command = [
                $this->config->get('poppler.pdftoppm_path'),
                '-png',
                '-r', $this->config->get('poppler.resolution', 300),
                $pdfPath,
                $outputDir . '/' . $baseName . '_page'
            ];
            
            $process = new Process($command);
            $process->setTimeout(300);
            $process->run();
            
            if ($process->isSuccessful()) {
                $files = glob($outputDir . '/' . $baseName . '_page_*.png');
                sort($files);
                $images = $files;
                $this->logger->info('PDF to images conversion successful', ['count' => count($images)]);
            } else {
                $this->logger->error('PDF to images conversion failed', [
                    'output' => $process->getOutput(),
                    'error' => $process->getErrorOutput()
                ]);
            }
            
        } catch (\Exception $e) {
            $this->logger->error('PDF to images exception', ['error' => $e->getMessage()]);
        }
        
        return $images;
    }

    private function ocrImage(string $imagePath): ?string
    {
        try {
            $outputPath = pathinfo($imagePath, PATHINFO_DIRNAME) . '/' . pathinfo($imagePath, PATHINFO_FILENAME) . '_ocr';
            
            $command = [
                $this->config->get('tesseract.path'),
                $imagePath,
                $outputPath,
                '-l', $this->config->get('tesseract.language'),
                '--oem', '3',
                '--psm', '6'
            ];
            
            $process = new Process($command);
            $process->setTimeout(60);
            $process->run();
            
            $txtPath = $outputPath . '.txt';
            if ($process->isSuccessful() && file_exists($txtPath)) {
                $text = file_get_contents($txtPath);
                $this->filesystem->remove($txtPath);
                return $text;
            }
            
            $this->logger->warning('OCR failed for image', ['image' => $imagePath]);
            return null;
            
        } catch (\Exception $e) {
            $this->logger->error('OCR exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function generateDocx(array $texts, string $outputDir, string $baseName): ?string
    {
        try {
            $phpWord = new PhpWord();
            $phpWord->setDefaultFontName('Arial');
            $phpWord->setDefaultFontSize(12);
            
            foreach ($texts as $index => $text) {
                $section = $phpWord->addSection();
                $section->addText($text);
                
                // Add page break between pages
                if ($index < count($texts) - 1) {
                    $section->addPageBreak();
                }
            }
            
            $docxPath = $outputDir . '/' . $baseName . '_converted.docx';
            $writer = IOFactory::createWriter($phpWord, 'Word2007');
            $writer->save($docxPath);
            
            $this->logger->info('DOCX generated successfully', ['path' => $docxPath]);
            return $docxPath;
            
        } catch (\Exception $e) {
            $this->logger->error('DOCX generation failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function validateFile(string $filePath): void
    {
        if (!file_exists($filePath)) {
            throw new \Exception('File not found: ' . $filePath);
        }
        
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $allowedExtensions = $this->config->get('upload.allowed_extensions', ['pdf']);
        
        if (!in_array($extension, $allowedExtensions)) {
            throw new \Exception('Invalid file extension: ' . $extension);
        }
        
        $mimeType = mime_content_type($filePath);
        $allowedMimeTypes = $this->config->get('upload.allowed_mime_types', ['application/pdf']);
        
        if (!in_array($mimeType, $allowedMimeTypes)) {
            throw new \Exception('Invalid MIME type: ' . $mimeType);
        }
        
        $maxSize = $this->config->get('upload.max_size', 104857600);
        if (filesize($filePath) > $maxSize) {
            throw new \Exception('File size exceeds limit: ' . filesize($filePath) . ' bytes');
        }
    }

    private function handleSuccess(string $inputPath, string $outputPath, float $startTime): array
    {
        $duration = microtime(true) - $startTime;
        $fileSize = filesize($outputPath);
        
        // Move file to uploads directory with unique name
        $finalPath = dirname(__DIR__) . '/' . $this->uploadDir . '/' . uniqid('docx_', true) . '.docx';
        $this->filesystem->copy($outputPath, $finalPath);
        $this->filesystem->chmod($finalPath, 0664);
        
        $this->logger->info('Conversion completed successfully', [
            'duration' => $duration,
            'file_size' => $fileSize,
            'input' => $inputPath,
            'output' => $finalPath
        ]);
        
        return [
            'success' => true,
            'file' => basename($finalPath),
            'size' => $fileSize,
            'duration' => round($duration, 2),
            'download_url' => '/download.php?file=' . basename($finalPath),
            'message' => 'Conversion completed successfully'
        ];
    }

    private function cleanup(string $dir): void
    {
        try {
            if ($this->filesystem->exists($dir)) {
                $this->filesystem->remove($dir);
                $this->logger->info('Cleaned up temp directory', ['dir' => $dir]);
            }
        } catch (\Exception $e) {
            $this->logger->warning('Failed to clean up temp directory', [
                'dir' => $dir,
                'error' => $e->getMessage()
            ]);
        }
    }

    public function cleanupOldFiles(): void
    {
        $maxAge = $this->config->get('cleanup.max_file_age', 86400);
        $directories = [
            dirname(__DIR__) . '/' . $this->uploadDir,
            dirname(__DIR__) . '/' . $this->tempDir
        ];
        
        foreach ($directories as $directory) {
            if (!$this->filesystem->exists($directory)) {
                continue;
            }
            
            $files = glob($directory . '/*');
            $now = time();
            
            foreach ($files as $file) {
                if (is_file($file)) {
                    $fileAge = $now - filemtime($file);
                    if ($fileAge > $maxAge) {
                        try {
                            $this->filesystem->remove($file);
                            $this->logger->info('Removed old file', ['file' => $file, 'age' => $fileAge]);
                        } catch (\Exception $e) {
                            $this->logger->warning('Failed to remove old file', [
                                'file' => $file,
                                'error' => $e->getMessage()
                            ]);
                        }
                    }
                }
            }
        }
    }

    public function getLogger(): Logger
    {
        return $this->logger;
    }
}

// Helper functions
function generateCSRFToken(): string
{
    if (!session_id()) {
        session_start();
    }
    
    $config = Config::getInstance();
    $tokenName = $config->get('security.csrf_token_name', 'csrf_token');
    $tokenLength = $config->get('security.csrf_token_length', 32);
    
    if (empty($_SESSION[$tokenName])) {
        $_SESSION[$tokenName] = bin2hex(random_bytes($tokenLength));
    }
    
    return $_SESSION[$tokenName];
}

function verifyCSRFToken(?string $token): bool
{
    if (!session_id()) {
        session_start();
    }
    
    $config = Config::getInstance();
    $tokenName = $config->get('security.csrf_token_name', 'csrf_token');
    
    if (empty($_SESSION[$tokenName]) || empty($token)) {
        return false;
    }
    
    return hash_equals($_SESSION[$tokenName], $token);
}

function sanitizeInput(string $input): string
{
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

function validateFileUpload(array $file): array
{
    $errors = [];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Upload error: ' . $file['error'];
    }
    
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        $errors[] = 'Invalid file upload';
    }
    
    $config = Config::getInstance();
    $maxSize = $config->get('upload.max_size', 104857600);
    
    if ($file['size'] > $maxSize) {
        $errors[] = 'File size exceeds limit: ' . $file['size'];
    }
    
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExtensions = $config->get('upload.allowed_extensions', ['pdf']);
    
    if (!in_array($extension, $allowedExtensions)) {
        $errors[] = 'Invalid file extension: ' . $extension;
    }
    
    $mimeType = mime_content_type($file['tmp_name']);
    $allowedMimeTypes = $config->get('upload.allowed_mime_types', ['application/pdf']);
    
    if (!in_array($mimeType, $allowedMimeTypes)) {
        $errors[] = 'Invalid MIME type: ' . $mimeType;
    }
    
    return $errors;
}

function logMessage(string $level, string $message, array $context = []): void
{
    try {
        $converter = new PDFToWordConverter();
        $logger = $converter->getLogger();
        $logger->log($level, $message, $context);
    } catch (\Exception $e) {
        error_log('Failed to log message: ' . $e->getMessage());
    }
}

function createResponse(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function redirect(string $url, int $statusCode = 302): void
{
    http_response_code($statusCode);
    header('Location: ' . $url);
    exit;
}
