<?php
/**
 * PDF to DOCX Converter
 * 
 * A secure, production-ready single-file PHP application that converts PDF files to DOCX
 * using LibreOffice, Tesseract OCR for scanned documents, and Poppler utilities.
 * 
 * Requirements:
 * - PHP 8.2+
 * - LibreOffice (headless)
 * - Tesseract OCR
 * - Poppler-utils (pdftoppm, pdfinfo)
 * - PHP extensions: fileinfo, json, session
 * 
 * @package PDF2DOCX
 * @version 1.0.0
 */

declare(strict_types=1);

// ============================================================================
// Configuration
// ============================================================================

define('MAX_FILE_SIZE', 100 * 1024 * 1024); // 100 MB
define('UPLOAD_DIR', sys_get_temp_dir() . '/pdf2docx_uploads/');
define('OUTPUT_DIR', sys_get_temp_dir() . '/pdf2docx_output/');
define('TEMP_DIR', sys_get_temp_dir() . '/pdf2docx_temp/');
define('MAX_EXECUTION_TIME', 600); // 10 minutes
define('CLEANUP_AFTER_SECONDS', 3600); // 1 hour
define('LOG_FILE', __DIR__ . '/pdf2docx_errors.log');

// ============================================================================
// Error Handling & Logging
// ============================================================================

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    if (error_reporting() & $errno) {
        logError("PHP Error [{$errno}]: {$errstr} in {$errfile}:{$errline}");
    }
    return false;
});

set_exception_handler(function (Throwable $e) {
    logError("Uncaught Exception: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['error' => 'An internal server error occurred.']);
    exit;
});

/**
 * Log error messages to the log file
 * 
 * @param string $message Error message to log
 */
function logError(string $message): void
{
    $date = date('Y-m-d H:i:s');
    $logEntry = "[{$date}] {$message}" . PHP_EOL;
    @file_put_contents(LOG_FILE, $logEntry, FILE_APPEND | LOCK_EX);
}

// ============================================================================
// Session & CSRF Protection
// ============================================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Generate a CSRF token
 * 
 * @return string CSRF token
 */
function generateCSRFToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate CSRF token
 * 
 * @param string $token Token to validate
 * @return bool True if valid
 */
function validateCSRFToken(string $token): bool
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// ============================================================================
// File System Utilities
// ============================================================================

/**
 * Create temporary directories with proper permissions
 */
function initializeDirectories(): void
{
    $dirs = [UPLOAD_DIR, OUTPUT_DIR, TEMP_DIR];
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                throw new RuntimeException("Failed to create directory: {$dir}");
            }
        }
        // Clean up old files
        cleanupOldFiles($dir);
    }
}

/**
 * Clean up files older than CLEANUP_AFTER_SECONDS
 * 
 * @param string $directory Directory to clean
 */
function cleanupOldFiles(string $directory): void
{
    $now = time();
    $files = @scandir($directory);
    if ($files === false) {
        return;
    }
    
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        $path = $directory . '/' . $file;
        if (is_file($path) && ($now - filemtime($path) > CLEANUP_AFTER_SECONDS)) {
            @unlink($path);
        }
    }
}

/**
 * Generate a secure random filename
 * 
 * @param string $extension File extension (without dot)
 * @return string Secure filename
 */
function generateSecureFilename(string $extension = ''): string
{
    $random = bin2hex(random_bytes(16));
    return $random . ($extension ? '.' . $extension : '');
}

/**
 * Delete a file and log any errors
 * 
 * @param string $file Path to file
 * @return bool True if deleted or doesn't exist
 */
function secureDelete(string $file): bool
{
    if (!file_exists($file)) {
        return true;
    }
    if (!is_writable($file)) {
        logError("Cannot delete file (not writable): {$file}");
        return false;
    }
    return @unlink($file);
}

/**
 * Delete all files in a directory
 * 
 * @param string $directory Directory to clear
 */
function clearDirectory(string $directory): void
{
    $files = @scandir($directory);
    if ($files === false) {
        return;
    }
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        $path = $directory . '/' . $file;
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

// ============================================================================
// File Validation
// ============================================================================

/**
 * Validate uploaded file
 * 
 * @param array $file $_FILES array entry
 * @return array Validated file info
 * @throws RuntimeException on validation failure
 */
function validateUploadedFile(array $file): array
{
    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize directive',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'File upload stopped by extension',
        ];
        $errorMsg = $errors[$file['error']] ?? 'Unknown upload error';
        throw new RuntimeException('Upload error: ' . $errorMsg);
    }
    
    // Check file size
    if ($file['size'] > MAX_FILE_SIZE) {
        throw new RuntimeException('File size exceeds maximum allowed size of 100 MB.');
    }
    if ($file['size'] === 0) {
        throw new RuntimeException('Empty file uploaded.');
    }
    
    // Validate MIME type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if ($mimeType !== 'application/pdf') {
        throw new RuntimeException('Only PDF files are allowed. MIME type: ' . $mimeType);
    }
    
    // Validate file extension
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($extension !== 'pdf') {
        throw new RuntimeException('File must have .pdf extension.');
    }
    
    return [
        'name' => $file['name'],
        'tmp_name' => $file['tmp_name'],
        'size' => $file['size'],
        'mime' => $mimeType,
    ];
}

// ============================================================================
// PDF Detection & Conversion
// ============================================================================

/**
 * Check if a PDF is scanned (contains images instead of text)
 * 
 * @param string $pdfPath Path to PDF file
 * @return bool True if scanned (no text content)
 */
function isScannedPDF(string $pdfPath): bool
{
    // Use pdfinfo from Poppler to check for text
    $cmd = sprintf(
        'pdfinfo "%s" 2>/dev/null | grep -i "Pages:"',
        escapeshellarg($pdfPath)
    );
    $output = shell_exec($cmd);
    
    if ($output === null) {
        // If pdfinfo fails, assume it might be scanned
        logError("pdfinfo failed for: {$pdfPath}");
        return true;
    }
    
    // Try to extract text using pdftotext
    $textFile = TEMP_DIR . generateSecureFilename('txt');
    $cmd = sprintf(
        'pdftotext -q "%s" "%s" 2>/dev/null',
        escapeshellarg($pdfPath),
        escapeshellarg($textFile)
    );
    shell_exec($cmd);
    
    $hasText = false;
    if (file_exists($textFile)) {
        $content = @file_get_contents($textFile);
        $hasText = $content !== false && trim($content) !== '';
        @unlink($textFile);
    }
    
    return !$hasText;
}

/**
 * Perform OCR on a PDF using Tesseract
 * 
 * @param string $pdfPath Path to PDF file
 * @return string Path to OCR-processed PDF with text layer
 * @throws RuntimeException on OCR failure
 */
function performOCR(string $pdfPath): string
{
    $outputPdf = TEMP_DIR . generateSecureFilename('pdf');
    
    // Convert PDF to images using pdftoppm
    $imagePrefix = TEMP_DIR . generateSecureFilename('page');
    $cmd = sprintf(
        'pdftoppm -jpeg -r 300 "%s" "%s" 2>/dev/null',
        escapeshellarg($pdfPath),
        escapeshellarg($imagePrefix)
    );
    $result = shell_exec($cmd);
    
    // Find all generated images
    $images = glob($imagePrefix . '*.jpg');
    if (empty($images)) {
        throw new RuntimeException('Failed to extract images from PDF for OCR.');
    }
    
    // Perform OCR on each image and merge into a searchable PDF
    $ocrImages = [];
    foreach ($images as $index => $image) {
        $ocrImage = TEMP_DIR . generateSecureFilename('jpg');
        // Use Tesseract to OCR and produce a PDF with text layer
        $cmd = sprintf(
            'tesseract "%s" "%s" -l eng --oem 3 --psm 6 pdf 2>/dev/null',
            escapeshellarg($image),
            escapeshellarg(dirname($ocrImage) . '/' . pathinfo($ocrImage, PATHINFO_FILENAME))
        );
        shell_exec($cmd);
        
        // Find the generated PDF
        $pdfFile = dirname($ocrImage) . '/' . pathinfo($ocrImage, PATHINFO_FILENAME) . '.pdf';
        if (!file_exists($pdfFile)) {
            continue;
        }
        $ocrImages[] = $pdfFile;
        
        // Clean up the original image
        @unlink($image);
    }
    
    if (empty($ocrImages)) {
        throw new RuntimeException('OCR processing failed to produce any output.');
    }
    
    // Merge all OCR PDFs into one using pdftk or ghostscript
    if (count($ocrImages) === 1) {
        copy($ocrImages[0], $outputPdf);
    } else {
        // Use Ghostscript to merge PDFs
        $cmd = sprintf(
            'gs -q -dNOPAUSE -dBATCH -sDEVICE=pdfwrite -sOutputFile="%s" %s 2>/dev/null',
            escapeshellarg($outputPdf),
            implode(' ', array_map('escapeshellarg', $ocrImages))
        );
        shell_exec($cmd);
    }
    
    // Clean up OCR images
    foreach ($ocrImages as $file) {
        @unlink($file);
    }
    
    if (!file_exists($outputPdf) || filesize($outputPdf) === 0) {
        throw new RuntimeException('OCR processing failed to create a valid PDF.');
    }
    
    return $outputPdf;
}

/**
 * Convert PDF to DOCX using LibreOffice
 * 
 * @param string $pdfPath Path to PDF file
 * @param string $outputPath Path for the output DOCX
 * @throws RuntimeException on conversion failure
 */
function convertPDFToDOCX(string $pdfPath, string $outputPath): void
{
    // Ensure output directory exists
    $outputDir = dirname($outputPath);
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
    }
    
    // Use LibreOffice headless mode
    $cmd = sprintf(
        'timeout %d libreoffice --headless --convert-to docx:"Office Open XML Text" --outdir "%s" "%s" 2>&1',
        MAX_EXECUTION_TIME,
        escapeshellarg($outputDir),
        escapeshellarg($pdfPath)
    );
    
    $output = shell_exec($cmd);
    
    // LibreOffice generates output in the same directory with .docx extension
    $expectedDocx = $outputDir . '/' . pathinfo($pdfPath, PATHINFO_FILENAME) . '.docx';
    
    if (!file_exists($expectedDocx) || filesize($expectedDocx) === 0) {
        logError("LibreOffice conversion failed. Output: " . ($output ?? 'No output'));
        throw new RuntimeException('Conversion failed. Please check if LibreOffice is installed and the PDF is valid.');
    }
    
    // Move to the desired output path
    if (!rename($expectedDocx, $outputPath)) {
        throw new RuntimeException('Failed to move converted file.');
    }
}

// ============================================================================
// Main Processing Logic
// ============================================================================

/**
 * Process the PDF upload and convert to DOCX
 * 
 * @param array $file $_FILES['pdf'] array
 * @param string $csrfToken CSRF token for validation
 * @return array Response data
 */
function processUpload(array $file, string $csrfToken): array
{
    // Validate CSRF
    if (!validateCSRFToken($csrfToken)) {
        throw new RuntimeException('Invalid CSRF token. Please refresh the page and try again.');
    }
    
    // Initialize directories
    initializeDirectories();
    
    // Validate uploaded file
    $validated = validateUploadedFile($file);
    
    // Generate unique filenames
    $inputFilename = generateSecureFilename('pdf');
    $outputFilename = generateSecureFilename('docx');
    $inputPath = UPLOAD_DIR . $inputFilename;
    $outputPath = OUTPUT_DIR . $outputFilename;
    
    // Move uploaded file to secure location
    if (!move_uploaded_file($validated['tmp_name'], $inputPath)) {
        throw new RuntimeException('Failed to store uploaded file.');
    }
    
    try {
        // Check if PDF is scanned
        $isScanned = isScannedPDF($inputPath);
        
        $pdfToConvert = $inputPath;
        if ($isScanned) {
            // Perform OCR
            try {
                $pdfToConvert = performOCR($inputPath);
            } catch (RuntimeException $e) {
                logError("OCR failed for {$inputPath}: " . $e->getMessage());
                // Fallback: try direct conversion even if OCR fails
                $pdfToConvert = $inputPath;
            }
        }
        
        // Convert to DOCX
        convertPDFToDOCX($pdfToConvert, $outputPath);
        
        // Clean up OCR file if it was created
        if ($pdfToConvert !== $inputPath && file_exists($pdfToConvert)) {
            @unlink($pdfToConvert);
        }
        
        // Return success response
        return [
            'success' => true,
            'filename' => pathinfo($validated['name'], PATHINFO_FILENAME) . '.docx',
            'download' => 'download.php?file=' . urlencode($outputFilename) . '&token=' . urlencode(generateCSRFToken()),
        ];
        
    } catch (Exception $e) {
        // Clean up input file
        if (file_exists($inputPath)) {
            @unlink($inputPath);
        }
        if (file_exists($outputPath)) {
            @unlink($outputPath);
        }
        throw $e;
    }
}

// ============================================================================
// Download Handler
// ============================================================================

/**
 * Handle file download
 */
function handleDownload(): void
{
    if (!isset($_GET['file']) || !isset($_GET['token'])) {
        http_response_code(400);
        echo 'Missing parameters.';
        exit;
    }
    
    $file = $_GET['file'];
    $token = $_GET['token'];
    
    // Validate token
    if (!validateCSRFToken($token)) {
        http_response_code(403);
        echo 'Invalid or expired token.';
        exit;
    }
    
    // Validate file path
    $filePath = OUTPUT_DIR . $file;
    if (!file_exists($filePath) || !is_file($filePath)) {
        http_response_code(404);
        echo 'File not found.';
        exit;
    }
    
    // Validate file extension
    if (pathinfo($file, PATHINFO_EXTENSION) !== 'docx') {
        http_response_code(403);
        echo 'Invalid file type.';
        exit;
    }
    
    // Generate a safe filename for download
    $downloadName = 'converted_' . date('Y-m-d_H-i-s') . '.docx';
    
    // Set headers for download
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    
    // Stream the file
    if ($handle = fopen($filePath, 'rb')) {
        while (!feof($handle)) {
            echo fread($handle, 8192);
            ob_flush();
            flush();
        }
        fclose($handle);
    }
    
    // Delete the file after download
    @unlink($filePath);
    exit;
}

// ============================================================================
// API Endpoint Handler
// ============================================================================

/**
 * Handle API requests
 */
function handleAPI(): void
{
    header('Content-Type: application/json');
    
    // Only accept POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed.']);
        return;
    }
    
    // Check for file upload
    if (!isset($_FILES['pdf'])) {
        http_response_code(400);
        echo json_encode(['error' => 'No file uploaded.']);
        return;
    }
    
    // Check for CSRF token
    $headers = getallheaders();
    $csrfToken = $_POST['csrf_token'] ?? ($headers['X-CSRF-Token'] ?? '');
    
    try {
        $result = processUpload($_FILES['pdf'], $csrfToken);
        echo json_encode($result);
    } catch (RuntimeException $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    } catch (Exception $e) {
        logError("Unexpected error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        http_response_code(500);
        echo json_encode(['error' => 'An unexpected error occurred.']);
    }
}

// ============================================================================
// HTML Interface
// ============================================================================

/**
 * Render the main HTML interface
 */
function renderHTML(): void
{
    $csrfToken = generateCSRFToken();
    $maxFileSize = MAX_FILE_SIZE;
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PDF to DOCX Converter</title>
    <style>
        /* ===== RESET & BASE ===== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            margin: 0;
        }
        
        /* ===== CONTAINER ===== */
        .container {
            background: #ffffff;
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.15);
            padding: 40px;
            max-width: 600px;
            width: 100%;
            transition: all 0.3s ease;
        }
        
        /* ===== HEADER ===== */
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        .header h1 {
            font-size: 28px;
            font-weight: 700;
            color: #1a1a2e;
            letter-spacing: -0.5px;
        }
        .header p {
            color: #666;
            font-size: 14px;
            margin-top: 6px;
        }
        
        /* ===== DROP ZONE ===== */
        .drop-zone {
            border: 2px dashed #d1d5db;
            border-radius: 16px;
            padding: 40px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: #fafbfc;
            position: relative;
        }
        .drop-zone:hover,
        .drop-zone.dragover {
            border-color: #4f46e5;
            background: #f0f1ff;
        }
        .drop-zone .icon {
            font-size: 48px;
            margin-bottom: 12px;
            display: block;
            color: #4f46e5;
        }
        .drop-zone .title {
            font-size: 18px;
            font-weight: 600;
            color: #1a1a2e;
        }
        .drop-zone .subtitle {
            font-size: 14px;
            color: #888;
            margin-top: 4px;
        }
        .drop-zone .file-types {
            font-size: 12px;
            color: #aaa;
            margin-top: 8px;
        }
        .drop-zone input[type="file"] {
            display: none;
        }
        
        /* ===== FILE INFO ===== */
        .file-info {
            display: none;
            align-items: center;
            gap: 12px;
            padding: 14px 18px;
            background: #f0f4ff;
            border-radius: 12px;
            margin-top: 16px;
            border: 1px solid #dbeafe;
        }
        .file-info.show {
            display: flex;
        }
        .file-info .file-icon {
            font-size: 28px;
            color: #4f46e5;
            flex-shrink: 0;
        }
        .file-info .file-details {
            flex: 1;
            min-width: 0;
        }
        .file-info .file-name {
            font-weight: 600;
            color: #1a1a2e;
            font-size: 14px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .file-info .file-size {
            font-size: 12px;
            color: #888;
        }
        .file-info .remove-file {
            background: none;
            border: none;
            font-size: 20px;
            color: #ef4444;
            cursor: pointer;
            padding: 4px 8px;
            border-radius: 6px;
            transition: background 0.2s;
        }
        .file-info .remove-file:hover {
            background: #fee2e2;
        }
        
        /* ===== PROGRESS ===== */
        .progress-container {
            display: none;
            margin-top: 16px;
        }
        .progress-container.show {
            display: block;
        }
        .progress-bar {
            width: 100%;
            height: 8px;
            background: #e5e7eb;
            border-radius: 999px;
            overflow: hidden;
            position: relative;
        }
        .progress-bar .fill {
            height: 100%;
            background: linear-gradient(90deg, #4f46e5, #7c3aed);
            border-radius: 999px;
            width: 0%;
            transition: width 0.3s ease;
        }
        .progress-label {
            display: flex;
            justify-content: space-between;
            font-size: 13px;
            color: #666;
            margin-top: 6px;
        }
        
        /* ===== BUTTONS ===== */
        .btn {
            display: inline-block;
            padding: 12px 28px;
            font-size: 16px;
            font-weight: 600;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            text-align: center;
        }
        .btn-primary {
            background: #4f46e5;
            color: #fff;
        }
        .btn-primary:hover {
            background: #4338ca;
            transform: translateY(-1px);
            box-shadow: 0 8px 25px rgba(79, 70, 229, 0.3);
        }
        .btn-primary:disabled {
            background: #a5b4fc;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        .btn-success {
            background: #10b981;
            color: #fff;
        }
        .btn-success:hover {
            background: #059669;
            transform: translateY(-1px);
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.3);
        }
        .btn-outline {
            background: transparent;
            color: #4f46e5;
            border: 2px solid #4f46e5;
        }
        .btn-outline:hover {
            background: #f0f1ff;
        }
        .btn-block {
            display: block;
            width: 100%;
            margin-top: 16px;
        }
        
        /* ===== STATUS ===== */
        .status {
            margin-top: 16px;
            padding: 12px 16px;
            border-radius: 12px;
            display: none;
            font-size: 14px;
        }
        .status.show {
            display: block;
        }
        .status.error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        .status.success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        
        /* ===== SUCCESS SCREEN ===== */
        .success-screen {
            display: none;
            text-align: center;
            padding: 20px 0;
        }
        .success-screen.show {
            display: block;
        }
        .success-screen .checkmark {
            font-size: 64px;
            color: #10b981;
            margin-bottom: 12px;
        }
        .success-screen h2 {
            font-size: 24px;
            color: #1a1a2e;
            margin-bottom: 4px;
        }
        .success-screen p {
            color: #666;
            font-size: 14px;
        }
        
        /* ===== FOOTER ===== */
        .footer {
            text-align: center;
            margin-top: 24px;
            font-size: 12px;
            color: #aaa;
        }
        .footer a {
            color: #4f46e5;
            text-decoration: none;
        }
        
        /* ===== RESPONSIVE ===== */
        @media (max-width: 640px) {
            .container {
                padding: 24px;
            }
            .header h1 {
                font-size: 22px;
            }
            .drop-zone {
                padding: 30px 16px;
            }
            .drop-zone .icon {
                font-size: 36px;
            }
            .btn {
                padding: 10px 20px;
                font-size: 14px;
            }
        }
        
        /* ===== ANIMATIONS ===== */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        .fade-in {
            animation: fadeInUp 0.4s ease forwards;
        }
        
        /* ===== SPINNER ===== */
        .spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 0.8s linear infinite;
            vertical-align: middle;
            margin-right: 8px;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>📄 PDF → DOCX</h1>
            <p>Convert your PDFs to editable Word documents</p>
        </div>
        
        <!-- Upload Form -->
        <form id="uploadForm" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            
            <!-- Drop Zone -->
            <div class="drop-zone" id="dropZone">
                <span class="icon">📤</span>
                <div class="title">Drag &amp; drop your PDF here</div>
                <div class="subtitle">or click to browse</div>
                <div class="file-types">Supports PDF files up to 100 MB</div>
                <input type="file" name="pdf" id="fileInput" accept=".pdf,application/pdf">
            </div>
            
            <!-- File Info -->
            <div class="file-info" id="fileInfo">
                <span class="file-icon">📄</span>
                <div class="file-details">
                    <div class="file-name" id="fileName">document.pdf</div>
                    <div class="file-size" id="fileSize">12.5 MB</div>
                </div>
                <button type="button" class="remove-file" id="removeFile" aria-label="Remove file">✕</button>
            </div>
            
            <!-- Progress -->
            <div class="progress-container" id="progressContainer">
                <div class="progress-bar">
                    <div class="fill" id="progressFill"></div>
                </div>
                <div class="progress-label">
                    <span id="progressText">Processing...</span>
                    <span id="progressPercent">0%</span>
                </div>
            </div>
            
            <!-- Status Messages -->
            <div class="status" id="status"></div>
            
            <!-- Convert Button -->
            <button type="submit" class="btn btn-primary btn-block" id="convertBtn" disabled>
                Convert to DOCX
            </button>
            
            <!-- Success Screen -->
            <div class="success-screen" id="successScreen">
                <div class="checkmark">✅</div>
                <h2>Conversion Complete!</h2>
                <p>Your document is ready for download.</p>
                <a href="#" class="btn btn-success btn-block" id="downloadBtn">
                    ⬇️ Download DOCX
                </a>
                <button type="button" class="btn btn-outline btn-block" id="convertAnotherBtn">
                    Convert Another PDF
                </button>
            </div>
        </form>
        
        <!-- Footer -->
        <div class="footer">
            Secure &bull; Private &bull; No storage &bull; <a href="#" onclick="location.reload();">New Conversion</a>
        </div>
    </div>
    
    <script>
    (function() {
        'use strict';
        
        // ===== DOM References =====
        const dropZone = document.getElementById('dropZone');
        const fileInput = document.getElementById('fileInput');
        const fileInfo = document.getElementById('fileInfo');
        const fileName = document.getElementById('fileName');
        const fileSize = document.getElementById('fileSize');
        const removeFileBtn = document.getElementById('removeFile');
        const convertBtn = document.getElementById('convertBtn');
        const status = document.getElementById('status');
        const progressContainer = document.getElementById('progressContainer');
        const progressFill = document.getElementById('progressFill');
        const progressText = document.getElementById('progressText');
        const progressPercent = document.getElementById('progressPercent');
        const successScreen = document.getElementById('successScreen');
        const downloadBtn = document.getElementById('downloadBtn');
        const convertAnotherBtn = document.getElementById('convertAnotherBtn');
        const uploadForm = document.getElementById('uploadForm');
        
        let selectedFile = null;
        let isProcessing = false;
        
        // ===== Utility Functions =====
        function formatFileSize(bytes) {
            if (bytes === 0) return '0 B';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            const value = bytes / Math.pow(k, i);
            return value.toFixed(i > 0 ? 1 : 0) + ' ' + sizes[i];
        }
        
        function showStatus(message, type) {
            status.textContent = message;
            status.className = 'status show ' + type;
        }
        
        function hideStatus() {
            status.className = 'status';
            status.textContent = '';
        }
        
        function resetUI() {
            hideStatus();
            progressContainer.classList.remove('show');
            successScreen.classList.remove('show');
            fileInfo.classList.remove('show');
            convertBtn.disabled = true;
            selectedFile = null;
            fileInput.value = '';
            progressFill.style.width = '0%';
            progressPercent.textContent = '0%';
        }
        
        function updateProgress(percent, text) {
            progressFill.style.width = percent + '%';
            progressPercent.textContent = percent + '%';
            if (text) {
                progressText.textContent = text;
            }
        }
        
        // ===== File Handling =====
        function handleFile(file) {
            // Validate file type
            if (file.type !== 'application/pdf') {
                showStatus('Please select a PDF file.', 'error');
                return false;
            }
            
            // Validate file size
            const maxSize = <?php echo MAX_FILE_SIZE; ?>;
            if (file.size > maxSize) {
                showStatus('File size exceeds the 100 MB limit.', 'error');
                return false;
            }
            
            if (file.size === 0) {
                showStatus('The file appears to be empty.', 'error');
                return false;
            }
            
            // Store file
            selectedFile = file;
            
            // Update UI
            fileName.textContent = file.name;
            fileSize.textContent = formatFileSize(file.size);
            fileInfo.classList.add('show');
            convertBtn.disabled = false;
            hideStatus();
            
            return true;
        }
        
        // ===== Event Listeners =====
        
        // Click drop zone to open file picker
        dropZone.addEventListener('click', function(e) {
            if (e.target.tagName !== 'BUTTON' && !isProcessing) {
                fileInput.click();
            }
        });
        
        // File input change
        fileInput.addEventListener('change', function(e) {
            if (this.files && this.files.length > 0) {
                handleFile(this.files[0]);
            }
        });
        
        // Drag and drop
        dropZone.addEventListener('dragover', function(e) {
            e.preventDefault();
            this.classList.add('dragover');
        });
        
        dropZone.addEventListener('dragleave', function(e) {
            e.preventDefault();
            this.classList.remove('dragover');
        });
        
        dropZone.addEventListener('drop', function(e) {
            e.preventDefault();
            this.classList.remove('dragover');
            if (isProcessing) return;
            
            const files = e.dataTransfer.files;
            if (files && files.length > 0) {
                handleFile(files[0]);
                // Update the file input for the form
                const dt = new DataTransfer();
                dt.items.add(files[0]);
                fileInput.files = dt.files;
            }
        });
        
        // Remove file
        removeFileBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            if (isProcessing) return;
            resetUI();
        });
        
        // Convert Another
        convertAnotherBtn.addEventListener('click', function() {
            resetUI();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
        
        // ===== Form Submit =====
        uploadForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            if (isProcessing) return;
            if (!selectedFile) {
                showStatus('Please select a PDF file first.', 'error');
                return;
            }
            
            // Start processing
            isProcessing = true;
            convertBtn.disabled = true;
            convertBtn.innerHTML = '<span class="spinner"></span> Converting...';
            hideStatus();
            successScreen.classList.remove('show');
            progressContainer.classList.add('show');
            updateProgress(0, 'Starting conversion...');
            
            const formData = new FormData();
            formData.append('pdf', selectedFile);
            formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
            
            try {
                const xhr = new XMLHttpRequest();
                xhr.open('POST', window.location.href, true);
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                
                // Track upload progress
                xhr.upload.addEventListener('progress', function(e) {
                    if (e.lengthComputable) {
                        const percent = Math.round((e.loaded / e.total) * 100);
                        updateProgress(percent, 'Uploading... ' + percent + '%');
                    }
                });
                
                // Track download progress (for response)
                xhr.addEventListener('progress', function(e) {
                    if (e.lengthComputable && e.total > 0) {
                        // Response download progress
                    }
                });
                
                xhr.onload = function() {
                    isProcessing = false;
                    convertBtn.innerHTML = 'Convert to DOCX';
                    convertBtn.disabled = false;
                    
                    if (xhr.status === 200) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.success) {
                                updateProgress(100, 'Done!');
                                // Show success screen
                                successScreen.classList.add('show');
                                downloadBtn.href = response.download;
                                downloadBtn.download = response.filename;
                                progressContainer.classList.remove('show');
                                fileInfo.classList.remove('show');
                            } else {
                                showStatus(response.error || 'Conversion failed.', 'error');
                                progressContainer.classList.remove('show');
                            }
                        } catch (parseError) {
                            showStatus('Invalid server response.', 'error');
                            progressContainer.classList.remove('show');
                        }
                    } else if (xhr.status === 400) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            showStatus(response.error || 'Bad request.', 'error');
                        } catch (parseError) {
                            showStatus('Bad request. Please try again.', 'error');
                        }
                        progressContainer.classList.remove('show');
                    } else {
                        showStatus('Server error. Please try again later.', 'error');
                        progressContainer.classList.remove('show');
                    }
                };
                
                xhr.onerror = function() {
                    isProcessing = false;
                    convertBtn.innerHTML = 'Convert to DOCX';
                    convertBtn.disabled = false;
                    showStatus('Network error. Please check your connection.', 'error');
                    progressContainer.classList.remove('show');
                };
                
                // Set a timeout for the request
                xhr.timeout = <?php echo MAX_EXECUTION_TIME * 1000; ?>;
                xhr.ontimeout = function() {
                    isProcessing = false;
                    convertBtn.innerHTML = 'Convert to DOCX';
                    convertBtn.disabled = false;
                    showStatus('Conversion timed out. The file may be too complex.', 'error');
                    progressContainer.classList.remove('show');
                };
                
                // Send the request
                xhr.send(formData);
                
            } catch (error) {
                isProcessing = false;
                convertBtn.innerHTML = 'Convert to DOCX';
                convertBtn.disabled = false;
                showStatus('An error occurred. Please try again.', 'error');
                progressContainer.classList.remove('show');
                console.error(error);
            }
        });
        
        // ===== Keyboard Shortcuts =====
        document.addEventListener('keydown', function(e) {
            // Escape key to cancel/remove file
            if (e.key === 'Escape' && !isProcessing && selectedFile) {
                resetUI();
            }
        });
        
        // ===== Initial State =====
        resetUI();
        
        // Handle drag and drop from outside the browser
        document.addEventListener('dragover', function(e) {
            e.preventDefault();
        });
        document.addEventListener('drop', function(e) {
            e.preventDefault();
        });
        
        // ===== Handle download button click (cleanup) =====
        downloadBtn.addEventListener('click', function() {
            // The server will delete the file after download
            // This is handled in the download.php endpoint
        });
        
    })();
    </script>
</body>
</html>
    <?php
}

// ============================================================================
// Router
// ============================================================================

// Set execution time limit
set_time_limit(MAX_EXECUTION_TIME);

// Initialize directories on startup
initializeDirectories();

// Route requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['pdf'])) {
    // API request
    handleAPI();
} elseif (isset($_GET['download']) && isset($_GET['file']) && isset($_GET['token'])) {
    // Download request
    handleDownload();
} else {
    // Render the UI
    renderHTML();
}
