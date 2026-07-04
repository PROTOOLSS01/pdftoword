<?php
/**
 * index.php
 * Production-ready PDF to Word Converter
 * Primary: LibreOffice conversion, fallback: text extraction/OCR.
 */

// ======================== CONFIGURATION ========================
define('MAX_FILE_SIZE', 100 * 1024 * 1024); // 100 MB
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('OUTPUT_DIR', __DIR__ . '/output/');
define('TEMP_DIR', __DIR__ . '/temp/');
define('CLEANUP_AGE', 3600); // delete files older than 1 hour

// Create directories if they don't exist
foreach ([UPLOAD_DIR, OUTPUT_DIR, TEMP_DIR] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// ======================== CLEANUP ==============================
function cleanupDirectory($dir) {
    if (!is_dir($dir)) return;
    $files = glob($dir . '*');
    $now = time();
    foreach ($files as $file) {
        if (is_file($file) && ($now - filemtime($file)) > CLEANUP_AGE) {
            @unlink($file);
        }
    }
}
cleanupDirectory(UPLOAD_DIR);
cleanupDirectory(OUTPUT_DIR);
cleanupDirectory(TEMP_DIR);

// ======================== LOGGING ==============================
function logError($msg) {
    error_log("[PDF2DOCX] " . $msg);
}

// ======================== HANDLE ACTIONS =======================
$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($action === 'download' && isset($_GET['file'])) {
    $file = basename($_GET['file']);
    $filePath = OUTPUT_DIR . $file;
    if (file_exists($filePath) && is_file($filePath)) {
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        // Delete both the converted file and the original PDF after download
        $pdfFile = str_replace('.docx', '.pdf', $filePath);
        if (file_exists($pdfFile)) @unlink($pdfFile);
        @unlink($filePath);
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
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Upload error: ' . $file['error']);
        }
        if ($file['size'] > MAX_FILE_SIZE) {
            throw new Exception('File exceeds maximum size of 100 MB.');
        }
        // Validate MIME using finfo
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if ($mime !== 'application/pdf') {
            throw new Exception('Invalid file type. Only PDF files are allowed.');
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'pdf') {
            throw new Exception('File must have .pdf extension.');
        }

        $baseName = uniqid('pdf_', true);
        $inputPath = UPLOAD_DIR . $baseName . '.pdf';
        $outputPath = OUTPUT_DIR . $baseName . '.docx';

        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $inputPath)) {
            throw new Exception('Failed to move uploaded file. Check permissions.');
        }
        // Ensure file exists
        if (!file_exists($inputPath)) {
            throw new Exception('Uploaded file not found after move.');
        }

        // ------------------------------------------------------------
        // 1. Attempt conversion with LibreOffice
        // ------------------------------------------------------------
        $converted = false;
        $cmd = sprintf(
            'libreoffice --headless --convert-to docx --outdir %s %s 2>&1',
            escapeshellarg(OUTPUT_DIR),
            escapeshellarg($inputPath)
        );
        exec($cmd, $output, $returnCode);
        logError("LibreOffice return code: $returnCode, output: " . implode("\n", $output));

        // LibreOffice generates a file with same basename but .docx
        if ($returnCode === 0 && file_exists($outputPath) && filesize($outputPath) > 0) {
            $converted = true;
        } else {
            // Try to find if LibreOffice produced a file with a different name (sometimes adds suffix)
            $possibleFiles = glob(OUTPUT_DIR . $baseName . '*.docx');
            if (!empty($possibleFiles)) {
                // Use the first one and rename to expected
                $found = $possibleFiles[0];
                if (rename($found, $outputPath)) {
                    $converted = true;
                }
            }
        }

        // ------------------------------------------------------------
        // 2. Fallback: text extraction with pdftotext (for text-based PDFs)
        // ------------------------------------------------------------
        if (!$converted) {
            $txtFile = TEMP_DIR . $baseName . '.txt';
            $cmd2 = sprintf('pdftotext -layout %s %s 2>&1', escapeshellarg($inputPath), escapeshellarg($txtFile));
            exec($cmd2, $out2, $ret2);
            logError("pdftotext return code: $ret2");

            if ($ret2 === 0 && file_exists($txtFile) && filesize($txtFile) > 100) {
                // Create DOCX with extracted text
                require_once __DIR__ . '/vendor/autoload.php';
                $phpWord = new \PhpOffice\PhpWord\PhpWord();
                $section = $phpWord->addSection();
                $text = file_get_contents($txtFile);
                $text = mb_convert_encoding($text, 'UTF-8', 'auto');
                $section->addText($text);
                $objWriter = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
                $objWriter->save($outputPath);
                $converted = true;
                @unlink($txtFile);
            } else {
                // ------------------------------------------------------------
                // 3. Fallback: OCR for scanned PDFs (using pdftoppm + tesseract)
                // ------------------------------------------------------------
                // Check if pdftoppm exists
                $pdftoppm = shell_exec('which pdftoppm');
                if (empty($pdftoppm)) {
                    throw new Exception('pdftoppm not found. Cannot OCR scanned PDF.');
                }
                // Create image directory
                $imgDir = TEMP_DIR . $baseName . '_images/';
                if (!is_dir($imgDir)) mkdir($imgDir, 0755, true);

                // Convert PDF to images (PNG)
                $cmd3 = sprintf(
                    'pdftoppm -png %s %s 2>&1',
                    escapeshellarg($inputPath),
                    escapeshellarg($imgDir . 'page')
                );
                exec($cmd3, $out3, $ret3);
                logError("pdftoppm return code: $ret3");

                if ($ret3 === 0) {
                    // Find all images
                    $images = glob($imgDir . 'page-*.png');
                    if (empty($images)) {
                        throw new Exception('No images generated from PDF for OCR.');
                    }
                    // OCR each image and concatenate text
                    $ocrText = '';
                    foreach ($images as $img) {
                        $ocrCmd = sprintf('tesseract %s stdout 2>&1', escapeshellarg($img));
                        $ocrOut = shell_exec($ocrCmd);
                        if ($ocrOut !== null) {
                            $ocrText .= $ocrOut . "\n\n";
                        }
                    }
                    // Clean up images
                    array_map('unlink', $images);
                    @rmdir($imgDir);

                    if (strlen(trim($ocrText)) > 10) {
                        require_once __DIR__ . '/vendor/autoload.php';
                        $phpWord = new \PhpOffice\PhpWord\PhpWord();
                        $section = $phpWord->addSection();
                        $text = mb_convert_encoding($ocrText, 'UTF-8', 'auto');
                        $section->addText($text);
                        $objWriter = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
                        $objWriter->save($outputPath);
                        $converted = true;
                    } else {
                        throw new Exception('OCR produced insufficient text. PDF may be corrupted.');
                    }
                } else {
                    throw new Exception('Failed to convert PDF to images for OCR.');
                }
            }
        }

        if (!$converted || !file_exists($outputPath) || filesize($outputPath) < 100) {
            if (file_exists($inputPath)) @unlink($inputPath);
            throw new Exception('Conversion failed. Could not produce a valid DOCX file.');
        }

        // Return success
        $downloadUrl = 'index.php?action=download&file=' . urlencode($baseName . '.docx');
        $response['success'] = true;
        $response['download_url'] = $downloadUrl;
        $response['message'] = 'Conversion successful!';
        $response['preview_url'] = 'uploads/' . $baseName . '.pdf';

    } catch (Exception $e) {
        $response['message'] = $e->getMessage();
        logError("Error: " . $e->getMessage());
    }

    echo json_encode($response);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link rel="icon" type="image/png" href="https://i.ibb.co/FkJPMZ8r/Chat-GPT-Image-Jun-18-2026-07-58-22-AM.png" />
    <link rel="shortcut icon" href="https://i.ibb.co/FkJPMZ8r/Chat-GPT-Image-Jun-18-2026-07-58-22-AM.png" />
    <title>PDF to Word Converter | ProToolss</title>
    <meta name="description" content="Convert PDF to editable Word documents instantly. Free and secure." />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            scroll-behavior: smooth;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: #f8f9fc;
            color: #1a1a1a;
            line-height: 1.6;
            padding-top: 80px;
            min-height: 100vh;
        }

        .container {
            max-width: 1280px;
            margin: 0 auto;
            padding: 0 28px;
        }

        nav {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 999;
            padding: 14px 0;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(0, 0, 0, 0.04);
            box-shadow: 0 1px 20px rgba(0, 0, 0, 0.03);
            transition: all 0.4s cubic-bezier(0.2, 0.9, 0.3, 1.2);
        }
        nav.scrolled {
            padding: 10px 0;
            background: rgba(255, 255, 255, 0.98);
            box-shadow: 0 2px 30px rgba(0, 0, 0, 0.06);
        }
        .nav-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            max-width: 1280px;
            margin: 0 auto;
            padding: 0 20px;
            position: relative;
            flex-wrap: nowrap;
        }
        .nav-left {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
            min-width: 0;
            justify-content: space-between;
        }
        .hamburger {
            display: block !important;
            font-size: 1.6rem;
            color: #333;
            cursor: pointer;
            transition: all 0.3s ease;
            padding: 6px 8px;
            background: transparent;
            border: none;
            border-radius: 8px;
            line-height: 1;
            flex-shrink: 0;
            order: 2;
            margin-left: auto;
        }
        .hamburger:hover {
            color: #cc0000;
            background: rgba(204, 0, 0, 0.06);
        }
        .logo {
            font-size: 1.5rem;
            font-weight: 700;
            letter-spacing: -0.3px;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            transition: all 0.3s ease;
            white-space: nowrap;
            flex-shrink: 1;
            min-width: 0;
            margin-right: auto;
        }
        .logo .pro {
            color: #cc0000;
        }
        .logo .toolss {
            color: #1a2a6c;
        }
        .logo-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            object-fit: cover;
            flex-shrink: 0;
        }
        .nav-center {
            display: none !important;
        }
        .nav-links {
            display: none !important;
        }
        .nav-links-mobile {
            display: none;
            flex-direction: column;
            width: 100%;
            gap: 0.6rem;
            padding: 16px 20px 12px;
            border-top: 1px solid rgba(0, 0, 0, 0.06);
            margin-top: 10px;
            background: rgba(255, 255, 255, 0.98);
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.08);
            border-radius: 0 0 16px 16px;
        }
        .nav-links-mobile.show {
            display: flex;
        }
        .nav-links-mobile a {
            padding: 8px 0;
            font-size: 0.95rem;
            width: 100%;
            border-bottom: 1px solid rgba(0, 0, 0, 0.03);
            white-space: normal;
            color: #444;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: 0.3s;
        }
        .nav-links-mobile a:last-child {
            border-bottom: none;
        }
        .nav-links-mobile a i {
            width: 24px;
            text-align: center;
            flex-shrink: 0;
            color: #cc0000;
        }
        .nav-links-mobile a.active {
            color: #cc0000;
        }
        .nav-links-mobile a:hover {
            color: #cc0000;
            padding-left: 8px;
        }

        footer {
            margin-top: 60px;
            padding: 30px 0;
            background: linear-gradient(145deg, #1a1a2e, #16213e);
            color: #e0e0e0;
            text-align: center;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
        }
        .footer-content {
            max-width: 1280px;
            margin: 0 auto;
            padding: 0 20px;
        }
        .footer-powered {
            font-size: 1rem;
            color: #aaaaaa;
            font-weight: 300;
            letter-spacing: 0.5px;
        }
        .footer-powered a {
            color: #ff6b6b;
            text-decoration: none;
            font-weight: 600;
            transition: 0.3s;
        }
        .footer-powered a:hover {
            color: #ff4444;
            text-decoration: underline;
        }
        .footer-powered i {
            color: #ff6b6b;
            margin: 0 6px;
        }

        @media (max-width: 768px) {
            body { padding-top: 68px; }
            nav { padding: 10px 0; }
            .nav-container { padding: 0 14px; }
            .nav-left { gap: 8px; }
            .hamburger { font-size: 1.5rem; padding: 4px 6px; }
            .logo { font-size: 1.2rem; gap: 6px; }
            .logo-icon { width: 28px; height: 28px; }
            .nav-links-mobile { padding: 12px 16px 10px; gap: 0.4rem; }
            .nav-links-mobile a { font-size: 0.85rem; padding: 6px 0; }
            .nav-links-mobile a i { width: 20px; font-size: 0.8rem; }
            footer { padding: 20px 0; }
            .footer-powered { font-size: 0.9rem; }
        }
        @media (max-width: 480px) {
            body { padding-top: 62px; }
            nav { padding: 8px 0; }
            .nav-container { padding: 0 12px; }
            .nav-left { gap: 6px; }
            .hamburger { font-size: 1.3rem; padding: 3px 5px; }
            .logo { font-size: 1rem; gap: 4px; }
            .logo-icon { width: 24px; height: 24px; }
            .nav-links-mobile { padding: 12px 16px 10px; gap: 0.4rem; }
            .nav-links-mobile a { font-size: 0.85rem; padding: 6px 0; }
            .nav-links-mobile a i { width: 20px; font-size: 0.8rem; }
            .footer-powered { font-size: 0.8rem; }
        }
        @media (max-width: 360px) {
            .logo { font-size: 0.85rem; gap: 3px; }
            .logo-icon { width: 20px; height: 20px; }
            .hamburger { font-size: 1.1rem; padding: 2px 4px; }
            .nav-container { padding: 0 8px; }
        }
        @media (min-width: 769px) {
            .nav-left { flex: 1; justify-content: space-between; }
            .hamburger { display: block !important; font-size: 1.8rem; padding: 8px 12px; }
            .nav-links-mobile { max-width: 300px; right: 0; left: auto; border-radius: 0 0 16px 16px; }
        }

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
        body {
            background: var(--bg-color);
            color: var(--text-color);
            transition: background var(--transition), color var(--transition);
        }
        .app-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 0 20px;
        }
        .card {
            background: var(--card-bg);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 2rem 1.5rem;
            border: 1px solid var(--border-color);
            transition: background var(--transition), border var(--transition), box-shadow var(--transition);
            margin-bottom: 1.5rem;
        }
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
            display: none;
            flex-wrap: wrap;
            gap: 1rem;
            margin-top: 1.5rem;
            justify-content: center;
        }
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
        .theme-toggle {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 50px;
            padding: 0.5rem 1rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--text-color);
            transition: background var(--transition);
            font-size: 0.9rem;
            margin-left: 0.5rem;
        }
        .theme-toggle:hover {
            background: var(--drop-bg);
        }
        .theme-toggle i {
            font-size: 1.1rem;
        }
        .header-tools {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        @media (max-width: 600px) {
            .card { padding: 1.5rem 1rem; }
            .drop-zone { padding: 1.5rem 0.5rem; }
            .action-group .btn { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>

<nav id="mainNav">
    <div class="nav-container">
        <div class="nav-left">
            <a href="index.php" class="logo">
                <img src="https://i.ibb.co/FkJPMZ8r/Chat-GPT-Image-Jun-18-2026-07-58-22-AM.png" alt="Pro Toolss Logo" class="logo-icon" />
                <span class="pro">Pro</span><span class="toolss">Toolss</span>
            </a>
            <button class="hamburger" id="hamburgerBtn" aria-label="Toggle menu">
                <i class="fas fa-bars"></i>
            </button>
        </div>
        <div class="nav-center" style="display:none !important;"></div>
        <div class="nav-links-mobile" id="navLinksMobile">
            <a href="index.php" class="active"><i class="fas fa-home"></i> Home</a>
        </div>
    </div>
</nav>

<div class="app-container">
    <div class="card">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem; flex-wrap:wrap; gap:0.5rem;">
            <h2 style="font-weight:600;">Upload your PDF</h2>
            <button class="theme-toggle" id="themeToggle" aria-label="Toggle dark mode">
                <i class="fas fa-moon"></i> <span id="themeLabel">Dark</span>
            </button>
        </div>

        <div class="drop-zone" id="dropZone">
            <i class="fas fa-cloud-upload-alt"></i>
            <p><strong>Drag &amp; drop</strong> your PDF here</p>
            <p class="file-info">or click to browse (max 100 MB)</p>
            <input type="file" id="fileInput" accept=".pdf,application/pdf" />
        </div>

        <div class="progress-wrapper" id="progressWrapper">
            <div class="progress-bar-bg">
                <div class="progress-bar" id="progressBar" style="width:0%;"></div>
            </div>
            <div class="progress-text">
                <span id="progressPercent">0%</span>
                <span id="progressStatus">Uploading...</span>
            </div>
        </div>

        <div class="preview-section" id="previewSection">
            <h3 style="margin-bottom:0.5rem; font-weight:500;">Preview</h3>
            <iframe id="pdfPreview" src="" title="PDF Preview"></iframe>
        </div>

        <div class="action-group" id="actionGroup">
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

        <div id="message" class="message"></div>
    </div>
</div>

<footer>
    <div class="footer-content">
        <div class="footer-powered">
            <i class=""></i> Powered by
            <a href="https://www.protoolss.online" target="_blank" rel="noopener noreferrer">www.protoolss.online</a>
            <i class="" style="color: #ff6b6b;"></i>
        </div>
    </div>
</footer>

<script>
    (function() {
        'use strict';

        document.addEventListener('DOMContentLoaded', function() {

            const nav = document.getElementById('mainNav');
            window.addEventListener('scroll', function() {
                if (window.scrollY > 50) nav.classList.add('scrolled');
                else nav.classList.remove('scrolled');
            });

            const hamburger = document.getElementById('hamburgerBtn');
            const navLinksMobile = document.getElementById('navLinksMobile');

            hamburger.addEventListener('click', function(e) {
                e.stopPropagation();
                navLinksMobile.classList.toggle('show');
                const icon = this.querySelector('i');
                icon.classList.toggle('fa-bars');
                icon.classList.toggle('fa-times');
            });

            document.querySelectorAll('#navLinksMobile a').forEach(link => {
                link.addEventListener('click', function() {
                    navLinksMobile.classList.remove('show');
                    const icon = hamburger.querySelector('i');
                    icon.classList.add('fa-bars');
                    icon.classList.remove('fa-times');
                });
            });

            document.addEventListener('click', function(e) {
                if (!e.target.closest('nav') && navLinksMobile.classList.contains('show')) {
                    navLinksMobile.classList.remove('show');
                    const icon = hamburger.querySelector('i');
                    icon.classList.add('fa-bars');
                    icon.classList.remove('fa-times');
                }
            });

            const themeToggle = document.getElementById('themeToggle');
            const themeLabel = document.getElementById('themeLabel');

            function setTheme(theme) {
                document.documentElement.setAttribute('data-theme', theme);
                localStorage.setItem('theme', theme);
                themeLabel.textContent = theme === 'dark' ? 'Light' : 'Dark';
                themeToggle.querySelector('i').className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
            }
            const savedTheme = localStorage.getItem('theme') || 'light';
            setTheme(savedTheme);
            themeToggle.addEventListener('click', () => {
                const current = document.documentElement.getAttribute('data-theme');
                setTheme(current === 'dark' ? 'light' : 'dark');
            });

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

            let currentFile = null;
            let downloadUrl = null;
            let previewUrl = null;

            function showMessage(text, type) {
                messageEl.textContent = text;
                messageEl.className = 'message';
                if (type) {
                    messageEl.classList.add(type);
                }
                messageEl.style.display = 'block';
            }

            function hideMessage() {
                messageEl.className = 'message';
                messageEl.textContent = '';
                messageEl.style.display = 'none';
            }

            function resetUI() {
                hideMessage();
                progressWrapper.style.display = 'none';
                progressBar.style.width = '0%';
                progressPercent.textContent = '0%';
                progressStatus.textContent = 'Uploading...';
                previewSection.style.display = 'none';
                pdfPreview.src = '';
                actionGroup.style.display = 'none';
                downloadBtn.style.display = 'none';
                convertBtn.disabled = true;
                dropZone.querySelector('.file-info').textContent = 'or click to browse (max 100 MB)';
                fileInput.value = '';
                currentFile = null;
                downloadUrl = null;
                previewUrl = null;
            }

            function handleFile(file) {
                if (!file) return;
                if (file.type !== 'application/pdf' && !file.name.toLowerCase().endsWith('.pdf')) {
                    showMessage('Please select a valid PDF file.', 'error');
                    return;
                }
                if (file.size > 100 * 1024 * 1024) {
                    showMessage('File exceeds 100 MB limit.', 'error');
                    return;
                }
                hideMessage();
                const info = dropZone.querySelector('.file-info');
                info.textContent = '📄 ' + file.name + ' (' + (file.size / 1024 / 1024).toFixed(2) + ' MB)';
                currentFile = file;
                uploadFile(file);
            }

            function uploadFile(file) {
                const formData = new FormData();
                formData.append('pdf_file', file);

                const xhr = new XMLHttpRequest();
                xhr.open('POST', window.location.href, true);

                xhr.upload.addEventListener('progress', function(e) {
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
                    actionGroup.style.display = 'none';
                    downloadBtn.style.display = 'none';
                    showMessage('Uploading...', '');
                };

                xhr.onload = function() {
                    if (xhr.status === 200) {
                        try {
                            const resp = JSON.parse(xhr.responseText);
                            if (resp.success) {
                                downloadUrl = resp.download_url;
                                previewUrl = resp.preview_url;
                                pdfPreview.src = previewUrl;
                                previewSection.style.display = 'block';
                                convertBtn.disabled = false;
                                downloadBtn.href = downloadUrl;
                                downloadBtn.style.display = 'inline-flex';
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

            dropZone.addEventListener('click', function(e) {
                if (e.target.tagName !== 'INPUT') {
                    fileInput.click();
                }
            });

            fileInput.addEventListener('change', function(e) {
                if (e.target.files.length > 0) {
                    handleFile(e.target.files[0]);
                }
            });

            dropZone.addEventListener('dragover', function(e) {
                e.preventDefault();
                dropZone.classList.add('dragover');
            });
            dropZone.addEventListener('dragleave', function() {
                dropZone.classList.remove('dragover');
            });
            dropZone.addEventListener('drop', function(e) {
                e.preventDefault();
                dropZone.classList.remove('dragover');
                if (e.dataTransfer.files.length > 0) {
                    handleFile(e.dataTransfer.files[0]);
                }
            });

            convertBtn.addEventListener('click', function() {
                if (downloadUrl) {
                    window.location.href = downloadUrl;
                } else {
                    showMessage('No converted file available.', 'error');
                }
            });

            resetBtn.addEventListener('click', function() {
                resetUI();
                dropZone.querySelector('.file-info').textContent = 'or click to browse (max 100 MB)';
                hideMessage();
            });

            resetUI();

        });
    })();
</script>

</body>
</html>
