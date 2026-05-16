<?php
// ============================================================
// import_members.php — One-time member CSV importer
// ============================================================
require_once __DIR__ . '/auth.php';
hm_require_login();
if (!hm_is_admin()) { echo '<h2>Admin access required.</h2>'; exit; }

require_once __DIR__ . '/db_config.php';

$message = '';
$preview = [];
$imported = 0;
$skipped  = 0;
$errors   = [];

// ── Handle upload ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file']['tmp_name'];
    if (!$file) { $message = 'error:No file uploaded.'; }
    else {
        $handle = fopen($file, 'r');
        $header = fgetcsv($handle); // skip header row

        // Normalize header to lowercase for flexible matching
        $header = array_map('strtolower', array_map('trim', $header));

        // Find column positions
        $col_name    = array_search('name', $header);
        $col_company = array_search('company', $header) !== false ? array_search('company', $header) : array_search('company name', $header);
        $col_cat     = array_search('category', $header);
        $col_mobile  = array_search('mobile number', $header) !== false ? array_search('mobile number', $header) : array_search('mobile', $header);
        $col_email   = array_search('email', $header);

        if ($col_name === false) {
            $message = 'error:CSV must have a "Name" column.';
        } else {
            $insert = $pdo->prepare("
                INSERT INTO members (name, company_name, category, mobile, email, status)
                VALUES (?, ?, ?, ?, ?, 'Active')
                ON DUPLICATE KEY UPDATE
                    company_name = VALUES(company_name),
                    category     = VALUES(category),
                    mobile       = VALUES(mobile),
                    email        = VALUES(email),
                    status       = 'Active'
            ");

            while (($row = fgetcsv($handle)) !== false) {
                $name    = trim($row[$col_name]                                  ?? '');
                $company = ($col_company !== false) ? trim($row[$col_company] ?? '') : '';
                $cat     = ($col_cat     !== false) ? trim($row[$col_cat]     ?? '') : '';
                $mobile  = ($col_mobile  !== false) ? trim($row[$col_mobile]  ?? '') : '';
                $email   = ($col_email   !== false) ? trim($row[$col_email]   ?? '') : '';

                if (!$name) { $skipped++; continue; }

                // Normalize mobile: remove spaces, leading 91, etc.
                $mobile = preg_replace('/\s+/', '', $mobile);
                if (strlen($mobile) === 12 && substr($mobile, 0, 2) === '91') {
                    $mobile = substr($mobile, 2);
                }

                try {
                    $insert->execute([$name, $company, $cat, $mobile, $email]);
                    $imported++;
                    $preview[] = ['name'=>$name,'company'=>$company,'cat'=>$cat,'mobile'=>$mobile,'email'=>$email];
                } catch (PDOException $e) {
                    $errors[] = "Row '$name': " . $e->getMessage();
                    $skipped++;
                }
            }
            fclose($handle);
            $message = "success:Imported $imported members. Skipped $skipped rows.";
        }
    }
}

// ── Current member count ───────────────────────────────────────────────────────
$total_now = (int)$pdo->query("SELECT COUNT(*) FROM members WHERE status='Active'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Members - Miracle Morning</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        body { background: #f8f9fa; font-family: 'Segoe UI', sans-serif; }
        .upload-area { border: 2px dashed #d90429; border-radius: 10px; padding: 30px; text-align: center; background: #fff8f8; cursor: pointer; transition: all .2s; }
        .upload-area:hover { background: #ffe8e8; }
        .step-badge { background: #d90429; color: white; border-radius: 50%; width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center; font-weight: 700; font-size: .8rem; margin-right: 8px; }
    </style>
</head>
<body>
<div class="container mt-4 mb-5" style="max-width:720px;">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 style="color:#d90429;font-weight:800;">Miracle Morning — Import Members</h4>
            <p class="text-muted mb-0">Currently <strong><?= $total_now ?></strong> active members in database</p>
        </div>
        <a href="admin_dashboard.php" class="btn btn-sm btn-outline-dark">← Dashboard</a>
    </div>

    <?php if ($message): 
        [$type, $text] = explode(':', $message, 2);
    ?>
    <div class="alert alert-<?= $type==='success'?'success':'danger' ?> mb-3">
        <?= htmlspecialchars($text) ?>
        <?php if ($errors): ?>
            <ul class="mb-0 mt-2 small">
                <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Steps -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <p class="fw-bold mb-3">How to import:</p>
            <p><span class="step-badge">1</span>Open your Excel file → <strong>File → Save As → CSV (Comma delimited)</strong></p>
            <p><span class="step-badge">2</span>Make sure the first row has these exact headers (case-insensitive):</p>
            <div class="bg-light rounded p-2 mb-3 font-monospace" style="font-size:.82rem;">
                Name, Company, Category, Mobile Number, Email
            </div>
            <p class="mb-0"><span class="step-badge">3</span>Upload the CSV below. Existing members will be updated, not duplicated.</p>
        </div>
    </div>

    <!-- Upload form -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data">
                <div class="upload-area mb-3" onclick="document.getElementById('csv_file').click()">
                    <div style="font-size:2rem;">📄</div>
                    <div class="fw-bold mt-2">Click to select CSV file</div>
                    <div class="text-muted small" id="file_label">No file chosen</div>
                    <input type="file" name="csv_file" id="csv_file" accept=".csv" class="d-none" onchange="document.getElementById('file_label').textContent=this.files[0].name">
                </div>
                <button type="submit" class="btn btn-danger w-100 fw-bold py-2">IMPORT MEMBERS</button>
            </form>
        </div>
    </div>

    <?php if ($preview): ?>
    <!-- Preview of imported -->
    <div class="card shadow-sm">
        <div class="card-header fw-bold bg-success text-white">✓ Imported <?= count($preview) ?> members</div>
        <div class="card-body p-0" style="max-height:400px;overflow-y:auto;">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr><th>#</th><th>Name</th><th>Company</th><th>Category</th><th>Mobile</th><th>Email</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($preview as $i => $m): ?>
                    <tr>
                        <td class="text-muted"><?= $i+1 ?></td>
                        <td><strong><?= htmlspecialchars($m['name']) ?></strong></td>
                        <td><?= htmlspecialchars($m['company']) ?></td>
                        <td><?= htmlspecialchars($m['cat']) ?></td>
                        <td><?= htmlspecialchars($m['mobile']) ?></td>
                        <td class="text-muted"><?= htmlspecialchars($m['email']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Existing members quick view -->
    <?php if (!$preview): 
        $existing = $pdo->query("SELECT name, company_name, category, mobile, status FROM members ORDER BY name ASC")->fetchAll();
        if ($existing):
    ?>
    <div class="card shadow-sm">
        <div class="card-header fw-bold">Current members in DB (<?= count($existing) ?>)</div>
        <div class="card-body p-0" style="max-height:350px;overflow-y:auto;">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light"><tr><th>#</th><th>Name</th><th>Company</th><th>Category</th><th>Mobile</th><th>Status</th></tr></thead>
                <tbody>
                    <?php foreach ($existing as $i => $m): ?>
                    <tr>
                        <td class="text-muted"><?= $i+1 ?></td>
                        <td><strong><?= htmlspecialchars($m['name']) ?></strong></td>
                        <td><?= htmlspecialchars($m['company_name']??'') ?></td>
                        <td><?= htmlspecialchars($m['category']??'') ?></td>
                        <td><?= htmlspecialchars($m['mobile']??'') ?></td>
                        <td><span class="badge bg-<?= $m['status']==='Active'?'success':'secondary' ?>"><?= $m['status'] ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; endif; ?>

</div>
<div class="text-center py-3">
    <img src="image/powerbi.png" alt="PowerBI" style="height:32px;width:auto;max-width:150px;object-fit:contain;opacity:0.8;display:inline-block;">
</div>
</body>
</html>
