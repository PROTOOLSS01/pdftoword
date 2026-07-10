<?php
/**
 * PDF to Word Converter - Production Ready
 * PHP 8.2+ Compatible
 * 
 * Conversion Strategies (in order):
 * 1. LibreOffice (best for formatted PDFs)
 * 2. PDF Parser (for text-based PDFs)
 * 3. OCR with Tesseract (for scanned PDFs)
 */

// ============================================================
// BOOTSTRAP & ERROR HANDLING
// ============================================================

// Enable error reporting for development (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

// Set maximum execution time for large files
set_time_limit(300); // 5 minutes
ini_set('memory_limit', '512M');

// ============================================================
// DEPENDENCY CHECK
// ============================================================

$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    die('<h2>Configuration Error</h2><p>Composer dependencies not found. Please run: <code>composer install</code></p>');
}
require_once $autoloadPath;

// Verify required classes exist
$requiredClasses = [
    'PhpOffice\PhpWord\IOFactory',
    'PhpOffice\PhpWord\Settings',
    'Smalot\PdfParser\Parser'
];

foreach ($requiredClasses as $class) {
    if (!class_exists($class)) {
        die('<h2>Dependency Error</h2><p>Required class not found: ' . htmlspecialchars($class) . 
            '. Please run: <code>composer update</code></p>');
    }
}

// ============================================================
// SESSION & CONFIGURATION
// ============================================================

session_start();

// Configuration
$maxFileSize = 100 * 1024 * 1024; // 100MB
$uploadDir = __DIR__ . '/uploads/';
$outputDir = __DIR__ . '/outputs/';
$tempDir = __DIR__ . '/temp/';
$logFile = __DIR__ . '/conversion.log';

// ============================================================
// DIRECTORY SETUP & PERMISSION CHECK
// ============================================================

function ensureDirectory($dir) {
    if (!file_exists($dir)) {
        if (!mkdir($dir, 0755, true)) {
            throw new RuntimeException("Failed to create directory: $dir");
        }
    }
    if (!is_writable($dir)) {
        throw new RuntimeException("Directory is not writable: $dir");
    }
    return true;
}

try {
    ensureDirectory($uploadDir);
    ensureDirectory($outputDir);
    ensureDirectory($tempDir);
} catch (RuntimeException $e) {
    die('<h2>Permission Error</h2><p>' . htmlspecialchars($e->getMessage()) . '</p>');
}

// ============================================================
// LOGGING FUNCTION
// ============================================================

function logMessage($message, $level = 'INFO') {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] [$level] $message" . PHP_EOL;
    @file_put_contents($logFile, $logEntry, FILE_APPEND);
}

// ============================================================
// CLEANUP OLD FILES
// ============================================================

function cleanupOldFiles($dir, $maxAge = 3600) {
    if (!file_exists($dir)) return;
    $files = glob($dir . '*');
    $now = time();
    foreach ($files as $file) {
        if (is_file($file) && ($now - filemtime($file) > $maxAge)) {
            @unlink($file);
        }
    }
}

cleanupOldFiles($uploadDir);
cleanupOldFiles($outputDir);
cleanupOldFiles($tempDir);

// ============================================================
// TOOL PATH DETECTION
// ============================================================

function findExecutable($commands) {
    if (!function_exists('exec')) {
        return false;
    }
    
    foreach ((array)$commands as $cmd) {
        // Check if it's a full path or just a command
        if (file_exists($cmd) && is_executable($cmd)) {
            return $cmd;
        }
        
        // Try which command to find path
        $output = [];
        $returnCode = 0;
        exec("which $cmd 2>/dev/null", $output, $returnCode);
        if ($returnCode === 0 && !empty($output[0]) && file_exists($output[0]) && is_executable($output[0])) {
            return $output[0];
        }
    }
    return false;
}

// Detect tools
$libreofficePath = findExecutable(['libreoffice', 'soffice', '/usr/bin/libreoffice', '/usr/bin/soffice']);
$ghostscriptPath = findExecutable(['gs', '/usr/bin/gs', '/usr/local/bin/gs']);
$tesseractPath = findExecutable(['tesseract', '/usr/bin/tesseract', '/usr/local/bin/tesseract']);

// Log tool availability
logMessage("Tool detection: LibreOffice=" . ($libreofficePath ?: 'not found') . 
           ", Ghostscript=" . ($ghostscriptPath ?: 'not found') . 
           ", Tesseract=" . ($tesseractPath ?: 'not found'));

// ============================================================
// PDF CONVERSION FUNCTIONS
// ============================================================

/**
 * Strategy 1: Convert using LibreOffice
 */
function convertWithLibreOffice($pdfPath, $wordPath, $libreofficePath) {
    if (!$libreofficePath) {
        return ['success' => false, 'error' => 'LibreOffice not available'];
    }
    
    if (!function_exists('exec')) {
        return ['success' => false, 'error' => 'exec() function is disabled'];
    }
    
    try {
        $outputDir = dirname($wordPath);
        $baseName = pathinfo($pdfPath, PATHINFO_FILENAME);
        $expectedOutput = $outputDir . '/' . $baseName . '.docx';
        
        // Ensure output directory exists
        if (!file_exists($outputDir)) {
            mkdir($outputDir, 0755, true);
        }
        
        // Build command with proper escaping
        $command = escapeshellcmd($libreofficePath) . 
                   ' --headless --convert-to docx --outdir ' . escapeshellarg($outputDir) . 
                   ' ' . escapeshellarg($pdfPath) . ' 2>&1';
        
        logMessage("Running LibreOffice command: $command");
        
        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);
        
        if ($returnCode === 0 && file_exists($expectedOutput) && filesize($expectedOutput) > 0) {
            // Rename to desired filename if different
            if ($expectedOutput !== $wordPath) {
                if (!rename($expectedOutput, $wordPath)) {
                    return ['success' => false, 'error' => 'Failed to rename output file'];
                }
            }
            return ['success' => true, 'file' => $wordPath];
        }
        
        // Clean up if file exists but is empty
        if (file_exists($expectedOutput)) {
            @unlink($expectedOutput);
        }
        
        return ['success' => false, 'error' => 'LibreOffice conversion failed with code: ' . $returnCode];
    } catch (Exception $e) {
        return ['success' => false, 'error' => 'LibreOffice error: ' . $e->getMessage()];
    }
}

/**
 * Strategy 2: Extract text using PDF Parser
 */
function convertWithPdfParser($pdfPath, $wordPath) {
    try {
        if (!class_exists('Smalot\PdfParser\Parser')) {
            return ['success' => false, 'error' => 'PDF Parser library not available'];
        }
        
        $parser = new Smalot\PdfParser\Parser();
        $pdf = $parser->parseFile($pdfPath);
        $text = $pdf->getText();
        
        if (empty(trim($text))) {
            return ['success' => false, 'error' => 'No text extracted from PDF'];
        }
        
        // Create Word document
        $phpWord = new PhpOffice\PhpWord\PhpWord();
        $phpWord->setDefaultFontName('Arial');
        $phpWord->setDefaultFontSize(12);
        
        $section = $phpWord->addSection();
        
        // Split into paragraphs and add
        $paragraphs = preg_split('/\r\n|\r|\n/', $text);
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if (!empty($paragraph)) {
                $section->addText($paragraph, ['size' => 12, 'name' => 'Arial']);
            }
        }
        
        $writer = PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($wordPath);
        
        if (file_exists($wordPath) && filesize($wordPath) > 0) {
            return ['success' => true, 'file' => $wordPath];
        }
        
        return ['success' => false, 'error' => 'Failed to save Word document'];
    } catch (Exception $e) {
        return ['success' => false, 'error' => 'PDF Parser error: ' . $e->getMessage()];
    }
}

/**
 * Strategy 3: OCR using Tesseract (for scanned PDFs)
 */
function convertWithOCR($pdfPath, $wordPath, $ghostscriptPath, $tesseractPath) {
    if (!$ghostscriptPath || !$tesseractPath) {
        return ['success' => false, 'error' => 'Ghostscript or Tesseract not available'];
    }
    
    if (!function_exists('exec')) {
        return ['success' => false, 'error' => 'exec() function is disabled'];
    }
    
    $tempDir = dirname($pdfPath) . '/temp_ocr/';
    if (!file_exists($tempDir)) {
        mkdir($tempDir, 0755, true);
    }
    
    $imagePrefix = $tempDir . 'page_' . uniqid();
    $textContent = '';
    
    try {
        // Get total number of pages
        $pageCount = 0;
        $infoCmd = escapeshellcmd($ghostscriptPath) . 
                   ' -dNODISPLAY -dBATCH -dNOPAUSE -sFile=' . escapeshellarg($pdfPath) . 
                   ' -c "(r) file runpdfbegin pdfpagecount = quit" 2>&1';
        exec($infoCmd, $infoOutput, $infoCode);
        
        if ($infoCode === 0 && !empty($infoOutput)) {
            $pageCount = intval($infoOutput[0]);
        }
        
        if ($pageCount <= 0) {
            $pageCount = 1; // Default to 1 page if count failed
        }
        
        logMessage("OCR: Processing $pageCount pages");
        
        // Convert each page to image and OCR
        for ($page = 1; $page <= $pageCount; $page++) {
            $imagePath = $imagePrefix . $page . '.png';
            
            // Convert page to PNG with improved quality
            $gsCmd = escapeshellcmd($ghostscriptPath) . 
                    ' -dNOPAUSE -dBATCH -sDEVICE=png16m -r300 -dFirstPage=' . $page . 
                    ' -dLastPage=' . $page . 
                    ' -sOutputFile=' . escapeshellarg($imagePath) . 
                    ' ' . escapeshellarg($pdfPath) . ' 2>&1';
            
            exec($gsCmd, $gsOutput, $gsReturn);
            
            if ($gsReturn !== 0 || !file_exists($imagePath) || filesize($imagePath) === 0) {
                logMessage("OCR: Failed to convert page $page to image", 'WARNING');
                continue;
            }
            
            // Perform OCR with optimized settings
            $ocrCmd = escapeshellcmd($tesseractPath) . 
                     ' ' . escapeshellarg($imagePath) . 
                     ' stdout --psm 3 --oem 3 -l eng 2>&1';
            
            $ocrOutput = [];
            $ocrReturn = 0;
            exec($ocrCmd, $ocrOutput, $ocrReturn);
            
            if ($ocrReturn === 0 && !empty($ocrOutput)) {
                $textContent .= implode("\n", $ocrOutput) . "\n\n";
            }
            
            // Clean up image
            @unlink($imagePath);
        }
        
        // Clean up temp directory
        @rmdir($tempDir);
        
        if (empty(trim($textContent))) {
            return ['success' => false, 'error' => 'OCR failed to extract any text'];
        }
        
        // Create Word document from OCR text
        $phpWord = new PhpOffice\PhpWord\PhpWord();
        $phpWord->setDefaultFontName('Arial');
        $phpWord->setDefaultFontSize(12);
        
        $section = $phpWord->addSection();
        
        $paragraphs = preg_split('/\r\n|\r|\n/', $textContent);
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if (!empty($paragraph)) {
                $section->addText($paragraph, ['size' => 12, 'name' => 'Arial']);
            }
        }
        
        $writer = PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($wordPath);
        
        if (file_exists($wordPath) && filesize($wordPath) > 0) {
            return ['success' => true, 'file' => $wordPath];
        }
        
        return ['success' => false, 'error' => 'Failed to save OCR result'];
    } catch (Exception $e) {
        // Clean up temp files
        $tempFiles = glob($tempDir . '*');
        foreach ($tempFiles as $file) {
            @unlink($file);
        }
        @rmdir($tempDir);
        
        return ['success' => false, 'error' => 'OCR error: ' . $e->getMessage()];
    }
}

/**
 * Main conversion function with fallback strategies
 */
function convertPdfToWord($pdfPath, $wordPath) {
    global $libreofficePath, $ghostscriptPath, $tesseractPath;
    
    logMessage("Starting conversion: " . basename($pdfPath));
    
    // Strategy 1: LibreOffice (best for formatted PDFs)
    $result = convertWithLibreOffice($pdfPath, $wordPath, $libreofficePath);
    if ($result['success']) {
        logMessage("Conversion successful using LibreOffice");
        return $result;
    }
    logMessage("LibreOffice failed: " . ($result['error'] ?? 'Unknown error'));
    
    // Strategy 2: PDF Parser (for text-based PDFs)
    $result = convertWithPdfParser($pdfPath, $wordPath);
    if ($result['success']) {
        logMessage("Conversion successful using PDF Parser");
        return $result;
    }
    logMessage("PDF Parser failed: " . ($result['error'] ?? 'Unknown error'));
    
    // Strategy 3: OCR (for scanned PDFs)
    $result = convertWithOCR($pdfPath, $wordPath, $ghostscriptPath, $tesseractPath);
    if ($result['success']) {
        logMessage("Conversion successful using OCR");
        return $result;
    }
    logMessage("OCR failed: " . ($result['error'] ?? 'Unknown error'));
    
    // All strategies failed
    $errors = [];
    if (!$libreofficePath) $errors[] = 'LibreOffice not installed';
    if (!$ghostscriptPath) $errors[] = 'Ghostscript not installed';
    if (!$tesseractPath) $errors[] = 'Tesseract not installed';
    if (!function_exists('exec')) $errors[] = 'exec() function is disabled';
    
    if (empty($errors)) {
        $errors[] = 'All conversion methods failed';
    }
    
    return ['success' => false, 'error' => implode('. ', $errors)];
}

// ============================================================
// FILE UPLOAD HANDLING
// ============================================================

$error = '';
$success = '';
$downloadUrl = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['pdf_file'])) {
    $file = $_FILES['pdf_file'];
    
    // Validate file upload
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = 'Upload failed with error code: ' . $file['error'];
    } elseif ($file['size'] > $maxFileSize) {
        $error = 'File size exceeds 100MB limit';
    } elseif ($file['size'] === 0) {
        $error = 'Uploaded file is empty';
    } else {
        // Validate MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        $allowedMimeTypes = ['application/pdf', 'application/x-pdf', 'application/force-download'];
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($mimeType, $allowedMimeTypes) && $extension !== 'pdf') {
            $error = 'Invalid file type. Only PDF files are allowed.';
        } else {
            // Generate secure filename
            $originalName = pathinfo($file['name'], PATHINFO_FILENAME);
            $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $originalName);
            $uniqueId = uniqid() . '_' . bin2hex(random_bytes(8));
            $uploadPath = $uploadDir . $uniqueId . '.pdf';
            
            // Move uploaded file
            if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
                try {
                    // Generate output filename
                    $outputFilename = $uniqueId . '.docx';
                    $outputPath = $outputDir . $outputFilename;
                    
                    // Perform conversion
                    $result = convertPdfToWord($uploadPath, $outputPath);
                    
                    if ($result['success']) {
                        $success = 'Conversion completed successfully!';
                        $downloadUrl = '/outputs/' . $outputFilename;
                        logMessage("Conversion successful: " . basename($outputPath));
                    } else {
                        $error = $result['error'];
                        logMessage("Conversion failed: " . $error, 'ERROR');
                    }
                    
                    // Clean up uploaded file
                    @unlink($uploadPath);
                    
                } catch (Exception $e) {
                    $error = 'Conversion error: ' . $e->getMessage();
                    logMessage("Exception: " . $e->getMessage(), 'ERROR');
                    @unlink($uploadPath);
                }
            } else {
                $error = 'Failed to move uploaded file';
                logMessage("Failed to move uploaded file", 'ERROR');
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PDF to Word Converter</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            padding: 40px;
            max-width: 600px;
            width: 100%;
        }
        
        h1 {
            font-size: 28px;
            color: #333;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        h1 .icon {
            font-size: 32px;
        }
        
        .subtitle {
            color: #666;
            margin-bottom: 30px;
            font-size: 14px;
        }
        
        .drop-zone {
            border: 2px dashed #cbd5e1;
            border-radius: 12px;
            padding: 40px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: #f8fafc;
        }
        
        .drop-zone:hover {
            border-color: #667eea;
            background: #f1f5f9;
        }
        
        .drop-zone.dragover {
            border-color: #667eea;
            background: #eef2ff;
        }
        
        .drop-zone .icon-large {
            font-size: 48px;
            margin-bottom: 12px;
        }
        
        .drop-zone p {
            color: #64748b;
            margin-bottom: 8px;
        }
        
        .drop-zone .browse-link {
            color: #667eea;
            font-weight: 600;
            cursor: pointer;
        }
        
        .drop-zone .file-types {
            font-size: 12px;
            color: #94a3b8;
            margin-top: 8px;
        }
        
        #file-input {
            display: none;
        }
        
        .file-info {
            display: none;
            align-items: center;
            gap: 12px;
            background: #f1f5f9;
            padding: 12px 16px;
            border-radius: 8px;
            margin-top: 16px;
        }
        
        .file-info .file-name {
            flex: 1;
            color: #333;
            font-size: 14px;
        }
        
        .file-info .file-size {
            color: #64748b;
            font-size: 12px;
        }
        
        .progress-container {
            display: none;
            margin-top: 20px;
        }
        
        .progress-bar {
            width: 100%;
            height: 6px;
            background: #e2e8f0;
            border-radius: 3px;
            overflow: hidden;
            position: relative;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #667eea, #764ba2);
            border-radius: 3px;
            width: 0%;
            transition: width 0.5s ease;
        }
        
        .progress-text {
            text-align: center;
            color: #64748b;
            font-size: 14px;
            margin-top: 8px;
        }
        
        .loading-spinner {
            display: none;
            text-align: center;
            margin-top: 20px;
        }
        
        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #e2e8f0;
            border-top-color: #667eea;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin: 0 auto;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .btn {
            display: none;
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            margin-top: 20px;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.4);
        }
        
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        
        .btn.show {
            display: block;
        }
        
        .alert {
            padding: 14px 16px;
            border-radius: 8px;
            margin-top: 16px;
            font-size: 14px;
            display: none;
        }
        
        .alert.error {
            background: #fee2e2;
            color: #991b1b;
            display: block;
        }
        
        .alert.success {
            background: #d1fae5;
            color: #065f46;
            display: block;
        }
        
        .alert .close-btn {
            float: right;
            cursor: pointer;
            font-weight: 600;
        }
        
        .download-section {
            display: none;
            margin-top: 16px;
        }
        
        .download-section .btn-download {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #10b981;
            color: white;
            padding: 12px 24px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: background 0.2s ease;
            width: 100%;
            justify-content: center;
        }
        
        .download-section .btn-download:hover {
            background: #059669;
        }
        
        .features {
            margin-top: 30px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        
        .feature-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #64748b;
            font-size: 13px;
        }
        
        .feature-item .check {
            color: #10b981;
            font-weight: 700;
        }
        
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
            text-align: center;
            color: #94a3b8;
            font-size: 12px;
        }
        
        @media (max-width: 640px) {
            .container {
                padding: 24px;
            }
            
            h1 {
                font-size: 22px;
            }
            
            .drop-zone {
                padding: 30px 16px;
            }
            
            .features {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>
            <span class="icon">📄</span>
            PDF to Word Converter
        </h1>
        <p class="subtitle">Convert your PDF files to editable Word documents</p>
        
        <form id="upload-form" method="POST" enctype="multipart/form-data">
            <div class="drop-zone" id="drop-zone">
                <div class="icon-large">📤</div>
                <p>Drag & drop your PDF here</p>
                <p>or <span class="browse-link" id="browse-link">browse files</span></p>
                <div class="file-types">Supported: PDF (Max 100MB)</div>
            </div>
            
            <input type="file" id="file-input" name="pdf_file" accept=".pdf,application/pdf">
            
            <div class="file-info" id="file-info">
                <span>📎</span>
                <span class="file-name" id="file-name">file.pdf</span>
                <span class="file-size" id="file-size">0 MB</span>
            </div>
            
            <div class="progress-container" id="progress-container">
                <div class="progress-bar">
                    <div class="progress-fill" id="progress-fill"></div>
                </div>
                <div class="progress-text" id="progress-text">Processing...</div>
            </div>
            
            <div class="loading-spinner" id="loading-spinner">
                <div class="spinner"></div>
                <p style="margin-top: 12px; color: #64748b; font-size: 14px;">Converting your document...</p>
            </div>
            
            <button type="submit" class="btn" id="convert-btn">Convert to Word</button>
        </form>
        
        <div id="alert-container"></div>
        
        <div class="download-section" id="download-section">
            <a href="#" class="btn-download" id="download-btn">
                📥 Download Word Document
            </a>
        </div>
        
        <div class="features">
            <div class="feature-item">
                <span class="check">✓</span> PDF to DOCX
            </div>
            <div class="feature-item">
                <span class="check">✓</span> 100MB Max
            </div>
            <div class="feature-item">
                <span class="check">✓</span> Secure & Private
            </div>
            <div class="feature-item">
                <span class="check">✓</span> Auto Cleanup
            </div>
        </div>
        
        <div class="footer">
            Powered by PHP 8.2 • Files are automatically deleted after 1 hour
        </div>
    </div>
    
    <script>
        const dropZone = document.getElementById('drop-zone');
        const fileInput = document.getElementById('file-input');
        const fileInfo = document.getElementById('file-info');
        const fileName = document.getElementById('file-name');
        const fileSize = document.getElementById('file-size');
        const convertBtn = document.getElementById('convert-btn');
        const progressContainer = document.getElementById('progress-container');
        const progressFill = document.getElementById('progress-fill');
        const progressText = document.getElementById('progress-text');
        const loadingSpinner = document.getElementById('loading-spinner');
        const alertContainer = document.getElementById('alert-container');
        const downloadSection = document.getElementById('download-section');
        const downloadBtn = document.getElementById('download-btn');
        const uploadForm = document.getElementById('upload-form');
        const browseLink = document.getElementById('browse-link');
        
        let selectedFile = null;
        
        // Browse link click
        browseLink.addEventListener('click', (e) => {
            e.preventDefault();
            fileInput.click();
        });
        
        // Drop zone click
        dropZone.addEventListener('click', () => {
            fileInput.click();
        });
        
        // Drag and drop events
        dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropZone.classList.add('dragover');
        });
        
        dropZone.addEventListener('dragleave', () => {
            dropZone.classList.remove('dragover');
        });
        
        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('dragover');
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                handleFileSelect(files[0]);
            }
        });
        
        // File input change
        fileInput.addEventListener('change', (e) => {
            if (e.target.files.length > 0) {
                handleFileSelect(e.target.files[0]);
            }
        });
        
        function handleFileSelect(file) {
            // Validate file type
            const validTypes = ['application/pdf', 'application/x-pdf'];
            const extension = file.name.split('.').pop().toLowerCase();
            
            if (!validTypes.includes(file.type) && extension !== 'pdf') {
                showAlert('Please select a valid PDF file.', 'error');
                return;
            }
            
            if (file.size > 100 * 1024 * 1024) {
                showAlert('File size exceeds 100MB limit.', 'error');
                return;
            }
            
            selectedFile = file;
            fileName.textContent = file.name;
            fileSize.textContent = (file.size / (1024 * 1024)).toFixed(2) + ' MB';
            fileInfo.style.display = 'flex';
            convertBtn.classList.add('show');
            hideAlert();
            
            // Auto submit form
            uploadForm.submit();
            
            // Show progress
            convertBtn.style.display = 'none';
            progressContainer.style.display = 'block';
            progressFill.style.width = '0%';
            progressText.textContent = 'Uploading...';
            loadingSpinner.style.display = 'block';
            
            // Animate progress
            let progress = 0;
            const interval = setInterval(() => {
                progress += Math.random() * 10;
                if (progress > 100) {
                    progress = 100;
                    clearInterval(interval);
                    progressText.textContent = 'Converting...';
                    setTimeout(() => {
                        progressText.textContent = 'Finalizing...';
                    }, 500);
                }
                progressFill.style.width = Math.min(progress, 95) + '%';
            }, 300);
        }
        
        // Handle form submission response
        <?php if (!empty($error)): ?>
        showAlert('<?php echo addslashes($error); ?>', 'error');
        resetUI();
        <?php endif; ?>
        
        <?php if (!empty($success) && !empty($downloadUrl)): ?>
        setTimeout(() => {
            showAlert('<?php echo addslashes($success); ?>', 'success');
            progressFill.style.width = '100%';
            progressText.textContent = '✅ Conversion complete!';
            loadingSpinner.style.display = 'none';
            downloadSection.style.display = 'block';
            downloadBtn.href = '<?php echo $downloadUrl; ?>';
            setTimeout(resetUI, 5000);
        }, 1000);
        <?php endif; ?>
        
        function showAlert(message, type) {
            hideAlert();
            const alert = document.createElement('div');
            alert.className = `alert ${type}`;
            alert.innerHTML = `
                <span class="close-btn" onclick="this.parentElement.remove()">×</span>
                ${message}
            `;
            alertContainer.appendChild(alert);
        }
        
        function hideAlert() {
            alertContainer.innerHTML = '';
        }
        
        function resetUI() {
            setTimeout(() => {
                fileInfo.style.display = 'none';
                convertBtn.classList.remove('show');
                convertBtn.style.display = 'block';
                progressContainer.style.display = 'none';
                progressFill.style.width = '0%';
                loadingSpinner.style.display = 'none';
                downloadSection.style.display = 'none';
                fileInput.value = '';
                selectedFile = null;
            }, 3000);
        }
    </script>
</body>
</html>
