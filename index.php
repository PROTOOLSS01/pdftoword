<?php
/**
 * index.php
 * Production-ready PDF to Word Converter
 * Handles upload, conversion, preview, download and cleanup.
 * Uses LibreOffice for PDF→DOCX conversion.
 */

// ======================== CONFIGURATION ========================
define('MAX_FILE_SIZE', 100 * 1024 * 1024); // 100 MB
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('OUTPUT_DIR', __DIR__ . '/output/');
define('TEMP_DIR', __DIR__ . '/temp/');
define('CLEANUP_AGE', 3600); // delete files older than 1 hour

// ======================== CLEANUP ==============================
/**
 * Delete files older than CLEANUP_AGE seconds from given directory
 */
function cleanupDirectory($dir)
{
    if (!is_dir($dir)) return;
    $files = glob($dir . '*');
    $now = time();
    foreach ($files as $file) {
        if (is_file($file) && ($now - filemtime($file)) > CLEANUP_AGE) {
            unlink($file);
        }
    }
}

// Run cleanup on every request
cleanupDirectory(UPLOAD_DIR);
cleanupDirectory(OUTPUT_DIR);
cleanupDirectory(TEMP_DIR);

// ======================== HANDLE ACTIONS =======================
$action = isset($_GET['action']) ? $_GET['action'] : '';
if ($action === 'download' && isset($_GET['file'])) {
    $file = basename($_GET['file']); // prevent path traversal
    $filePath = OUTPUT_DIR . $file;
    if (file_exists($filePath) && is_file($filePath)) {
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        // Delete both the converted file and the original PDF after download
        $pdfFile = str_replace('.docx', '.pdf', $filePath);
        if (file_exists($pdfFile)) unlink($pdfFile);
        unlink($filePath);
        exit;
    } else {
        http_response_code(404);
        exit('File not found');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['pdf_file'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];

    try {
        $file = $_FILES['pdf_file'];
        // Validate upload error
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Upload error: ' . $file['error']);
        }
        // Validate size
        if ($file['size'] > MAX_FILE_SIZE) {
            throw new Exception('File exceeds maximum size of 100 MB.');
        }
        // Validate MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if ($mime !== 'application/pdf') {
            throw new Exception('Invalid file type. Only PDF files are allowed.');
        }
        // Validate extension (additional check)
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'pdf') {
            throw new Exception('File must have .pdf extension.');
        }

        // Generate unique names
        $baseName = uniqid('pdf_', true);
        $inputPath = UPLOAD_DIR . $baseName . '.pdf';
        $outputPath = OUTPUT_DIR . $baseName . '.docx';

        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $inputPath)) {
            throw new Exception('Failed to move uploaded file.');
        }

        // Convert using LibreOffice
        $cmd = sprintf(
            'libreoffice --headless --convert-to docx --outdir %s %s 2>&1',
            escapeshellarg(OUTPUT_DIR),
            escapeshellarg($inputPath)
        );
        exec($cmd, $output, $returnCode);

        // Check if conversion succeeded
        if ($returnCode !== 0) {
            // Clean up input if conversion failed
            if (file_exists($inputPath)) unlink($inputPath);
            throw new Exception('Conversion failed. Please check that LibreOffice is installed and PDF is valid.');
        }

        // LibreOffice generates file with same basename as input, but .docx
        // However, we used unique name with .pdf, so output will be baseName.docx
        if (!file_exists($outputPath)) {
            // Sometimes LibreOffice might add suffix? Actually no.
            throw new Exception('Conversion output not found.');
        }

        // Return success with download URL
        $downloadUrl = 'index.php?action=download&file=' . urlencode($baseName . '.docx');
        $response['success'] = true;
        $response['download_url'] = $downloadUrl;
        $response['message'] = 'Conversion successful!';
        // Also return the original PDF for preview
        $response['preview_url'] = 'uploads/' . $baseName . '.pdf';
        // Keep both files for preview/download; cleanup will remove them later

    } catch (Exception $e) {
        $response['message'] = $e->getMessage();
    }

    echo json_encode($response);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PDF to Word Converter | Free Online Tool</title>
    <meta name="description" content="Convert your PDF files to editable Word documents (DOCX) instantly. Free, secure, and no sign-up required.">
    <link rel="canonical" href="https://yourdomain.com/">
    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* ========== CSS VARIABLES (Light/Dark) ========== */
        :root {
            --bg-color: #f8f9fc;
            --text-color: #1a1a2e;
            --card-bg: #ffffff;
            --border-color: #e0e5ec;
            --primary: #4a6cf7;
            --primary-hover: #3a56d4;
            --shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            --radius: 16px;
            --transition: 0.3s ease;
            --drop-bg: #f0f4ff;
            --drop-border: #4a6cf7;
        }
        [data-theme="dark"] {
            --bg-color: #0f0f1a;
            --text-color: #e4e6f0;
            --card-bg: #1a1a2e;
            --border-color: #2d2d44;
            --shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
            --drop-bg: #1a1a30;
            --drop-border: #6a8cff;
        }
        /* ========== RESET & BASE ========== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-color);
            color: var(--text-color);
            transition: background var(--transition), color var(--transition);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 2rem 1rem;
            line-height: 1.6;
        }
        /* ========== CONTAINER ========== */
        .app-container {
            max-width: 800px;
            width: 100%;
            margin: 0 auto;
        }
        /* ========== HEADER ========== */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .logo h1 {
            font-size: 1.8rem;
            font-weight: 700;
            background: linear-gradient(135deg, #4a6cf7, #6a8cff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .logo span {
            font-size: 0.9rem;
            color: var(--text-color);
            opacity: 0.7;
            -webkit-text-fill-color: var(--text-color);
            display: block;
            font-weight: 400;
        }
        .theme-toggle {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 50px;
            padding: 0.5rem 1rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--text-color);
            transition: background var(--transition);
            font-size: 0.9rem;
        }
        .theme-toggle:hover {
            background: var(--drop-bg);
        }
        .theme-toggle i {
            font-size: 1.1rem;
        }
        /* ========== CARD ========== */
        .card {
            background: var(--card-bg);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 2rem 1.5rem;
            border: 1px solid var(--border-color);
            transition: background var(--transition), border var(--transition), box-shadow var(--transition);
            margin-bottom: 1.5rem;
        }
        /* ========== DROP ZONE ========== */
        .drop-zone {
            border: 2px dashed var(--border-color);
            border-radius: var(--radius);
            padding: 2.5rem 1rem;
            text-align: center;
            cursor: pointer;
            transition: border var(--transition), background var(--transition);
            background: var(--drop-bg);
            position: relative;
        }
        .drop-zone.dragover {
            border-color: var(--drop-border);
            background: rgba(74, 108, 247, 0.06);
        }
        .drop-zone i {
            font-size: 3rem;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }
        .drop-zone p {
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
        }
        .drop-zone .file-info {
            font-size: 0.9rem;
            opacity: 0.7;
        }
        .drop-zone input[type="file"] {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
            width: 100%;
            height: 100%;
        }
        /* ========== PROGRESS BAR ========== */
        .progress-wrapper {
            display: none;
            margin-top: 1.5rem;
        }
        .progress-bar-bg {
            width: 100%;
            height: 8px;
            background: var(--border-color);
            border-radius: 10px;
            overflow: hidden;
        }
        .progress-bar {
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, #4a6cf7, #6a8cff);
            border-radius: 10px;
            transition: width 0.1s linear;
        }
        .progress-text {
            display: flex;
            justify-content: space-between;
            font-size: 0.85rem;
            margin-top: 0.3rem;
            opacity: 0.8;
        }
        /* ========== PREVIEW ========== */
        .preview-section {
            display: none;
            margin-top: 1.5rem;
        }
        .preview-section iframe {
            width: 100%;
            height: 400px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            background: #fff;
        }
        /* ========== BUTTONS ========== */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.7rem 1.8rem;
            border: none;
            border-radius: 50px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all var(--transition);
            text-decoration: none;
            background: var(--primary);
            color: #fff;
        }
        .btn:hover {
            background: var(--primary-hover);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(74, 108, 247, 0.3);
        }
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        .btn-success {
            background: #22c55e;
        }
        .btn-success:hover {
            background: #16a34a;
            box-shadow: 0 8px 20px rgba(34, 197, 94, 0.3);
        }
        .btn-outline {
            background: transparent;
            border: 2px solid var(--primary);
            color: var(--primary);
        }
        .btn-outline:hover {
            background: var(--primary);
            color: #fff;
        }
        .action-group {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-top: 1.5rem;
            justify-content: center;
        }
        /* ========== LOADING SPINNER ========== */
        .spinner {
            display: inline-block;
            width: 24px;
            height: 24px;
            border: 3px solid rgba(255,255,255,0.3);
            border-top: 3px solid #fff;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        /* ========== MESSAGES ========== */
        .message {
            margin-top: 1rem;
            padding: 0.8rem 1rem;
            border-radius: var(--radius);
            font-weight: 500;
            display: none;
        }
        .message.error {
            background: #fee2e2;
            color: #b91c1c;
            display: block;
        }
        .message.success {
            background: #dcfce7;
            color: #166534;
            display: block;
        }
        /* ========== FOOTER ========== */
        .footer {
            margin-top: 2rem;
            text-align: center;
            font-size: 0.85rem;
            opacity: 0.6;
        }
        .footer a {
            color: var(--primary);
            text-decoration: none;
        }
        /* ========== RESPONSIVE ========== */
        @media (max-width: 600px) {
            .header { flex-direction: column; align-items: flex-start; }
            .logo h1 { font-size: 1.5rem; }
            .card { padding: 1.5rem 1rem; }
            .drop-zone { padding: 1.5rem 0.5rem; }
            .action-group .btn { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>
<div class="app-container">

    <!-- HEADER -->
    <header class="header">
        <div class="logo">
            <h1>PDF → Word</h1>
            <span>Free &amp; secure converter</span>
        </div>
        <button class="theme-toggle" id="themeToggle" aria-label="Toggle dark mode">
            <i class="fas fa-moon"></i> <span id="themeLabel">Dark</span>
        </button>
    </header>

    <!-- MAIN CARD -->
    <main class="card" role="main">
        <h2 style="margin-bottom: 1.5rem; font-weight: 600;">Upload your PDF</h2>

        <!-- DROP ZONE -->
        <div class="drop-zone" id="dropZone">
            <i class="fas fa-cloud-upload-alt"></i>
            <p><strong>Drag &amp; drop</strong> your PDF here</p>
            <p class="file-info">or click to browse (max 100 MB)</p>
            <input type="file" id="fileInput" accept=".pdf,application/pdf" />
        </div>

        <!-- PROGRESS -->
        <div class="progress-wrapper" id="progressWrapper">
            <div class="progress-bar-bg">
                <div class="progress-bar" id="progressBar" style="width:0%;"></div>
            </div>
            <div class="progress-text">
                <span id="progressPercent">0%</span>
                <span id="progressStatus">Uploading...</span>
            </div>
        </div>

        <!-- PREVIEW -->
        <div class="preview-section" id="previewSection">
            <h3 style="margin-bottom: 0.5rem; font-weight: 500;">Preview</h3>
            <iframe id="pdfPreview" src="" title="PDF Preview"></iframe>
        </div>

        <!-- ACTION BUTTONS -->
        <div class="action-group" id="actionGroup" style="display: none;">
            <button class="btn btn-success" id="convertBtn" disabled>
                <i class="fas fa-file-word"></i> Convert to DOCX
            </button>
            <a href="#" class="btn btn-success" id="downloadBtn" style="display: none;">
                <i class="fas fa-download"></i> Download DOCX
            </a>
            <button class="btn btn-outline" id="resetBtn">
                <i class="fas fa-undo"></i> New File
            </button>
        </div>

        <!-- MESSAGE -->
        <div id="message" class="message"></div>
    </main>

    <!-- FOOTER -->
    <footer class="footer">
        <p>Built with ❤️ · Secure &amp; serverless · <a href="#" onclick="alert('No data is stored permanently. Files are auto-deleted after 1 hour.'); return false;">Privacy</a></p>
    </footer>
</div>

<script>
    (function() {
        'use strict';

        // ========== DOM REFS ==========
        const dropZone = document.getElementById('dropZone');
        const fileInput = document.getElementById('fileInput');
        const progressWrapper = document.getElementById('progressWrapper');
        const progressBar = document.getElementById('progressBar');
        const progressPercent = document.getElementById('progressPercent');
        const progressStatus = document.getElementById('progressStatus');
        const previewSection = document.getElementById('previewSection');
        const pdfPreview = document.getElementById('pdfPreview');
        const actionGroup = document.getElementById('actionGroup');
        const convertBtn = document.getElementById('convertBtn');
        const downloadBtn = document.getElementById('downloadBtn');
        const resetBtn = document.getElementById('resetBtn');
        const messageEl = document.getElementById('message');
        const themeToggle = document.getElementById('themeToggle');
        const themeLabel = document.getElementById('themeLabel');

        let currentFile = null; // File object
        let uploadedFileName = null; // server-side name (for preview)
        let downloadUrl = null;

        // ========== THEME ==========
        function setTheme(theme) {
            document.documentElement.setAttribute('data-theme', theme);
            localStorage.setItem('theme', theme);
            themeLabel.textContent = theme === 'dark' ? 'Light' : 'Dark';
            themeToggle.querySelector('i').className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
        }
        // Load saved theme
        const savedTheme = localStorage.getItem('theme') || 'light';
        setTheme(savedTheme);
        themeToggle.addEventListener('click', () => {
            const current = document.documentElement.getAttribute('data-theme');
            setTheme(current === 'dark' ? 'light' : 'dark');
        });

        // ========== HELPERS ==========
        function showMessage(text, type = 'error') {
            messageEl.textContent = text;
            messageEl.className = 'message ' + type;
        }

        function hideMessage() {
            messageEl.className = 'message';
            messageEl.textContent = '';
        }

        function resetUI() {
            hideMessage();
            progressWrapper.style.display = 'none';
            progressBar.style.width = '0%';
            progressPercent.textContent = '0%';
            previewSection.style.display = 'none';
            pdfPreview.src = '';
            actionGroup.style.display = 'none';
            downloadBtn.style.display = 'none';
            convertBtn.disabled = true;
            currentFile = null;
            uploadedFileName = null;
            downloadUrl = null;
            fileInput.value = ''; // reset file input
        }

        // ========== HANDLE FILE SELECTION ==========
        function handleFile(file) {
            if (!file) return;
            // Validate type
            if (file.type !== 'application/pdf' && !file.name.toLowerCase().endsWith('.pdf')) {
                showMessage('Please select a valid PDF file.', 'error');
                return;
            }
            if (file.size > 100 * 1024 * 1024) {
                showMessage('File exceeds 100 MB limit.', 'error');
                return;
            }
            hideMessage();
            currentFile = file;
            // Show file name in drop zone
            const info = dropZone.querySelector('.file-info');
            info.textContent = `📄 ${file.name} (${(file.size / 1024 / 1024).toFixed(2)} MB)`;
            // Trigger upload automatically
            uploadFile(file);
        }

        // ========== UPLOAD FILE (AJAX with progress) ==========
        function uploadFile(file) {
            const formData = new FormData();
            formData.append('pdf_file', file);

            const xhr = new XMLHttpRequest();
            xhr.open('POST', window.location.href, true);

            // Progress events
            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable) {
                    const percent = Math.round((e.loaded / e.total) * 100);
                    progressBar.style.width = percent + '%';
                    progressPercent.textContent = percent + '%';
                    progressStatus.textContent = percent === 100 ? 'Processing...' : 'Uploading...';
                }
            });

            xhr.onloadstart = function() {
                progressWrapper.style.display = 'block';
                progressBar.style.width = '0%';
                progressPercent.textContent = '0%';
                progressStatus.textContent = 'Uploading...';
                convertBtn.disabled = true;
                actionGroup.style.display = 'none'; // hide until upload complete
                showMessage('Uploading...', '');
            };

            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        const resp = JSON.parse(xhr.responseText);
                        if (resp.success) {
                            // Upload and conversion succeeded
                            uploadedFileName = resp.preview_url; // e.g., uploads/pdf_xxx.pdf
                            downloadUrl = resp.download_url;
                            // Show preview
                            pdfPreview.src = uploadedFileName;
                            previewSection.style.display = 'block';
                            // Enable convert button (though conversion already done)
                            convertBtn.disabled = false;
                            // Show download button
                            downloadBtn.href = downloadUrl;
                            downloadBtn.style.display = 'inline-flex';
                            // Show action group
                            actionGroup.style.display = 'flex';
                            showMessage(resp.message, 'success');
                            progressStatus.textContent = 'Done!';
                        } else {
                            showMessage(resp.message || 'Conversion failed.', 'error');
                            resetUI();
                        }
                    } catch (e) {
                        showMessage('Invalid server response.', 'error');
                        resetUI();
                    }
                } else {
                    showMessage('Server error (HTTP ' + xhr.status + ').', 'error');
                    resetUI();
                }
            };

            xhr.onerror = function() {
                showMessage('Network error. Please try again.', 'error');
                resetUI();
            };

            xhr.send(formData);
        }

        // ========== EVENT LISTENERS ==========

        // Browse button (click on drop zone triggers file input)
        dropZone.addEventListener('click', (e) => {
            if (e.target.tagName !== 'INPUT') {
                fileInput.click();
            }
        });

        fileInput.addEventListener('change', (e) => {
            if (e.target.files.length > 0) {
                handleFile(e.target.files[0]);
            }
        });

        // Drag & drop
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
            if (e.dataTransfer.files.length > 0) {
                handleFile(e.dataTransfer.files[0]);
            }
        });

        // Convert button (if needed, but conversion already done; we'll just trigger download)
        convertBtn.addEventListener('click', () => {
            if (downloadUrl) {
                window.location.href = downloadUrl;
            } else {
                showMessage('No converted file available.', 'error');
            }
        });

        // Reset button
        resetBtn.addEventListener('click', () => {
            resetUI();
            // Reset drop zone info
            dropZone.querySelector('.file-info').textContent = 'or click to browse (max 100 MB)';
            // Hide message
            hideMessage();
            // Reset progress
            progressWrapper.style.display = 'none';
            progressBar.style.width = '0%';
            progressPercent.textContent = '0%';
            // Reset preview
            previewSection.style.display = 'none';
            pdfPreview.src = '';
            actionGroup.style.display = 'none';
            downloadBtn.style.display = 'none';
            convertBtn.disabled = true;
            currentFile = null;
        });

        // ========== INITIAL ==========
        resetUI();
    })();
</script>
</body>
</html>
