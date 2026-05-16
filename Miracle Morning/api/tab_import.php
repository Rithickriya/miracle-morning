<?php
// tab_import.php — Import tab wrapper for dashboard
// Processes CSV upload and shows import UI inside dashboard

$import_message = '';
$import_preview = [];
$import_errors  = [];
$visitor_import_message = '';
$visitor_import_preview = [];
$visitor_import_errors  = [];

function visitor_import_next_Sunday(): string {
    $d = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
    return (int)$d->format('N') === 7 ? $d->format('Y-m-d') : (clone $d)->modify('next sunday')->format('Y-m-d');
}

function visitor_import_header_index(array $header, array $names) {
    foreach ($names as $name) {
        $idx = array_search($name, $header);
        if ($idx !== false) return $idx;
    }
    return false;
}

function visitor_import_read_rows(string $file): array {
    $raw = file_get_contents($file);
    if ($raw === false || trim($raw) === '') return [];

    if (stripos($raw, '<table') !== false || stripos($raw, '<tr') !== false) {
        $rows = [];
        if (preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/is', $raw, $trMatches)) {
            foreach ($trMatches[1] as $tr) {
                $cells = [];
                if (preg_match_all('/<t[dh]\b[^>]*>(.*?)<\/t[dh]>/is', $tr, $tdMatches)) {
                    foreach ($tdMatches[1] as $cell) {
                        $cells[] = trim(html_entity_decode(strip_tags($cell), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    }
                }
                if ($cells) $rows[] = $cells;
            }
        }
        return $rows;
    }

    $lines = preg_split("/\r\n|\n|\r/", $raw);
    $rows = [];
    foreach ($lines as $line) {
        if (trim($line) === '') continue;
        $rows[] = str_getcsv($line);
    }
    return $rows;
}

require_once __DIR__ . '/name_match_helper.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['visitor_file']['tmp_name'])) {
    $rows = visitor_import_read_rows($_FILES['visitor_file']['tmp_name']);
    if (count($rows) < 2) {
        $visitor_import_message = 'error:Visitor import file is empty or has no data rows.';
    } else {
        $header = array_map(function($h){
            return strtolower(trim(str_replace(["\xEF\xBB\xBF", '"'], '', $h)));
        }, $rows[0]);

        $nameIdx = visitor_import_header_index($header, ['visitor name', 'name']);
        $mobileIdx = visitor_import_header_index($header, ['mobile', 'mobile number', 'phone']);
        $emailIdx = visitor_import_header_index($header, ['email', 'email address']);
        $companyIdx = visitor_import_header_index($header, ['company', 'company name']);
        $professionIdx = visitor_import_header_index($header, ['profession', 'category', 'business category']);
        $referrerIdx = visitor_import_header_index($header, ['invited by', 'referrer', 'referrer name', 'member name']);
        $amountIdx = visitor_import_header_index($header, ['amount', 'paid amount', 'fee']);
        $methodIdx = visitor_import_header_index($header, ['payment method', 'method', 'mode']);
        $dateIdx = visitor_import_header_index($header, ['meeting date', 'Sunday Date', 'date']);

        if ($nameIdx === false || $mobileIdx === false || $referrerIdx === false) {
            $visitor_import_message = 'error:Missing required columns. Need Visitor Name, Mobile, and Invited By.';
        } else {
            $insertVisitor = $pdo->prepare("
                INSERT INTO transactions
                    (member_id, visitor_name, visitor_mobile, visitor_email, visitor_company,
                     visitor_profession, referrer_name, type, amount,
                     payment_method, friday_date, status, submitted_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'Visitor', ?, ?, ?, 'Pending', NOW())
            ");

            $imported = 0;
            $skipped = 0;
            $defaultSunday = visitor_import_next_Sunday();
            for ($i = 1; $i < count($rows); $i++) {
                $r = $rows[$i];
                $name = trim($r[$nameIdx] ?? '');
                $mobile = trim($r[$mobileIdx] ?? '');
                $referrer = trim($r[$referrerIdx] ?? '');
                if ($name === '' && $mobile === '' && $referrer === '') { $skipped++; continue; }
                if ($name === '' || $mobile === '' || $referrer === '') {
                    $visitor_import_errors[] = 'Row '.($i + 1).': Visitor Name, Mobile and Invited By are required.';
                    $skipped++;
                    continue;
                }

                $email = $emailIdx !== false ? trim($r[$emailIdx] ?? '') : '';
                $company = $companyIdx !== false ? trim($r[$companyIdx] ?? '') : '';
                $profession = $professionIdx !== false ? trim($r[$professionIdx] ?? '') : '';
                $amount = $amountIdx !== false ? (int)preg_replace('/[^\d]/', '', $r[$amountIdx] ?? '') : 1450;
                if ($amount <= 0) $amount = 1450;
                $method = $methodIdx !== false ? trim($r[$methodIdx] ?? '') : 'Cash';
                if ($method === '') $method = 'Cash';
                $matchedMember = hm_find_member_by_name($pdo, $referrer);
                $memberId = $matchedMember ? (int)$matchedMember['id'] : null;
                $referrerToStore = $matchedMember ? $matchedMember['name'] : $referrer;
                $Sunday = $defaultSunday;
                if ($dateIdx !== false && !empty($r[$dateIdx])) {
                    $dt = DateTime::createFromFormat('Y-m-d', trim($r[$dateIdx]));
                    if (!$dt) $dt = DateTime::createFromFormat('d-m-Y', trim($r[$dateIdx]));
                    if (!$dt) $dt = DateTime::createFromFormat('d/m/Y', trim($r[$dateIdx]));
                    if ($dt) $Sunday = $dt->format('Y-m-d');
                }

                try {
                    $insertVisitor->execute([$memberId, $name, $mobile, $email, $company, $profession, $referrerToStore, $amount, $method, $Sunday]);
                    $visitor_import_preview[] = [
                        'name' => $name,
                        'mobile' => $mobile,
                        'company' => $company,
                        'profession' => $profession,
                        'referrer' => $referrerToStore,
                        'amount' => $amount,
                        'method' => $method,
                        'date' => $Sunday,
                    ];
                    $imported++;
                } catch (Exception $e) {
                    $visitor_import_errors[] = "Row ".($i + 1)." '$name': " . $e->getMessage();
                    $skipped++;
                }
            }
            $visitor_import_message = "success:Imported $imported visitors into Verification Queue" . ($skipped ? ", skipped $skipped rows" : "");
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['csv_file']['tmp_name'])) {
    $file = $_FILES['csv_file']['tmp_name'];
    $rows = array_map('str_getcsv', file($file));
    if (count($rows) < 2) {
        $import_message = 'error:CSV file is empty or has no data rows.';
    } else {
        $header = array_map(function($h){ return strtolower(trim(str_replace(["\xEF\xBB\xBF",'"'],'',$h))); }, $rows[0]);
        $ni = array_search('name', $header);
        $ci = array_search('company', $header) !== false ? array_search('company', $header) : array_search('company_name', $header);
        $ki = array_search('category', $header);
        $mi = array_search('mobile number', $header) !== false ? array_search('mobile number', $header) : array_search('mobile', $header);
        $ei = array_search('email', $header);

        if ($ni === false) {
            $import_message = 'error:Missing "Name" column in CSV header.';
        } else {
            $imported = 0; $skipped = 0;
            $insert = $pdo->prepare("INSERT INTO members (name, company_name, category, mobile, email, status) VALUES (?, ?, ?, ?, ?, 'Active') ON DUPLICATE KEY UPDATE company_name=VALUES(company_name), category=VALUES(category), mobile=VALUES(mobile), email=VALUES(email)");
            
            for ($i = 1; $i < count($rows); $i++) {
                $r = $rows[$i];
                $name = trim($r[$ni] ?? '');
                if (!$name) { $skipped++; continue; }
                $company  = trim($r[$ci] ?? '');
                $category = trim($r[$ki] ?? '');
                $mobile   = trim($r[$mi] ?? '');
                $email    = trim($r[$ei] ?? '');
                try {
                    $insert->execute([$name, $company, $category, $mobile, $email]);
                    $import_preview[] = ['name'=>$name,'company'=>$company,'cat'=>$category,'mobile'=>$mobile,'email'=>$email];
                    $imported++;
                } catch (Exception $e) {
                    $import_errors[] = "Row '$name': " . $e->getMessage();
                }
            }
            $import_message = "success:Imported $imported members" . ($skipped ? ", skipped $skipped empty rows" : "");
        }
    }
}

$total_now = (int)$pdo->query("SELECT COUNT(*) FROM members WHERE status='Active'")->fetchColumn();
?>
<div class="content">
<div class="scard" style="max-width:920px;margin:0 auto 16px">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h5 style="color:#1565c0;font-weight:800;margin:0">Import Visitors</h5>
            <div style="font-size:.78rem;color:var(--gry)">Imported visitor rows are saved as <strong>Pending</strong> and shown in Verification Queue</div>
        </div>
    </div>

    <?php if ($visitor_import_message):
        [$vType, $vText] = explode(':', $visitor_import_message, 2);
    ?>
    <div style="padding:10px 14px;border-radius:8px;margin-bottom:12px;font-size:.82rem;font-weight:600;background:<?=$vType==='success'?'#e8f5e9;color:#1b5e20':'#ffebee;color:#c62828'?>">
        <?= htmlspecialchars($vText) ?>
        <?php if ($visitor_import_errors): ?>
            <ul style="margin:6px 0 0;padding-left:20px;font-weight:400">
                <?php foreach ($visitor_import_errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div style="background:#f8fcff;border:1px solid #bbdefb;border-radius:10px;padding:14px 16px;margin-bottom:14px;font-size:.82rem">
        <div style="font-weight:700;margin-bottom:8px">Visitor Excel import format:</div>
        <div style="margin-bottom:6px"><span style="background:#1565c0;color:#fff;border-radius:50%;width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:700;margin-right:6px">1</span>Download the sample Excel file below.</div>
        <div style="margin-bottom:6px"><span style="background:#1565c0;color:#fff;border-radius:50%;width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:700;margin-right:6px">2</span>Required headers: <code style="font-size:.78rem;background:#fff;padding:2px 6px;border-radius:4px">Visitor Name, Mobile, Invited By</code></div>
        <div><span style="background:#1565c0;color:#fff;border-radius:50%;width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:700;margin-right:6px">3</span>Optional headers: Email, Company, Profession, Amount, Payment Method, Meeting Date.</div>
        <div style="margin-top:10px;padding-top:10px;border-top:1px solid #dbeeff;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <button type="button" onclick="downloadVisitorSampleExcel()" style="background:#1565c0;color:#fff;border:none;border-radius:8px;padding:6px 14px;font-size:.78rem;font-weight:700;cursor:pointer">Download Sample Excel</button>
            <span style="font-size:.72rem;color:#777">Upload the same edited file, or a CSV with matching headers.</span>
        </div>
    </div>

    <script>
    function downloadVisitorSampleExcel() {
        var rows = [
            ['Visitor Name', 'Mobile', 'Email', 'Company', 'Profession', 'Invited By', 'Amount', 'Payment Method', 'Meeting Date'],
            ['Arun Kumar', '9876543210', 'arun@example.com', 'A One Traders', 'Automobile', 'Ramanan', '1450', 'Cash', '<?=visitor_import_next_Sunday()?>'],
            ['Meena S', '9876543211', 'meena@example.com', 'MS Designs', 'Interior Designer', 'Rithick Ramannan', '1450', 'UPI', '<?=visitor_import_next_Sunday()?>']
        ];
        var html = '<html><head><meta charset="UTF-8"></head><body><table border="1">';
        rows.forEach(function(row) {
            html += '<tr>' + row.map(function(cell) {
                return '<td style="mso-number-format:\\@">' + String(cell).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</td>';
            }).join('') + '</tr>';
        });
        html += '</table></body></html>';
        var blob = new Blob([html], { type: 'application/vnd.ms-excel;charset=utf-8;' });
        var link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'miracle_morning_visitor_import_sample.xls';
        link.click();
    }
    </script>

    <form method="POST" enctype="multipart/form-data" action="?date=<?=$sel?>&tab=import">
        <div style="border:2px dashed #bbdefb;border-radius:12px;padding:20px;text-align:center;cursor:pointer;transition:border-color .2s;margin-bottom:12px"
             onclick="document.getElementById('visitor_file').click()"
             onmouseover="this.style.borderColor='#1565c0'" onmouseout="this.style.borderColor='#bbdefb'">
            <div style="font-size:1.5rem">Import</div>
            <div style="font-weight:700;font-size:.85rem;margin-top:4px">Click to select visitor Excel / CSV file</div>
            <div style="font-size:.72rem;color:#999" id="visitor_import_file_label">No file chosen</div>
            <input type="file" name="visitor_file" id="visitor_file" accept=".xls,.csv" style="display:none" onchange="document.getElementById('visitor_import_file_label').textContent=this.files[0]?.name||'No file'">
        </div>
        <button type="submit" style="width:100%;padding:10px;background:#1565c0;color:#fff;border:none;border-radius:10px;font-size:.88rem;font-weight:700;cursor:pointer">Import Visitors to Verification Queue</button>
    </form>

    <?php if ($visitor_import_preview): ?>
    <div style="margin-top:14px">
        <div style="font-size:.75rem;font-weight:700;text-transform:uppercase;color:var(--gry);margin-bottom:6px">Sent to Verification Queue: <?= count($visitor_import_preview) ?> visitors</div>
        <div style="max-height:250px;overflow-y:auto;border:1px solid var(--bdr);border-radius:8px">
            <table class="tbl">
                <thead><tr><th>#</th><th>Visitor</th><th>Mobile</th><th>Invited By</th><th>Date</th><th>Amount</th><th>Mode</th></tr></thead>
                <tbody>
                <?php foreach ($visitor_import_preview as $i => $v): ?>
                <tr>
                    <td class="text-muted"><?=$i+1?></td>
                    <td><strong><?=htmlspecialchars($v['name'])?></strong><br><span style="font-size:.7rem;color:var(--gry)"><?=htmlspecialchars($v['company'])?></span></td>
                    <td><?=htmlspecialchars($v['mobile'])?></td>
                    <td><?=htmlspecialchars($v['referrer'])?></td>
                    <td><?=date('d M Y', strtotime($v['date']))?></td>
                    <td style="font-weight:700;color:#1565c0">₹<?=number_format($v['amount'])?></td>
                    <td><span class="badge-mode"><?=htmlspecialchars($v['method'])?></span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="scard" style="max-width:920px;margin:0 auto">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h5 style="color:var(--red);font-weight:800;margin:0">Import Members</h5>
            <div style="font-size:.78rem;color:var(--gry)">Currently <strong><?= $total_now ?></strong> active members in database</div>
        </div>
    </div>

    <?php if ($import_message): 
        [$type, $text] = explode(':', $import_message, 2);
    ?>
    <div style="padding:10px 14px;border-radius:8px;margin-bottom:12px;font-size:.82rem;font-weight:600;background:<?=$type==='success'?'#e8f5e9;color:#1b5e20':'#ffebee;color:#c62828'?>">
        <?= htmlspecialchars($text) ?>
        <?php if ($import_errors): ?>
            <ul style="margin:6px 0 0;padding-left:20px;font-weight:400">
                <?php foreach ($import_errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Steps -->
    <div style="background:#f8f8f8;border-radius:10px;padding:14px 16px;margin-bottom:14px;font-size:.82rem">
        <div style="font-weight:700;margin-bottom:8px">How to import:</div>
        <div style="margin-bottom:6px"><span style="background:var(--red);color:#fff;border-radius:50%;width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:700;margin-right:6px">1</span>Open your Excel → <strong>File → Save As → CSV</strong></div>
        <div style="margin-bottom:6px"><span style="background:var(--red);color:#fff;border-radius:50%;width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:700;margin-right:6px">2</span>First row headers: <code style="font-size:.78rem;background:#fff;padding:2px 6px;border-radius:4px">Name, Company, Category, Mobile Number, Email</code></div>
        <div><span style="background:var(--red);color:#fff;border-radius:50%;width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:700;margin-right:6px">3</span>Upload below. Existing members updated, not duplicated.</div>
        <div style="margin-top:10px;padding-top:10px;border-top:1px solid #e8e8e8;display:flex;align-items:center;gap:10px">
            <button type="button" onclick="downloadSampleCSV()" style="background:#1b5e20;color:#fff;border:none;border-radius:8px;padding:6px 14px;font-size:.78rem;font-weight:700;cursor:pointer">⬇ Download Sample CSV</button>
            <span style="font-size:.72rem;color:#999">Use this as a template for your import file</span>
        </div>
    </div>

    <script>
    function downloadSampleCSV() {
        var rows = [
            ['Name', 'Company', 'Category', 'Mobile Number', 'Email'],
            ['John Smith', 'ABC Solutions', 'IT Services', '9876543210', 'john@abc.com'],
            ['Priya Raj', 'Raj Enterprises', 'Textile', '9876543211', 'priya@raj.com'],
            ['Karthik M', 'MK Builders', 'Construction', '9876543212', 'karthik@mk.com'],
            ['Lakshmi S', 'LS Consulting', 'Financial Advisor', '9876543213', 'lakshmi@ls.com'],
            ['Rajesh Kumar', 'Kumar Motors', 'Automobile', '9876543214', 'rajesh@km.com']
        ];
        var csv = rows.map(function(r){ return r.map(function(c){ return '"'+c.replace(/"/g,'""')+'"'; }).join(','); }).join('\n');
        var blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
        var link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'miracle_morning_members_sample.csv';
        link.click();
    }
    </script>

    <!-- Upload form -->
    <form method="POST" enctype="multipart/form-data" action="?date=<?=$sel?>&tab=import">
        <div style="border:2px dashed var(--bdr);border-radius:12px;padding:20px;text-align:center;cursor:pointer;transition:border-color .2s;margin-bottom:12px"
             onclick="document.getElementById('csv_file').click()"
             onmouseover="this.style.borderColor='var(--red)'" onmouseout="this.style.borderColor='var(--bdr)'">
            <div style="font-size:1.5rem">📄</div>
            <div style="font-weight:700;font-size:.85rem;margin-top:4px">Click to select CSV file</div>
            <div style="font-size:.72rem;color:#bbb" id="import_file_label">No file chosen</div>
            <input type="file" name="csv_file" id="csv_file" accept=".csv" style="display:none" onchange="document.getElementById('import_file_label').textContent=this.files[0]?.name||'No file'">
        </div>
        <button type="submit" style="width:100%;padding:10px;background:var(--red);color:#fff;border:none;border-radius:10px;font-size:.88rem;font-weight:700;cursor:pointer">Import Members</button>
    </form>

    <?php if ($import_preview): ?>
    <div style="margin-top:14px">
        <div style="font-size:.75rem;font-weight:700;text-transform:uppercase;color:var(--gry);margin-bottom:6px">✓ Imported <?= count($import_preview) ?> members</div>
        <div style="max-height:250px;overflow-y:auto;border:1px solid var(--bdr);border-radius:8px">
            <table class="tbl">
                <thead><tr><th>#</th><th>Name</th><th>Company</th><th>Category</th><th>Mobile</th></tr></thead>
                <tbody>
                <?php foreach ($import_preview as $i => $m): ?>
                <tr>
                    <td class="text-muted"><?=$i+1?></td>
                    <td><strong><?=htmlspecialchars($m['name'])?></strong></td>
                    <td><?=htmlspecialchars($m['company'])?></td>
                    <td><?=htmlspecialchars($m['cat'])?></td>
                    <td><?=htmlspecialchars($m['mobile'])?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>
</div>
