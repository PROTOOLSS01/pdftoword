<?php
// index.php - Production Ready PDF to Word Converter
// PHP 8.3, Docker, LibreOffice, Ghostscript, Tesseract OCR

// Security & Error Reporting
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('memory_limit', '512M');
ini_set('upload_max_filesize', '100M');
ini_set('post_max_size', '100M');
ini_set('max_execution_time', '300');

// Define paths
define('UPLOAD_DIR', __DIR__ . '/uploads');
define('OUTPUT_DIR', __DIR__ . '/output');
define('LOG_FILE', __DIR__ . '/converter.log');

// Create directories if they don't exist
foreach ([UPLOAD_DIR, OUTPUT_DIR] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// Logging function
function logMessage($message) {
    file_put_contents(LOG_FILE, date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL, FILE_APPEND);
}

// Check dependencies
function checkDependencies() {
    $deps = [
        'libreoffice' => 'which libreoffice',
        'gs' => 'which gs',
        'tesseract' => 'which tesseract',
        'pdftoppm' => 'which pdftoppm'
    ];
    
    $missing = [];
    foreach ($deps as $name => $cmd) {
        exec($cmd . ' 2>/dev/null', $output, $returnCode);
        if ($returnCode !== 0) {
            $missing[] = $name;
        }
    }
    
    if (!empty($missing)) {
        logMessage('Missing dependencies: ' . implode(', ', $missing));
        return false;
    }
    return true;
}

// Generate random filename
function generateRandomFilename($extension) {
    return bin2hex(random_bytes(16)) . '.' . $extension;
}

// Validate uploaded file
function validateFile($file) {
    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Upload error: ' . $file['error']);
    }
    
    // Check file size (100MB max)
    if ($file['size'] > 100 * 1024 * 1024) {
        throw new Exception('File size exceeds 100MB limit');
    }
    
    // Check file type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    $allowedMimes = ['application/pdf', 'application/x-pdf'];
    if (!in_array($mimeType, $allowedMimes)) {
        throw new Exception('Invalid file type. Only PDF files are allowed.');
    }
    
    // Additional check: file extension
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($extension !== 'pdf') {
        throw new Exception('File must have .pdf extension');
    }
    
    return true;
}

// Detect if PDF is scanned or text-based
function detectPdfType($pdfPath) {
    // Use pdffonts to check for fonts
    exec("pdffonts '$pdfPath' 2>/dev/null | tail -n +3 | grep -v '^$' | wc -l", $output, $returnCode);
    
    if ($returnCode === 0 && isset($output[0]) && intval($output[0]) > 0) {
        return 'text'; // Has fonts - likely text-based
    }
    
    // Check for text content using pdftotext
    exec("pdftotext '$pdfPath' - 2>/dev/null | wc -c", $output, $returnCode);
    if ($returnCode === 0 && isset($output[0]) && intval($output[0]) > 100) {
        return 'text'; // Has extractable text
    }
    
    return 'scanned'; // No text found - likely scanned
}

// Convert PDF to DOCX
function convertPdfToDocx($pdfPath, $outputPath) {
    logMessage('Starting conversion: ' . basename($pdfPath));
    
    // Detect PDF type
    $pdfType = detectPdfType($pdfPath);
    logMessage('PDF type: ' . $pdfType);
    
    if ($pdfType === 'text') {
        // Use LibreOffice for text-based PDFs
        $cmd = "libreoffice --headless --convert-to docx --outdir '" . dirname($outputPath) . "' '$pdfPath' 2>&1";
        exec($cmd, $output, $returnCode);
        
        if ($returnCode !== 0) {
            throw new Exception('LibreOffice conversion failed: ' . implode("\n", $output));
        }
        
        // LibreOffice creates file with original name, rename to random
        $originalBasename = pathinfo($pdfPath, PATHINFO_FILENAME);
        $libreofficeOutput = dirname($outputPath) . '/' . $originalBasename . '.docx';
        
        if (file_exists($libreofficeOutput)) {
            rename($libreofficeOutput, $outputPath);
        } else {
            throw new Exception('LibreOffice output file not found');
        }
    } else {
        // Scanned PDF - OCR first then convert
        logMessage('Performing OCR on scanned PDF');
        $ocrPdfPath = dirname($pdfPath) . '/ocr_' . basename($pdfPath);
        
        // Convert PDF to images and OCR
        $imageDir = dirname($pdfPath) . '/images';
        if (!is_dir($imageDir)) {
            mkdir($imageDir, 0755, true);
        }
        
        // Convert PDF to images using Ghostscript
        $cmd = "gs -dNOPAUSE -dBATCH -sDEVICE=png16m -r300 -sOutputFile='$imageDir/page_%d.png' '$pdfPath' 2>&1";
        exec($cmd, $output, $returnCode);
        
        if ($returnCode !== 0) {
            throw new Exception('Ghostscript conversion failed: ' . implode("\n", $output));
        }
        
        // Process each image with Tesseract OCR
        $images = glob($imageDir . '/*.png');
        if (empty($images)) {
            throw new Exception('No images generated for OCR');
        }
        
        $ocrText = '';
        foreach ($images as $image) {
            $ocrOutput = $image . '.txt';
            $cmd = "tesseract '$image' '" . pathinfo($image, PATHINFO_FILENAME) . "' -l eng --psm 6 2>&1";
            exec($cmd, $output, $returnCode);
            
            if ($returnCode !== 0) {
                throw new Exception('Tesseract OCR failed: ' . implode("\n", $output));
            }
            
            if (file_exists($ocrOutput)) {
                $ocrText .= file_get_contents($ocrOutput) . "\n\n";
                unlink($ocrOutput);
            }
        }
        
        // Clean up images
        foreach ($images as $image) {
            unlink($image);
        }
        rmdir($imageDir);
        
        if (empty(trim($ocrText))) {
            throw new Exception('No text extracted from scanned PDF');
        }
        
        // Create a temporary text file for LibreOffice
        $tempTextFile = dirname($pdfPath) . '/temp_text.txt';
        file_put_contents($tempTextFile, $ocrText);
        
        // Convert text to DOCX using LibreOffice
        $cmd = "libreoffice --headless --convert-to docx --outdir '" . dirname($outputPath) . "' '$tempTextFile' 2>&1";
        exec($cmd, $output, $returnCode);
        
        if ($returnCode !== 0) {
            throw new Exception('LibreOffice text to DOCX conversion failed: ' . implode("\n", $output));
        }
        
        $tempDocx = dirname($outputPath) . '/temp_text.docx';
        if (file_exists($tempDocx)) {
            rename($tempDocx, $outputPath);
        } else {
            throw new Exception('DOCX output file not found');
        }
        
        unlink($tempTextFile);
    }
    
    logMessage('Conversion completed: ' . basename($outputPath));
    return true;
}

// Cleanup old files (older than 1 hour)
function cleanupFiles() {
    $dirs = [UPLOAD_DIR, OUTPUT_DIR];
    $now = time();
    
    foreach ($dirs as $dir) {
        if (is_dir($dir)) {
            $files = glob($dir . '/*');
            foreach ($files as $file) {
                if (is_file($file) && ($now - filemtime($file)) > 3600) {
                    unlink($file);
                    logMessage('Cleaned up old file: ' . basename($file));
                }
            }
        }
    }
}

// Handle conversion request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['pdf_file'])) {
    try {
        // Cleanup old files
        cleanupFiles();
        
        // Check dependencies
        if (!checkDependencies()) {
            throw new Exception('Missing required system dependencies');
        }
        
        // Validate file
        validateFile($_FILES['pdf_file']);
        
        // Save uploaded file with random name
        $uploadedFile = $_FILES['pdf_file'];
        $randomName = generateRandomFilename('pdf');
        $pdfPath = UPLOAD_DIR . '/' . $randomName;
        
        if (!move_uploaded_file($uploadedFile['tmp_name'], $pdfPath)) {
            throw new Exception('Failed to move uploaded file');
        }
        
        logMessage('File uploaded: ' . $randomName);
        
        // Prepare output path
        $outputName = generateRandomFilename('docx');
        $outputPath = OUTPUT_DIR . '/' . $outputName;
        
        // Convert
        convertPdfToDocx($pdfPath, $outputPath);
        
        // Check if output file exists
        if (!file_exists($outputPath) || filesize($outputPath) === 0) {
            throw new Exception('Conversion failed: Output file is empty or missing');
        }
        
        // Prepare response
        $response = [
            'success' => true,
            'message' => 'PDF successfully converted to DOCX!',
            'download_url' => 'download.php?file=' . urlencode($outputName),
            'filename' => $outputName
        ];
        
        // Clean up uploaded PDF (keep output file for download)
        unlink($pdfPath);
        logMessage('Uploaded PDF deleted: ' . $randomName);
        
        // Return JSON response for AJAX
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode($response);
            exit;
        }
        
        // Store result in session for non-AJAX
        session_start();
        $_SESSION['conversion_result'] = $response;
        header('Location: ' . $_SERVER['PHP_SELF'] . '?result=success');
        exit;
        
    } catch (Exception $e) {
        logMessage('Error: ' . $e->getMessage());
        
        // Clean up any partial files
        if (isset($pdfPath) && file_exists($pdfPath)) {
            unlink($pdfPath);
        }
        
        $error = $e->getMessage();
        
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => $error]);
            exit;
        }
        
        session_start();
        $_SESSION['conversion_error'] = $error;
        header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
        exit;
    }
}

// Handle download
if (isset($_GET['download']) && isset($_GET['file'])) {
    $filename = basename($_GET['file']);
    $filepath = OUTPUT_DIR . '/' . $filename;
    
    if (file_exists($filepath)) {
        // Clean up after download
        $cleanup = function() use ($filepath) {
            if (file_exists($filepath)) {
                unlink($filepath);
                logMessage('Output file deleted after download: ' . basename($filepath));
            }
        };
        register_shutdown_function($cleanup);
        
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="converted_' . date('Y-m-d') . '.docx"');
        header('Content-Length: ' . filesize($filepath));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        
        readfile($filepath);
        exit;
    } else {
        http_response_code(404);
        echo 'File not found or already deleted.';
        exit;
    }
}

// Show result page
session_start();
$result = $_SESSION['conversion_result'] ?? null;
$error = $_SESSION['conversion_error'] ?? null;
unset($_SESSION['conversion_result'], $_SESSION['conversion_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Free PDF to Word Converter | Convert PDF to DOCX Online</title>
    <meta name="description" content="Convert PDF to editable DOCX online for free. Supports both text and scanned PDFs. No signup required.">
    <link rel="canonical" href="https://<?php echo $_SERVER['HTTP_HOST']; ?>">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            padding: 40px;
            max-width: 600px;
            width: 100%;
            transition: all 0.3s ease;
        }
        h1 {
            color: #2d3748;
            font-size: 28px;
            margin-bottom: 8px;
            text-align: center;
        }
        .subtitle {
            color: #718096;
            text-align: center;
            margin-bottom: 30px;
            font-size: 16px;
        }
        .upload-area {
            border: 3px dashed #e2e8f0;
            border-radius: 16px;
            padding: 40px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            background: #f7fafc;
        }
        .upload-area:hover, .upload-area.dragover {
            border-color: #667eea;
            background: #edf2f7;
            transform: scale(1.01);
        }
        .upload-area svg {
            width: 64px;
            height: 64px;
            color: #667eea;
            margin-bottom: 16px;
        }
        .upload-area p {
            color: #4a5568;
            font-size: 18px;
            margin-bottom: 8px;
        }
        .upload-area small {
            color: #a0aec0;
            font-size: 14px;
        }
        #fileInput {
            display: none;
        }
        .progress-container {
            margin-top: 20px;
            display: none;
        }
        .progress-bar {
            width: 100%;
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
            margin-top: 10px;
        }
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #667eea, #764ba2);
            width: 0%;
            transition: width 0.5s ease;
            border-radius: 4px;
        }
        .progress-text {
            color: #4a5568;
            font-size: 14px;
            text-align: center;
            margin-top: 8px;
        }
        .result-box {
            margin-top: 20px;
            padding: 20px;
            border-radius: 12px;
            display: none;
        }
        .result-box.success {
            display: block;
            background: #f0fff4;
            border: 1px solid #c6f6d5;
            color: #22543d;
        }
        .result-box.error {
            display: block;
            background: #fff5f5;
            border: 1px solid #fed7d7;
            color: #742a2a;
        }
        .result-box .icon {
            font-size: 48px;
            display: block;
            text-align: center;
            margin-bottom: 10px;
        }
        .result-box h3 {
            text-align: center;
            margin-bottom: 8px;
        }
        .result-box p {
            text-align: center;
            margin-bottom: 16px;
        }
        .btn {
            display: inline-block;
            padding: 12px 32px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            text-align: center;
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
        .btn-block {
            display: block;
            width: 100%;
        }
        .btn-secondary {
            background: #e2e8f0;
            color: #2d3748;
        }
        .btn-secondary:hover {
            background: #cbd5e0;
            box-shadow: none;
        }
        .file-info {
            margin-top: 12px;
            font-size: 14px;
            color: #4a5568;
            text-align: center;
        }
        .features {
            margin-top: 30px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        .feature {
            background: #f7fafc;
            padding: 12px;
            border-radius: 8px;
            text-align: center;
            font-size: 14px;
            color: #4a5568;
        }
        .feature svg {
            width: 20px;
            height: 20px;
            display: block;
            margin: 0 auto 6px;
            color: #667eea;
        }
        @media (max-width: 640px) {
            .container { padding: 20px; }
            h1 { font-size: 24px; }
            .features { grid-template-columns: 1fr; }
            .upload-area { padding: 24px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📄 PDF to Word Converter</h1>
        <p class="subtitle">Convert PDF to editable DOCX in seconds • Supports scanned PDFs</p>
        
        <?php if ($result && isset($result['success'])): ?>
        <div class="result-box success" style="display:block;">
            <span class="icon">✅</span>
            <h3>Conversion Complete!</h3>
            <p><?php echo htmlspecialchars($result['message']); ?></p>
            <a href="?download=1&file=<?php echo urlencode($result['filename']); ?>" class="btn btn-block">
                📥 Download DOCX
            </a>
            <br><br>
            <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-secondary btn-block">Convert Another File</a>
        </div>
        <?php elseif ($error): ?>
        <div class="result-box error" style="display:block;">
            <span class="icon">❌</span>
            <h3>Conversion Failed</h3>
            <p><?php echo htmlspecialchars($error); ?></p>
            <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-block">Try Again</a>
        </div>
        <?php else: ?>
        <div class="upload-area" id="dropZone">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
            </svg>
            <p><strong>Drop your PDF here</strong> or click to browse</p>
            <small>Maximum file size: 100MB • Supports text and scanned PDFs</small>
            <input type="file" id="fileInput" accept=".pdf,application/pdf" />
        </div>
        
        <div id="fileInfo" class="file-info" style="display:none;"></div>
        
        <div class="progress-container" id="progressContainer">
            <div class="progress-bar">
                <div class="progress-fill" id="progressFill"></div>
            </div>
            <div class="progress-text" id="progressText">Uploading...</div>
        </div>
        
        <div id="resultContainer"></div>
        
        <div class="features">
            <div class="feature">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                Text PDF Support
            </div>
            <div class="feature">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                Scanned PDF OCR
            </div>
            <div class="feature">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                </svg>
                Secure & Private
            </div>
            <div class="feature">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
                Fast & Free
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
        (function() {
            'use strict';
            
            const dropZone = document.getElementById('dropZone');
            const fileInput = document.getElementById('fileInput');
            const progressContainer = document.getElementById('progressContainer');
            const progressFill = document.getElementById('progressFill');
            const progressText = document.getElementById('progressText');
            const fileInfo = document.getElementById('fileInfo');
            const resultContainer = document.getElementById('resultContainer');
            
            if (!dropZone || !fileInput) return;
            
            // Drag and drop events
            ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                dropZone.addEventListener(eventName, preventDefaults, false);
            });
            
            function preventDefaults(e) {
                e.preventDefault();
                e.stopPropagation();
            }
            
            ['dragenter', 'dragover'].forEach(eventName => {
                dropZone.addEventListener(eventName, () => {
                    dropZone.classList.add('dragover');
                }, false);
            });
            
            ['dragleave', 'drop'].forEach(eventName => {
                dropZone.addEventListener(eventName, () => {
                    dropZone.classList.remove('dragover');
                }, false);
            });
            
            dropZone.addEventListener('drop', handleDrop, false);
            dropZone.addEventListener('click', () => fileInput.click(), false);
            fileInput.addEventListener('change', (e) => {
                if (e.target.files.length) {
                    handleFile(e.target.files[0]);
                }
            });
            
            function handleDrop(e) {
                const dt = e.dataTransfer;
                const files = dt.files;
                if (files.length) {
                    handleFile(files[0]);
                }
            }
            
            function handleFile(file) {
                // Validate file
                if (file.type !== 'application/pdf' && !file.name.toLowerCase().endsWith('.pdf')) {
                    showResult('error', '❌', 'Invalid File', 'Please select a valid PDF file.');
                    return;
                }
                
                if (file.size > 100 * 1024 * 1024) {
                    showResult('error', '❌', 'File Too Large', 'File size exceeds 100MB limit.');
                    return;
                }
                
                // Show file info
                fileInfo.style.display = 'block';
                fileInfo.innerHTML = `<strong>${file.name}</strong> (${(file.size / 1024 / 1024).toFixed(2)} MB)`;
                
                // Upload
                uploadFile(file);
            }
            
            function uploadFile(file) {
                const formData = new FormData();
                formData.append('pdf_file', file);
                
                // Show progress
                progressContainer.style.display = 'block';
                progressFill.style.width = '0%';
                progressText.textContent = 'Uploading...';
                
                const xhr = new XMLHttpRequest();
                xhr.open('POST', window.location.href, true);
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                
                xhr.upload.addEventListener('progress', (e) => {
                    if (e.lengthComputable) {
                        const percent = Math.round((e.loaded / e.total) * 100);
                        progressFill.style.width = percent + '%';
                        progressText.textContent = `Uploading... ${percent}%`;
                    }
                });
                
                xhr.onload = function() {
                    if (xhr.status === 200) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.success) {
                                progressFill.style.width = '100%';
                                progressText.textContent = 'Conversion complete!';
                                showResult('success', '✅', 'Conversion Complete!', response.message, response.download_url);
                            } else {
                                showResult('error', '❌', 'Conversion Failed', response.error || 'Unknown error occurred.');
                            }
                        } catch (e) {
                            showResult('error', '❌', 'Error', 'Invalid response from server.');
                        }
                    } else {
                        showResult('error', '❌', 'Server Error', 'Failed to process request. Please try again.');
                    }
                };
                
                xhr.onerror = function() {
                    showResult('error', '❌', 'Network Error', 'Failed to connect to server. Please check your connection.');
                };
                
                xhr.send(formData);
            }
            
            function showResult(type, icon, title, message, downloadUrl) {
                resultContainer.style.display = 'block';
                resultContainer.className = 'result-box ' + type;
                resultContainer.innerHTML = `
                    <span class="icon">${icon}</span>
                    <h3>${title}</h3>
                    <p>${message}</p>
                    ${downloadUrl ? `<a href="${downloadUrl}" class="btn btn-block">📥 Download DOCX</a>` : ''}
                    ${!downloadUrl ? `<a href="${window.location.href}" class="btn btn-secondary btn-block">Try Again</a>` : ''}
                `;
                
                // Hide progress
                if (type === 'success' || type === 'error') {
                    setTimeout(() => {
                        progressContainer.style.display = 'none';
                    }, 1000);
                }
            }
        })();
    </script>
</body>
</html>
