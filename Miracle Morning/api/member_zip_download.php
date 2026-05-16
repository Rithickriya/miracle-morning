<?php
// member_zip_download.php - Generate ZIP with individual PDF reports for selected members

require_once __DIR__ . '/auth.php';
hm_require_login();

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Mpdf\Mpdf;

// Get parameters
$ids = $_GET['ids'] ?? '';
$idArr = array_filter(array_map('intval', explode(',', $ids)));

if (empty($idArr)) {
    die('No valid member IDs.');
}

// Check ZIP extension
if (!class_exists('ZipArchive')) {
    die('ZIP extension is not enabled on this server.');
}

// Create temporary ZIP file
$zip = new ZipArchive();
$zipName = 'member_reports_' . date('Ymd_His') . '.zip';
$tmpZip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $zipName;

if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    die('Could not create ZIP file.');
}

foreach ($idArr as $mid) {
    // Fetch member name for PDF filename
    $stmt = $pdo->prepare("SELECT name FROM members WHERE id = ?");
    $stmt->execute([$mid]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$member) {
        continue;
    }

    $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $member['name']);
    $fileName = $safeName . '_' . $mid . '.pdf';

    try {
        // Backup current GET values
        $oldGet = $_GET;

        // Set member id for included report page
        $_GET['id'] = $mid;

        // Capture member report HTML directly
        ob_start();
       include __DIR__ . '/member_report_pdf.php';
        $html = ob_get_clean();

        // Restore GET values
        $_GET = $oldGet;

        // If report returned empty HTML
        if (empty(trim($html))) {
            $html = '<html><body><h1>No Data</h1><p>No report content found for ' . htmlspecialchars($member['name']) . '.</p></body></html>';
        }

        // Remove scripts
        $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);

        // Remove print-related onload if present
        $html = preg_replace('/onload\s*=\s*"[^"]*"/i', '', $html);
        $html = preg_replace("/onload\s*=\s*'[^']*'/i", '', $html);

        // Generate PDF
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_top' => 10,
            'margin_bottom' => 10,
            'margin_left' => 12,
            'margin_right' => 12,
            'tempDir' => sys_get_temp_dir()
        ]);

        $mpdf->WriteHTML($html);
        $pdfData = $mpdf->Output('', 'S');

        $zip->addFromString($fileName, $pdfData);

    } catch (Throwable $e) {
        error_log("PDF generation error for member ID {$mid}: " . $e->getMessage());

        // Add fallback error PDF
        try {
            $mpdf = new Mpdf([
                'mode' => 'utf-8',
                'format' => 'A4',
                'tempDir' => sys_get_temp_dir()
            ]);

            $errorHtml = '
                <html>
                <body>
                    <h1>PDF Generation Error</h1>
                    <p>Could not generate report for: ' . htmlspecialchars($member['name']) . '</p>
                    <p>Member ID: ' . (int)$mid . '</p>
                </body>
                </html>
            ';

            $mpdf->WriteHTML($errorHtml);
            $zip->addFromString($fileName, $mpdf->Output('', 'S'));
        } catch (Throwable $inner) {
            error_log("Fallback PDF also failed for member ID {$mid}: " . $inner->getMessage());
        }
    }
}

$zip->close();

// Clean output buffer if anything was printed before headers
while (ob_get_level()) {
    ob_end_clean();
}

// Send ZIP file
if (!file_exists($tmpZip)) {
    die('ZIP file was not created.');
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipName . '"');
header('Content-Length: ' . filesize($tmpZip));
header('Pragma: no-cache');
header('Expires: 0');

readfile($tmpZip);
unlink($tmpZip);
exit;