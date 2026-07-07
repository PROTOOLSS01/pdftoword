<?php
require_once 'vendor/autoload.php';

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Settings;
use Smalot\PdfParser\Parser;
use thiagoalessio\TesseractOCR\TesseractOCR;

session_start();

// Configuration
$maxFileSize = 100 * 1024 * 1024; // 100MB
$uploadDir = __DIR__ . '/uploads/';
$outputDir = __DIR__ . '/outputs/';

// Create directories if they don't exist
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}
if (!file_exists($outputDir)) {
    mkdir($outputDir, 0755, true);
}

// Clean old temporary files (older than 1 hour)
$files = glob($uploadDir . '*');
$now = time();
foreach ($files as $file) {
    if (is_file($file) && ($now - filemtime($file) > 3600)) {
        unlink($file);
    }
}
$files = glob($outputDir . '*');
foreach ($files as $file) {
    if (is_file($file) && ($now - filemtime($file) > 3600)) {
        unlink($file);
    }
}

$error = '';
$success = '';
$downloadUrl = '';

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['pdf_file'])) {
    $file = $_FILES['pdf_file'];
    
    // Validate file
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = 'Upload failed with error code: ' . $file['error'];
    } elseif ($file['size'] > $maxFileSize) {
        $error = 'File size exceeds 100MB limit';
    } else {
        $fileInfo = pathinfo($file['name']);
        $extension = strtolower($fileInfo['extension'] ?? '');
        
        if ($extension !== 'pdf') {
            $error = 'Only PDF files are allowed';
        } else {
            // Generate secure filename
            $originalName = $fileInfo['filename'];
            $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $originalName);
            $uniqueId = uniqid() . '_' . bin2hex(random_bytes(8));
            $uploadPath = $uploadDir . $uniqueId . '.pdf';
            
            // Move uploaded file
            if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
                try {
                    // Convert PDF to Word
                    $outputFilename = $uniqueId . '.docx';
                    $outputPath = $outputDir . $outputFilename;
                    
                    // Attempt conversion
                    $conversionResult = convertPdfToWord($uploadPath, $outputPath);
                    
                    if ($conversionResult['success']) {
                        $success = 'Conversion completed successfully!';
                        $downloadUrl = '/outputs/' . $outputFilename;
                    } else {
                        $error = $conversionResult['error'];
                    }
                    
                    // Clean up uploaded file
                    if (file_exists($uploadPath)) {
                        unlink($uploadPath);
                    }
                    
                } catch (Exception $e) {
                    $error = 'Conversion error: ' . $e->getMessage();
                    if (file_exists($uploadPath)) {
                        unlink($uploadPath);
                    }
                }
            } else {
                $error = 'Failed to move uploaded file';
            }
        }
    }
}

/**
 * Convert PDF to Word using multiple strategies
 */
function convertPdfToWord($pdfPath, $wordPath) {
    $result = ['success' => false, 'error' => ''];
    
    // Strategy 1: Try using LibreOffice (best for formatted PDFs)
    if (function_exists('exec') && is_executable('/usr/bin/libreoffice')) {
        try {
            $command = '/usr/bin/libreoffice --headless --convert-to docx --outdir "' . dirname($wordPath) . '" "' . $pdfPath . '" 2>&1';
            exec($command, $output, $returnCode);
            
            // Check if file was created
            $possibleOutput = dirname($wordPath) . '/' . pathinfo($pdfPath, PATHINFO_FILENAME) . '.docx';
            if ($returnCode === 0 && file_exists($possibleOutput)) {
                // Rename to our desired filename
                if ($possibleOutput !== $wordPath) {
                    rename($possibleOutput, $wordPath);
                }
                
                if (file_exists($wordPath) && filesize($wordPath) > 0) {
                    $result['success'] = true;
                    return $result;
                }
            }
        } catch (Exception $e) {
            // Continue to next strategy
        }
    }
    
    // Strategy 2: Use PDF parsing library
    try {
        $parser = new Parser();
        $pdf = $parser->parseFile($pdfPath);
        $text = $pdf->getText();
        
        if (!empty(trim($text))) {
            // Create Word document from extracted text
            $phpWord = new \PhpOffice\PhpWord\PhpWord();
            $section = $phpWord->addSection();
            
            // Split text into paragraphs
            $paragraphs = explode("\n", $text);
            foreach ($paragraphs as $paragraph) {
                $paragraph = trim($paragraph);
                if (!empty($paragraph)) {
                    $section->addText($paragraph, ['size' => 12, 'name' => 'Arial']);
                }
            }
            
            $writer = IOFactory::createWriter($phpWord, 'Word2007');
            $writer->save($wordPath);
            
            if (file_exists($wordPath) && filesize($wordPath) > 0) {
                $result['success'] = true;
                return $result;
            }
        }
    } catch (Exception $e) {
        // Continue to OCR fallback
    }
    
    // Strategy 3: OCR fallback for scanned PDFs
    try {
        $imagePath = dirname($pdfPath) . '/temp_image.png';
        
        // Convert PDF pages to images using ghostscript
        if (is_executable('/usr/bin/gs')) {
            $command = '/usr/bin/gs -dNOPAUSE -dBATCH -sDEVICE=png16m -r150 -sOutputFile="' . $imagePath . '" "' . $pdfPath . '" 2>&1';
            exec($command, $output, $returnCode);
            
            if ($returnCode === 0 && file_exists($imagePath)) {
                // Perform OCR using Tesseract
                $ocr = new TesseractOCR($imagePath);
                $text = $ocr->run();
                
                if (!empty(trim($text))) {
                    // Create Word document from OCR text
                    $phpWord = new \PhpOffice\PhpWord\PhpWord();
                    $section = $phpWord->addSection();
                    
                    $paragraphs = explode("\n", $text);
                    foreach ($paragraphs as $paragraph) {
                        $paragraph = trim($paragraph);
                        if (!empty($paragraph)) {
                            $section->addText($paragraph, ['size' => 12, 'name' => 'Arial']);
                        }
                    }
                    
                    $writer = IOFactory::createWriter($phpWord, 'Word2007');
                    $writer->save($wordPath);
                    
                    if (file_exists($wordPath) && filesize($wordPath) > 0) {
                        $result['success'] = true;
                    }
                }
                
                // Clean up image
                if (file_exists($imagePath)) {
                    unlink($imagePath);
                }
            }
        }
    } catch (Exception $e) {
        $result['error'] = 'All conversion methods failed: ' . $e->getMessage();
    }
    
    if (!$result['success'] && empty($result['error'])) {
        $result['error'] = 'Unable to convert PDF to Word. The PDF might be empty or corrupted.';
    }
    
    return $result;
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
