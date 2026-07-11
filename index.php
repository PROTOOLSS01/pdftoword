<?php
/**
 * PDF to Word Converter
 * 
 * A production-ready PDF to Word document converter with OCR support
 * Built with PHP 8.3, PHPWord, LibreOffice, Poppler, and Tesseract
 * 
 * @package PDF2Word
 * @author Your Name
 * @version 1.0.0
 */

// Error reporting for development (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/dev/stderr');

// Set time limit for large files
set_time_limit(300);

// Define base paths
define('BASE_PATH', __DIR__);
define('UPLOAD_PATH', BASE_PATH . '/uploads');
define('OUTPUT_PATH', BASE_PATH . '/output');
define('TEMP_PATH', BASE_PATH . '/temp');

// Create required directories
foreach ([UPLOAD_PATH, OUTPUT_PATH, TEMP_PATH] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

/**
 * Clean up temporary files
 * 
 * @param string $directory Directory to clean
 * @param int $maxAge Maximum age in seconds (default: 1 hour)
 */
function cleanupTempFiles($directory, $maxAge = 3600) {
    if (!is_dir($directory)) {
        return;
    }
    
    $files = scandir($directory);
    $now = time();
    
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        
        $filePath = $directory . '/' . $file;
        if (is_file($filePath) && ($now - filemtime($filePath)) > $maxAge) {
            @unlink($filePath);
        }
    }
}

// Clean up old temporary files
cleanupTempFiles(UPLOAD_PATH, 3600);
cleanupTempFiles(OUTPUT_PATH, 3600);
cleanupTempFiles(TEMP_PATH, 3600);

/**
 * Generate a unique filename
 * 
 * @param string $original Original filename
 * @return string Unique filename
 */
function generateUniqueFilename($original) {
    $extension = pathinfo($original, PATHINFO_EXTENSION);
    return uniqid() . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
}

/**
 * Check if PDF contains selectable text
 * 
 * @param string $pdfPath Path to PDF file
 * @return bool True if PDF has selectable text
 */
function hasSelectableText($pdfPath) {
    $tempFile = TEMP_PATH . '/text_' . uniqid() . '.txt';
    $command = "pdftotext -q '{$pdfPath}' '{$tempFile}' 2>/dev/null";
    exec($command, $output, $returnCode);
    
    $hasText = false;
    if (file_exists($tempFile)) {
        $content = file_get_contents($tempFile);
        $hasText = strlen(trim($content)) > 0;
        @unlink($tempFile);
    }
    
    return $hasText;
}

/**
 * Convert PDF with selectable text to DOCX
 * 
 * @param string $pdfPath Path to PDF file
 * @param string $outputPath Output path for DOCX
 * @return bool Success status
 */
function convertSelectablePDF($pdfPath, $outputPath) {
    // Use LibreOffice to convert PDF to DOCX
    $command = "libreoffice --headless --convert-to docx --outdir '" . dirname($outputPath) . "' '{$pdfPath}' 2>&1";
    exec($command, $output, $returnCode);
    
    // LibreOffice saves with original filename, so we need to rename
    $originalBasename = pathinfo($pdfPath, PATHINFO_FILENAME);
    $tempDocx = dirname($outputPath) . '/' . $originalBasename . '.docx';
    
    if (file_exists($tempDocx)) {
        rename($tempDocx, $outputPath);
        return true;
    }
    
    return false;
}

/**
 * Convert scanned PDF using OCR
 * 
 * @param string $pdfPath Path to PDF file
 * @param string $outputPath Output path for DOCX
 * @return bool Success status
 * @throws Exception On OCR failure
 */
function convertScannedPDF($pdfPath, $outputPath) {
    require_once 'vendor/autoload.php';
    use PhpOffice\PhpWord\PhpWord;
    use PhpOffice\PhpWord\IOFactory;
    
    $phpWord = new PhpWord();
    $section = $phpWord->addSection();
    
    // Convert PDF to images
    $imagePrefix = TEMP_PATH . '/page_' . uniqid();
    $command = "pdftoppm -jpeg -r 300 '{$pdfPath}' '{$imagePrefix}' 2>&1";
    exec($command, $output, $returnCode);
    
    if ($returnCode !== 0) {
        throw new Exception("Failed to convert PDF to images: " . implode("\n", $output));
    }
    
    // Process each page
    $pageNumber = 1;
    $imageFiles = glob($imagePrefix . '*.jpg');
    
    if (empty($imageFiles)) {
        throw new Exception("No images generated from PDF");
    }
    
    foreach ($imageFiles as $imagePath) {
        // OCR the image
        $textFile = TEMP_PATH . '/ocr_' . uniqid() . '.txt';
        $command = "tesseract '{$imagePath}' '{$textFile}' -l eng --oem 3 2>&1";
        exec($command, $output, $returnCode);
        
        if ($returnCode !== 0) {
            @unlink($imagePath);
            throw new Exception("OCR failed for page {$pageNumber}: " . implode("\n", $output));
        }
        
        // Read OCR text
        $textContent = '';
        $textFileFull = $textFile . '.txt';
        if (file_exists($textFileFull)) {
            $textContent = file_get_contents($textFileFull);
            @unlink($textFileFull);
        }
        
        // Add text to Word document
        if (!empty(trim($textContent))) {
            $section->addTitle("Page " . $pageNumber, 1);
            $section->addText($textContent);
            $section->addPageBreak();
        }
        
        @unlink($textFile);
        @unlink($imagePath);
        $pageNumber++;
    }
    
    // Save DOCX
    $objWriter = IOFactory::createWriter($phpWord, 'Word2007');
    $objWriter->save($outputPath);
    
    return true;
}

/**
 * Validate uploaded file
 * 
 * @param array $file $_FILES array
 * @return array [isValid, message]
 */
function validateFile($file) {
    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize directive',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload'
        ];
        return [false, $errors[$file['error']] ?? 'Unknown upload error'];
    }
    
    // Check file size (max 50MB)
    $maxSize = 50 * 1024 * 1024;
    if ($file['size'] > $maxSize) {
        return [false, 'File size exceeds 50MB limit'];
    }
    
    // Check MIME type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if ($mimeType !== 'application/pdf' && $mimeType !== 'application/x-pdf') {
        return [false, 'Only PDF files are allowed'];
    }
    
    return [true, 'OK'];
}

// Handle file upload
$response = ['success' => false, 'message' => '', 'download' => ''];
$uploadedFile = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['pdf_file'])) {
    try {
        $file = $_FILES['pdf_file'];
        
        // Validate file
        list($isValid, $message) = validateFile($file);
        if (!$isValid) {
            throw new Exception($message);
        }
        
        // Generate unique filename and move file
        $uniqueName = generateUniqueFilename($file['name']);
        $uploadPath = UPLOAD_PATH . '/' . $uniqueName;
        
        if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
            throw new Exception('Failed to move uploaded file');
        }
        
        // Determine if PDF has selectable text
        $hasText = hasSelectableText($uploadPath);
        
        // Generate output filename
        $outputName = pathinfo($file['name'], PATHINFO_FILENAME) . '.docx';
        $outputPath = OUTPUT_PATH . '/' . generateUniqueFilename($outputName);
        
        // Convert based on text availability
        if ($hasText) {
            $success = convertSelectablePDF($uploadPath, $outputPath);
            if (!$success) {
                throw new Exception('Failed to convert PDF to Word document');
            }
        } else {
            $success = convertScannedPDF($uploadPath, $outputPath);
            if (!$success) {
                throw new Exception('OCR conversion failed');
            }
        }
        
        // Clean up uploaded file
        @unlink($uploadPath);
        
        // Create download link
        $downloadUrl = '/download.php?file=' . basename($outputPath);
        
        $response = [
            'success' => true,
            'message' => 'PDF converted successfully!',
            'download' => $downloadUrl
        ];
        
    } catch (Exception $e) {
        $response = [
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ];
        
        // Clean up any leftover files
        if (isset($uploadPath) && file_exists($uploadPath)) {
            @unlink($uploadPath);
        }
    }
}

// Download handler
if (isset($_GET['download']) && isset($_GET['file'])) {
    $file = basename($_GET['file']);
    $filePath = OUTPUT_PATH . '/' . $file;
    
    if (file_exists($filePath)) {
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        
        // Clean up after download
        @unlink($filePath);
        exit;
    }
    
    http_response_code(404);
    die('File not found');
}

// Display the main interface
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Free PDF to Word Converter - Convert PDFs to editable Word documents with OCR support">
    <meta name="keywords" content="PDF to Word, Convert PDF, OCR, PDF Converter">
    <meta name="robots" content="index, follow">
    <title>PDF to Word Converter - Free Online Tool</title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📄</text></svg>">
    
    <style>
        /* CSS Variables for themes */
        :root {
            --bg-primary: #ffffff;
            --bg-secondary: #f8f9fa;
            --bg-card: #ffffff;
            --text-primary: #212529;
            --text-secondary: #6c757d;
            --border-color: #dee2e6;
            --accent-color: #0d6efd;
            --accent-hover: #0b5ed7;
            --success-color: #198754;
            --danger-color: #dc3545;
            --shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
            --radius: 1rem;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        [data-theme="dark"] {
            --bg-primary: #1a1a1a;
            --bg-secondary: #2d2d2d;
            --bg-card: #2d2d2d;
            --text-primary: #e9ecef;
            --text-secondary: #adb5bd;
            --border-color: #404040;
            --shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.5);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            transition: var(--transition);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        
        .container {
            max-width: 768px;
            width: 100%;
            background: var(--bg-card);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 2rem;
            transition: var(--transition);
        }
        
        /* Header */
        .header {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .header h1 {
            font-size: 2.25rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            background: linear-gradient(135deg, var(--accent-color), #6f42c1);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .header p {
            color: var(--text-secondary);
            font-size: 1rem;
        }
        
        /* Theme toggle */
        .theme-toggle {
            position: fixed;
            top: 1rem;
            right: 1rem;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 50%;
            width: 3rem;
            height: 3rem;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 1.5rem;
            transition: var(--transition);
            box-shadow: var(--shadow);
            z-index: 1000;
        }
        
        .theme-toggle:hover {
            transform: scale(1.1);
        }
        
        /* Drop zone */
        .drop-zone {
            border: 2px dashed var(--border-color);
            border-radius: var(--radius);
            padding: 3rem 1rem;
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
            background: var(--bg-secondary);
            position: relative;
        }
        
        .drop-zone:hover,
        .drop-zone.drag-over {
            border-color: var(--accent-color);
            background: var(--bg-primary);
        }
        
        .drop-zone .icon {
            font-size: 4rem;
            margin-bottom: 1rem;
        }
        
        .drop-zone h3 {
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
        }
        
        .drop-zone p {
            color: var(--text-secondary);
            font-size: 0.875rem;
        }
        
        .drop-zone input[type="file"] {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
        }
        
        /* File info */
        .file-info {
            display: none;
            margin-top: 1rem;
            padding: 1rem;
            background: var(--bg-secondary);
            border-radius: 0.5rem;
            align-items: center;
            gap: 1rem;
        }
        
        .file-info.show {
            display: flex;
        }
        
        .file-info .name {
            flex: 1;
            font-size: 0.875rem;
            word-break: break-all;
        }
        
        .file-info .size {
            color: var(--text-secondary);
            font-size: 0.75rem;
            white-space: nowrap;
        }
        
        /* Progress bar */
        .progress-container {
            display: none;
            margin-top: 1rem;
            height: 0.5rem;
            background: var(--bg-secondary);
            border-radius: 999px;
            overflow: hidden;
        }
        
        .progress-container.show {
            display: block;
        }
        
        .progress-bar {
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, var(--accent-color), #6f42c1);
            border-radius: 999px;
            transition: width 0.3s ease;
        }
        
        /* Status messages */
        .status {
            margin-top: 1rem;
            padding: 0.75rem 1rem;
            border-radius: 0.5rem;
            display: none;
            align-items: center;
            gap: 0.75rem;
        }
        
        .status.show {
            display: flex;
        }
        
        .status.success {
            background: #d1e7dd;
            color: #0a3622;
        }
        
        .status.error {
            background: #f8d7da;
            color: #58151c;
        }
        
        .status.loading {
            background: #cfe2ff;
            color: #052c65;
        }
        
        .status .spinner {
            width: 1.25rem;
            height: 1.25rem;
            border: 2px solid currentColor;
            border-top-color: transparent;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
            flex-shrink: 0;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Download button */
        .download-btn {
            display: none;
            margin-top: 1rem;
            width: 100%;
            padding: 0.75rem;
            background: var(--success-color);
            color: white;
            border: none;
            border-radius: 0.5rem;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        
        .download-btn.show {
            display: flex;
        }
        
        .download-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 0.25rem 0.5rem rgba(25, 135, 84, 0.3);
        }
        
        /* Responsive */
        @media (max-width: 640px) {
            .container {
                padding: 1rem;
            }
            
            .header h1 {
                font-size: 1.75rem;
            }
            
            .drop-zone {
                padding: 2rem 1rem;
            }
            
            .drop-zone .icon {
                font-size: 3rem;
            }
            
            .theme-toggle {
                width: 2.5rem;
                height: 2.5rem;
                font-size: 1.25rem;
                top: 0.75rem;
                right: 0.75rem;
            }
        }
    </style>
</head>
<body>
    <!-- Theme toggle -->
    <button class="theme-toggle" id="themeToggle" aria-label="Toggle theme">
        🌙
    </button>
    
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>📄 PDF to Word Converter</h1>
            <p>Upload a PDF file and convert it to an editable Word document</p>
        </div>
        
        <!-- Drop zone -->
        <form id="uploadForm" enctype="multipart/form-data" method="post">
            <div class="drop-zone" id="dropZone">
                <div class="icon">📤</div>
                <h3>Drag &amp; drop your PDF here</h3>
                <p>or click to browse files (Max 50MB)</p>
                <input type="file" name="pdf_file" id="fileInput" accept=".pdf,application/pdf" required>
            </div>
            
            <!-- File info -->
            <div class="file-info" id="fileInfo">
                <span class="name" id="fileName">file.pdf</span>
                <span class="size" id="fileSize">0 MB</span>
                <button type="button" id="removeFile" style="background:none;border:none;cursor:pointer;font-size:1.25rem;color:var(--text-secondary)">✕</button>
            </div>
            
            <!-- Progress -->
            <div class="progress-container" id="progressContainer">
                <div class="progress-bar" id="progressBar"></div>
            </div>
            
            <!-- Status -->
            <div class="status" id="status">
                <div class="spinner" id="spinner"></div>
                <span id="statusMessage">Processing...</span>
            </div>
            
            <!-- Submit button (hidden) -->
            <input type="submit" id="submitBtn" style="display:none">
        </form>
        
        <!-- Download button -->
        <button class="download-btn" id="downloadBtn">
            ⬇️ Download Word Document
        </button>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Elements
            const dropZone = document.getElementById('dropZone');
            const fileInput = document.getElementById('fileInput');
            const fileInfo = document.getElementById('fileInfo');
            const fileName = document.getElementById('fileName');
            const fileSize = document.getElementById('fileSize');
            const removeFile = document.getElementById('removeFile');
            const progressContainer = document.getElementById('progressContainer');
            const progressBar = document.getElementById('progressBar');
            const status = document.getElementById('status');
            const statusMessage = document.getElementById('statusMessage');
            const spinner = document.getElementById('spinner');
            const downloadBtn = document.getElementById('downloadBtn');
            const uploadForm = document.getElementById('uploadForm');
            const themeToggle = document.getElementById('themeToggle');
            
            let selectedFile = null;
            
            // Theme handling
            function getTheme() {
                return localStorage.getItem('theme') || 'light';
            }
            
            function setTheme(theme) {
                document.documentElement.setAttribute('data-theme', theme);
                localStorage.setItem('theme', theme);
                themeToggle.textContent = theme === 'dark' ? '☀️' : '🌙';
            }
            
            // Initialize theme
            setTheme(getTheme());
            
            themeToggle.addEventListener('click', function() {
                const current = getTheme();
                setTheme(current === 'dark' ? 'light' : 'dark');
            });
            
            // File handling
            function formatSize(bytes) {
                if (bytes === 0) return '0 B';
                const k = 1024;
                const sizes = ['B', 'KB', 'MB', 'GB'];
                const i = Math.floor(Math.log(bytes) / Math.log(k));
                return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
            }
            
            function handleFile(file) {
                if (!file) return;
                
                // Validate file type
                if (file.type !== 'application/pdf' && file.type !== 'application/x-pdf') {
                    showStatus('error', 'Please select a valid PDF file');
                    return;
                }
                
                // Validate size
                if (file.size > 50 * 1024 * 1024) {
                    showStatus('error', 'File size exceeds 50MB limit');
                    return;
                }
                
                selectedFile = file;
                fileName.textContent = file.name;
                fileSize.textContent = formatSize(file.size);
                fileInfo.classList.add('show');
                dropZone.style.display = 'none';
                hideStatus();
                downloadBtn.classList.remove('show');
            }
            
            function removeFile() {
                selectedFile = null;
                fileInfo.classList.remove('show');
                dropZone.style.display = 'block';
                fileInput.value = '';
                hideStatus();
                progressContainer.classList.remove('show');
                downloadBtn.classList.remove('show');
            }
            
            function showStatus(type, message) {
                status.className = 'status show ' + type;
                statusMessage.textContent = message;
                if (type === 'loading') {
                    spinner.style.display = 'block';
                } else {
                    spinner.style.display = 'none';
                }
            }
            
            function hideStatus() {
                status.className = 'status';
                statusMessage.textContent = '';
                spinner.style.display = 'none';
            }
            
            // Drag and drop events
            dropZone.addEventListener('dragover', function(e) {
                e.preventDefault();
                this.classList.add('drag-over');
            });
            
            dropZone.addEventListener('dragleave', function(e) {
                e.preventDefault();
                this.classList.remove('drag-over');
            });
            
            dropZone.addEventListener('drop', function(e) {
                e.preventDefault();
                this.classList.remove('drag-over');
                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    handleFile(files[0]);
                }
            });
            
            fileInput.addEventListener('change', function() {
                if (this.files.length > 0) {
                    handleFile(this.files[0]);
                }
            });
            
            removeFile.addEventListener('click', removeFile);
            
            // Form submission
            uploadForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                if (!selectedFile) {
                    showStatus('error', 'Please select a PDF file');
                    return;
                }
                
                // Show progress
                progressContainer.classList.add('show');
                progressBar.style.width = '0%';
                showStatus('loading', 'Starting conversion...');
                downloadBtn.classList.remove('show');
                
                // Create FormData
                const formData = new FormData();
                formData.append('pdf_file', selectedFile);
                
                // Upload with progress
                const xhr = new XMLHttpRequest();
                xhr.open('POST', window.location.href, true);
                
                xhr.upload.addEventListener('progress', function(e) {
                    if (e.lengthComputable) {
                        const percent = (e.loaded / e.total) * 100;
                        progressBar.style.width = percent + '%';
                        showStatus('loading', `Uploading... ${Math.round(percent)}%`);
                    }
                });
                
                xhr.onload = function() {
                    progressBar.style.width = '100%';
                    
                    try {
                        const response = JSON.parse(this.responseText);
                        
                        if (response.success) {
                            showStatus('success', response.message);
                            if (response.download) {
                                downloadBtn.classList.add('show');
                                downloadBtn.dataset.url = response.download;
                            }
                            removeFile();
                        } else {
                            showStatus('error', response.message || 'Conversion failed');
                        }
                    } catch (e) {
                        showStatus('error', 'An unexpected error occurred');
                        console.error('Response parse error:', e);
                    }
                    
                    setTimeout(() => {
                        progressContainer.classList.remove('show');
                    }, 1000);
                };
                
                xhr.onerror = function() {
                    showStatus('error', 'Network error occurred. Please try again.');
                    progressContainer.classList.remove('show');
                };
                
                xhr.send(formData);
            });
            
            // Download handler
            downloadBtn.addEventListener('click', function() {
                const url = this.dataset.url;
                if (url) {
                    window.location.href = url;
                    setTimeout(() => {
                        this.classList.remove('show');
                        showStatus('success', 'Download complete!');
                    }, 1000);
                }
            });
            
            // Click on drop zone to trigger file input
            dropZone.addEventListener('click', function(e) {
                if (e.target.tagName !== 'INPUT') {
                    fileInput.click();
                }
            });
        });
    </script>
</body>
</html>
