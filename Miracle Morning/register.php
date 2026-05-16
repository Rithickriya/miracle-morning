<?php
require_once __DIR__ . '/api/db_config.php';

// Sunday Date
$today  = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
$Sunday = ((int)$today->format('N') === 7) ? $today : (clone $today)->modify('next sunday');
$SundayDisplay = $Sunday->format('d M Y');

// Member list for datalists
$members = [];
try {
    $ms = $pdo->query("SELECT name, company_name FROM members WHERE status='Active' ORDER BY name ASC");
    $members = $ms->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $members = []; }

// Kitty data for progress
$kittyData = [];
try {
    $kq = $pdo->query("
        SELECT m.name, m.company_name,
               COALESCE(SUM(CASE WHEN k.status='Paid' THEN k.amount ELSE 0 END),0) AS paid
        FROM members m
        LEFT JOIN kitty_payments k ON k.member_id = m.id
        WHERE m.status = 'Active'
        GROUP BY m.id, m.name, m.company_name
        ORDER BY m.name ASC
    ");
    while ($r = $kq->fetch(PDO::FETCH_ASSOC)) {
        $kittyData[$r['name'].' - '.$r['company_name']] = (int)$r['paid'];
    }
} catch (Exception $e) { $kittyData = []; }

// Visitor member list (names only)
$memberNames = array_map(fn($m) => $m['name'], $members);

// Generate next 26 Sundays
$Sundays = [];
$fd = clone $Sunday; // Start from meeting Sunday (today if Sunday, else next)
for ($i = 0; $i < 26; $i++) {
    $Sundays[] = ['val' => $fd->format('Y-m-d'), 'disp' => $fd->format('d M Y'), 'm' => $fd->format('m'), 'y' => $fd->format('Y'), 'mon' => $fd->format('M')];
    $fd->modify('+7 days');
}

// Month chips
$monthsSeen = []; $monthChips = [];
foreach ($Sundays as $f) {
    $k = $f['mon'].$f['y'];
    if (!in_array($k, $monthsSeen)) { $monthsSeen[] = $k; $monthChips[] = ['m'=>$f['m'], 'y'=>$f['y'], 'label'=>$f['mon'].' '.$f['y']]; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Miracle Morning — Registration Desk</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --red:#D90429;--rdk:#a8001f;--rlt:#fff0f2;
  --blk:#111;--gry:#666;--lite:#f2f2f2;--bdr:#e0e0e0;--wht:#fff;
}
html,body{height:100%}
body{font-family:'Inter',sans-serif;background:#e0e0e0;color:var(--blk);-webkit-font-smoothing:antialiased;font-size:16px}

/* SCREENS */
.scr{position:fixed;inset:0;overflow-y:auto;background:var(--wht);z-index:1}
.scr.off{display:none}

/* HERO */
.hero{background:var(--red);padding:34px 20px 26px;position:relative;overflow:hidden}
.hero::after{content:'';position:absolute;bottom:-50px;right:-50px;width:160px;height:160px;background:rgba(255,255,255,.08);border-radius:50%}
.live-tag{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.3);color:#fff;font-size:11px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;padding:4px 12px;border-radius:20px;margin-bottom:14px}
.ldot{width:6px;height:6px;background:#fff;border-radius:50%;animation:blink 1.6s infinite}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.3}}
.hero-title{font-size:clamp(28px,8vw,36px);font-weight:800;color:#fff;letter-spacing:-.5px;line-height:1.1;margin-bottom:4px}
.hero-sub{font-size:13px;color:rgba(255,255,255,.72)}
.chips{display:flex;gap:8px;flex-wrap:wrap;margin-top:16px}
.chip{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);color:#fff;font-size:12px;font-weight:600;padding:6px 14px;border-radius:8px}

/* HOME CARDS */
.home-body{padding:20px 16px 12px}
.sec-label{font-size:11px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:var(--gry);margin-bottom:14px}

.ecard{background:var(--wht);border:1.5px solid var(--bdr);border-radius:14px;padding:16px;display:flex;align-items:center;gap:14px;cursor:pointer;margin-bottom:12px;transition:border-color .2s,box-shadow .2s;-webkit-tap-highlight-color:transparent;user-select:none}
.ecard:active{transform:scale(.98)}
.ecard:hover{border-color:var(--red);box-shadow:0 4px 16px rgba(217,4,41,.1)}
.eicon{width:48px;height:48px;border-radius:12px;background:var(--rlt);border:1px solid #ffd6dc;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
.einfo{flex:1;min-width:0}
.ename{font-size:16px;font-weight:700;color:var(--blk)}
.edesc{font-size:12px;color:var(--gry);margin-top:2px}
.eright{text-align:right;flex-shrink:0}
.efee{font-size:16px;font-weight:700;color:var(--red)}
.earr{width:26px;height:26px;border-radius:50%;background:var(--lite);display:flex;align-items:center;justify-content:center;font-size:18px;color:var(--gry);margin-top:4px;margin-left:auto;transition:all .18s}
.ecard:hover .earr{background:var(--red);color:#fff}

.hfooter{text-align:center;padding:16px 20px 28px;border-top:1px solid var(--bdr)}
.hfooter img{height:28px;width:auto;max-width:140px;object-fit:contain;opacity:.7}

/* FORM SCREEN */
.fscr{background:#f5f5f5;min-height:100dvh;display:flex;flex-direction:column}
.fhdr{background:var(--wht);border-bottom:1.5px solid var(--bdr);position:sticky;top:0;z-index:10}
.fbar{height:4px;background:var(--red)}
.fhdr-in{display:flex;align-items:center;gap:12px;padding:12px 16px}
.back{width:36px;height:36px;background:var(--lite);border:1.5px solid var(--bdr);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px;cursor:pointer;color:var(--blk);flex-shrink:0;transition:all .18s;-webkit-tap-highlight-color:transparent;line-height:1}
.back:hover{background:var(--rlt);border-color:var(--red)}
.ftxt{flex:1}
.ftitle{font-size:16px;font-weight:700;color:var(--blk)}
.fsub{font-size:11px;color:var(--gry);margin-top:1px}
.fbadge{background:var(--rlt);color:var(--red);font-size:11px;font-weight:700;padding:4px 10px;border-radius:8px;flex-shrink:0}

.fbody{padding:14px;display:flex;flex-direction:column;gap:12px;padding-bottom:32px;flex:1}

/* Field card */
.fc{background:var(--wht);border:1.5px solid var(--bdr);border-radius:14px;overflow:visible}
.fc-hd{font-size:10px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--red);padding:10px 16px 8px;background:var(--rlt);border-bottom:1px solid #ffd6dc}
.fr{padding:12px 16px;border-bottom:1px solid var(--bdr)}
.fr:last-child{border-bottom:none}
.fr:focus-within{background:#fafafa}
.fr:focus-within label{color:var(--red)}
.fr label{display:block;font-size:11px;font-weight:700;color:var(--gry);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px}
.fr input,.fr select{width:100%;border:none;outline:none;font-family:'Inter',sans-serif;font-size:16px;font-weight:500;color:var(--blk);background:transparent;padding:0;-webkit-appearance:none}
.fr input::placeholder{color:#bbb;font-weight:400}
.fr select{background:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%23888' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E") no-repeat right 2px center;padding-right:20px}

/* Amount block */
.acard{background:var(--wht);border:1.5px solid var(--bdr);border-radius:14px;overflow:visible}
.ahd{font-size:10px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--red);padding:10px 16px 8px;background:var(--rlt);border-bottom:1px solid #ffd6dc}
.arow{display:flex;align-items:center;gap:8px;padding:14px 16px;border-bottom:1px solid var(--bdr)}
.asym{font-size:28px;font-weight:700;color:var(--red);line-height:1}
.ainp{flex:1;border:none;outline:none;font-family:'Inter',sans-serif;font-size:30px;font-weight:700;color:var(--blk);background:transparent;min-width:0}
.ainp::placeholder{color:#ddd}
.ahint{padding:8px 16px;font-size:12px;color:var(--gry);border-bottom:1px solid var(--bdr);background:#fafafa}

/* Summary grid */
.sgrid{display:grid;grid-template-columns:1fr 1fr}
.scell{padding:12px 16px;border-right:1px solid var(--bdr);border-bottom:1px solid var(--bdr)}
.scell:nth-child(even){border-right:none}
.scell:nth-child(3),.scell:nth-child(4){border-bottom:none}
.snum{font-size:18px;font-weight:700;color:var(--blk)}
.snum.red{color:var(--red)}
.snum.amb{color:#b07000}
.slbl{font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--gry);margin-top:2px}

/* Month chips */
.mcs{display:flex;gap:6px;overflow-x:auto;padding:10px 16px;border-bottom:1px solid var(--bdr);scrollbar-width:none}
.mcs::-webkit-scrollbar{display:none}
.mc{flex-shrink:0;padding:5px 12px;border-radius:20px;font-size:12px;font-weight:600;border:1.5px solid var(--bdr);background:#fff;color:var(--gry);cursor:pointer;transition:all .18s;-webkit-tap-highlight-color:transparent}
.mc:hover{border-color:var(--red);color:var(--red);background:var(--rlt)}

/* Week grid */
.whdr{display:flex;justify-content:space-between;align-items:center;padding:10px 16px;border-bottom:1px solid var(--bdr)}
.wlbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--gry)}
.wclr{font-size:12px;color:var(--gry);background:none;border:none;cursor:pointer}
.wclr:hover{color:var(--red)}
.wgrid{display:grid;grid-template-columns:1fr 1fr;max-height:260px;overflow-y:auto}
.wc{padding:11px 14px;position:relative;border-right:1px solid var(--bdr);border-bottom:1px solid var(--bdr);cursor:pointer;transition:background .15s;-webkit-tap-highlight-color:transparent}
.wc:nth-child(even){border-right:none}
.wc:hover{background:#fafafa}
.wc.full{background:#fff0f2}
.wc.part{background:#fffbee}
.wchk{position:absolute;top:8px;right:10px;width:17px;height:17px;border-radius:50%;border:1.5px solid var(--bdr);display:flex;align-items:center;justify-content:center;font-size:9px;color:transparent;transition:all .18s}
.wc.full .wchk{background:var(--red);border-color:var(--red);color:#fff}
.wc.part .wchk{background:#f5a623;border-color:#f5a623;color:#fff}
.wdate{font-size:13px;font-weight:600;color:var(--blk)}
.wstat{font-size:11px;color:var(--gry);margin-top:2px}
.wc.full .wstat{color:var(--red);font-weight:500}
.wc.part .wstat{color:#b07000;font-weight:500}

/* Fee row */
.feerow{background:var(--wht);border:1.5px solid var(--bdr);border-radius:14px;padding:16px;display:flex;justify-content:space-between;align-items:center}
.feelbl{font-size:14px;font-weight:600;color:var(--blk)}
.feesub{font-size:11px;color:var(--gry);margin-top:2px}
.feeamt{font-size:24px;font-weight:800;color:var(--red)}

/* Kitty progress */
.kpbox{background:var(--wht);border:1.5px solid var(--bdr);border-radius:14px;padding:16px;display:none}
.kpbox.show{display:block}
.ktrack{height:10px;background:#f0f0f0;border-radius:5px;margin:8px 0 6px;overflow:hidden}
.kfill{height:100%;background:var(--red);border-radius:5px;transition:width .4s}
.kplbls{display:flex;justify-content:space-between;font-size:11px;color:var(--gry);font-weight:600}

/* Quick amount buttons */
.qarow{display:flex;gap:8px;flex-wrap:wrap;padding:10px 16px;border-bottom:1px solid var(--bdr)}
.qa{padding:6px 14px;border-radius:20px;font-size:12px;font-weight:600;border:1.5px solid var(--bdr);background:#fff;color:var(--blk);cursor:pointer;transition:all .18s;-webkit-tap-highlight-color:transparent}
.qa:hover{border-color:var(--red);background:var(--rlt);color:var(--red)}

/* Submit */
.sbtn{width:100%;padding:15px;background:var(--red);color:#fff;border:none;border-radius:14px;font-family:'Inter',sans-serif;font-size:16px;font-weight:700;letter-spacing:.3px;cursor:pointer;transition:background .2s;-webkit-tap-highlight-color:transparent}
.sbtn:hover{background:var(--rdk)}
.sbtn:active{transform:scale(.98)}
.sbtn:disabled{background:#ccc;cursor:not-allowed}

/* Footer logo */
.fftr{text-align:center;padding:18px 20px 32px;border-top:1px solid var(--bdr);background:var(--wht)}
.fftr img{height:30px;width:auto;max-width:140px;object-fit:contain;opacity:.72}

/* Toast */
.toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(120px);background:var(--blk);color:#fff;padding:12px 22px;border-radius:12px;font-size:13px;font-weight:500;z-index:9999;transition:transform .3s;max-width:90vw;text-align:center;white-space:nowrap;box-shadow:0 6px 24px rgba(0,0,0,.18)}
.toast.show{transform:translateX(-50%) translateY(0)}
.toast.ok{background:#1e7e34}
.toast.err{background:var(--rdk)}

/* Desktop */
@media(min-width:700px){
  body{display:flex;align-items:center;justify-content:center;min-height:100vh}
  .scr{position:relative;width:420px;height:90dvh;border-radius:24px;box-shadow:0 24px 60px rgba(0,0,0,.18);overflow:hidden}
}

/* Animations */
.ecard{animation:fup .4s ease both}
.ecard:nth-child(1){animation-delay:.04s}
.ecard:nth-child(2){animation-delay:.1s}
.ecard:nth-child(3){animation-delay:.16s}
.ecard:nth-child(4){animation-delay:.22s}
@keyframes fup{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}

/* Custom member picker */
.picker-wrap{position:relative;width:100%;z-index:50}
.picker-inp{
    width:100%;border:none;outline:none;
    font-family:'Inter',sans-serif;font-size:16px;font-weight:500;
    color:var(--blk);background:transparent;padding:0;
    -webkit-appearance:none;
}
.picker-inp::placeholder{color:#bbb;font-weight:400}
.picker-list{
    position:absolute;top:calc(100% + 4px);left:-16px;right:-16px;
    background:#fff;border:1.5px solid var(--bdr);
    border-radius:14px;box-shadow:0 12px 40px rgba(0,0,0,.22);
    z-index:9999;max-height:280px;overflow-y:auto;
    -webkit-overflow-scrolling:touch;
    scrollbar-width:thin;display:none;
}
.picker-item{
    padding:14px 16px;font-size:16px;font-weight:500;
    cursor:pointer;border-bottom:1px solid #f5f5f5;
    -webkit-tap-highlight-color:transparent;
    touch-action:manipulation;
    user-select:none;-webkit-user-select:none;
}
.picker-item:active,.picker-item.pk-active{background:var(--rlt);color:var(--red)}
.picker-item:last-child{border-bottom:none}
.picker-item:hover,.picker-item.active{background:var(--rlt);color:var(--red)}
.picker-item .pi-sub{font-size:11px;color:var(--gry);margin-top:2px;font-weight:400}
.picker-item mark{background:transparent;color:var(--red);font-weight:700}

/* Hidden visual sections but logic still alive */
.ui-hide{display:none !important}
</style>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.js"></script>
</head>
<body>

<!-- ════ HOME ════ -->
<div class="scr" id="s-home">
  <div style="display:flex;flex-direction:column;min-height:100%">
    <div class="hero">
      <div class="live-tag"><span class="ldot"></span> Weekly Meeting</div>
      <div class="hero-title">Miracle Morning</div>
      <div class="hero-sub">Coimbatore Chapter &middot; Registration Desk</div>
      <div class="chips">
        <div class="chip">📅 <?= $SundayDisplay ?></div>
        <div class="chip">₹1,450 / session</div>
      </div>
    </div>
    <div class="home-body">
      <div class="sec-label">Select your entry type</div>

      <div class="ecard" onclick="go('member')">
        <div class="eicon">🏢</div>
        <div class="einfo"><div class="ename">Member</div><div class="edesc">Pay weekly meeting fee</div></div>
        <div class="eright"><div class="efee">₹1,450</div><div class="earr">›</div></div>
      </div>

      <div class="ecard" onclick="go('kitty')">
        <div class="eicon">💰</div>
        <div class="einfo"><div class="ename">Kitty Cash</div><div class="edesc">Contribute kitty &middot; ₹3,000 / 6 months</div></div>
        <div class="eright"><div class="efee" style="font-size:13px">Any amount</div><div class="earr">›</div></div>
      </div>

      <div class="ecard" onclick="go('visitor')">
        <div class="eicon">🤝</div>
        <div class="einfo"><div class="ename">Visitor</div><div class="edesc">Invited guest for this week</div></div>
        <div class="eright"><div class="efee">₹1,450</div><div class="earr">›</div></div>
      </div>

      <div class="ecard" onclick="go('observer')">
        <div class="eicon">👁</div>
        <div class="einfo"><div class="ename">Observer</div><div class="edesc">Visiting from another chapter</div></div>
        <div class="eright"><div class="efee">₹1,450</div><div class="earr">›</div></div>
      </div>
    </div>
    <div class="hfooter"><img src="/api/image/powerbi.png" alt="PowerBI"></div>
  </div>
</div>

<!-- ════ MEMBER FORM ════ -->
<div class="scr off" id="s-member">
<div class="fscr">
  <div class="fhdr">
    <div class="fbar"></div>
    <div class="fhdr-in">
      <button class="back" onclick="go('home')">&#8592;</button>
      <div class="ftxt"><div class="ftitle">Member Payment</div><div class="fsub">Weekly fee &amp; advance booking</div></div>
      <span class="fbadge">Member</span>
    </div>
  </div>
  <form action="/api/process_all.php" method="POST" class="fbody" onsubmit="return valMember()">
    <input type="hidden" name="form_type" value="member">
    <input type="hidden" name="weeks_json"      id="m_wj"  value="[]">
    <input type="hidden" name="partial_week"    id="m_pw"  value="">
    <input type="hidden" name="partial_paid"    id="m_pp"  value="0">
    <input type="hidden" name="partial_balance" id="m_pb"  value="0">
    <input type="hidden" name="amount"          id="m_amt" value="0">

    <div class="fc">
      <div class="fc-hd">Your details</div>
      <div class="fr" style="position:relative">
        <label>Full Name *</label>
        <select id="m_name_inp" name="member_name" required onchange="onMemberNameInput(this.value)">
          <option value="" selected disabled>— Select Member —</option>
          <?php foreach ($members as $m):
            $val  = $m['name'].' - '.($m['company_name'] ?? '');
            $name = htmlspecialchars($m['name']);
          ?>
          <option value="<?= htmlspecialchars($val) ?>"><?= $name ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="fr">
        <label>Payment Method *</label>
        <select name="method" required>
          <option value="FinCloud">FinCloud</option>
          <option value="UPI">QR Code (UPI)</option>
          <option value="Cash">Cash</option>
          <option value="Card">Card</option>
        </select>
      </div>
    </div>

    <div class="acard">
      <div class="ahd">Payment Amount</div>
      <div class="arow">
        <span class="asym">₹</span>
        <input class="ainp" type="number" id="ma" placeholder="0" min="1" inputmode="numeric" oninput="calcAmt()">
      </div>
      <div class="ahint ui-hide">Each Sunday = ₹1,450 &middot; Extra amount carries to next Sundays automatically</div>
      <div class="sgrid ui-hide">
        <div class="scell"><div class="snum red" id="m_full">0</div><div class="slbl">Weeks paid</div></div>
        <div class="scell"><div class="snum amb" id="m_part">—</div><div class="slbl">Partial week</div></div>
        <div class="scell"><div class="snum red" id="m_bal">₹0</div><div class="slbl">Balance due</div></div>
        <div class="scell"><div class="snum" id="m_tot">₹0</div><div class="slbl">Total payable</div></div>
      </div>
    </div>

    <div class="fc ui-hide">
      <div class="whdr">
        <span class="wlbl">Sundays covered</span>
        <button type="button" class="wclr" onclick="clearW()">Clear all</button>
      </div>
      <div class="mcs">
        <?php foreach ($monthChips as $mc): ?>
        <button type="button" class="mc" data-m="<?= $mc['m'] ?>" data-y="<?= $mc['y'] ?>" onclick="selMonth('<?= $mc['m'] ?>','<?= $mc['y'] ?>',this)"><?= $mc['label'] ?></button>
        <?php endforeach; ?>
      </div>
      <div class="wgrid">
        <?php foreach ($Sundays as $f): ?>
        <div class="wc" id="wc_<?= $f['val'] ?>" data-date="<?= $f['val'] ?>" data-m="<?= $f['m'] ?>" data-y="<?= $f['y'] ?>" onclick="togW('<?= $f['val'] ?>')">
          <div class="wchk">&#10003;</div>
          <div class="wdate"><?= $f['disp'] ?></div>
          <div class="wstat" id="ws_<?= $f['val'] ?>">— unpaid</div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <button type="submit" class="sbtn" id="m_sub" disabled>Submit for Approval</button>
  </form>
  <div class="fftr"><img src="/api/image/powerbi.png" alt="PowerBI"></div>
</div>
</div>

<!-- ════ KITTY FORM ════ -->
<div class="scr off" id="s-kitty">
<div class="fscr">
  <div class="fhdr">
    <div class="fbar"></div>
    <div class="fhdr-in">
      <button class="back" onclick="go('home')">&#8592;</button>
      <div class="ftxt"><div class="ftitle">Kitty Cash</div><div class="fsub">Contribute anytime &middot; ₹3,000 / 6 months</div></div>
      <span class="fbadge">Kitty</span>
    </div>
  </div>
  <form action="/api/process_all.php" method="POST" class="fbody">
    <input type="hidden" name="form_type" value="kitty">

    <div class="fc">
      <div class="fc-hd">Your details</div>
      <div class="fr">
        <label>Full Name *</label>
        <select id="k_name" name="member_name" required onchange="loadKitty(this.value)">
          <option value="" selected disabled>— Select Member —</option>
          <?php foreach ($kittyData as $kname => $kpaid):
            $kparts = explode(' - ', $kname, 2);
            $kn    = htmlspecialchars($kparts[0]);
            $kco   = htmlspecialchars($kparts[1]??'');
            $kbal2 = max(0, 3000-$kpaid);
          ?>
          <option value="<?=htmlspecialchars($kname)?>"><?=$kn?><?=$kco?' — '.$kco:''?> (<?=$kbal2>0?'₹'.number_format($kbal2).' due':'✓ Full'?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="kpbox" id="kpbox">
      <div style="display:flex;justify-content:space-between;margin-bottom:2px">
        <span style="font-size:13px;font-weight:600">Kitty progress</span>
        <span style="font-size:13px;font-weight:700;color:var(--red)" id="kpct">0%</span>
      </div>
      <div class="ktrack"><div class="kfill" id="kbar" style="width:0%"></div></div>
      <div class="kplbls"><span id="kpaid">₹0 paid</span><span id="kbal" style="color:var(--red)">₹3,000 remaining</span></div>
    </div>

    <div class="acard">
      <div class="ahd">Amount to Pay</div>
      <div class="arow">
        <span class="asym">₹</span>
        <input class="ainp" type="number" name="amount" id="k_amt" placeholder="0" min="1" inputmode="numeric" required>
      </div>
      <div class="qarow">
        <button type="button" class="qa" onclick="setK(500)">₹500</button>
        <button type="button" class="qa" onclick="setK(1000)">₹1,000</button>
        <button type="button" class="qa" onclick="setK(1500)">₹1,500</button>
        <button type="button" class="qa" onclick="setK(3000)">₹3,000 (full)</button>
        <button type="button" class="qa" id="kbalbtn" style="display:none" onclick="setK(parseInt(this.dataset.v))">Pay balance</button>
      </div>
    </div>

    <div class="fc">
      <div class="fc-hd">Payment</div>
      <div class="fr">
        <label>Payment Method *</label>
        <select name="method" required>
          <option value="FinCloud">FinCloud</option>
          <option value="UPI">QR Code (UPI)</option>
          <option value="Cash">Cash</option>
          <option value="Card">Card</option>
        </select>
      </div>
      <div class="fr">
        <label>Notes (optional)</label>
        <input type="text" name="notes" placeholder="e.g. 2nd instalment">
      </div>
    </div>

    <button type="submit" class="sbtn">Submit for Approval</button>
  </form>
  <div class="fftr"><img src="/api/image/powerbi.png" alt="PowerBI"></div>
</div>
</div>

<!-- ════ VISITOR FORM ════ -->
<div class="scr off" id="s-visitor">
<div class="fscr">
  <div class="fhdr">
    <div class="fbar"></div>
    <div class="fhdr-in">
      <button class="back" onclick="go('home')">&#8592;</button>
      <div class="ftxt"><div class="ftitle">Visitor Entry</div><div class="fsub">Register your arrival · Payment handled at desk</div></div>
      <span class="fbadge">Visitor</span>
    </div>
  </div>
  <form action="/api/process_all.php" method="POST" enctype="multipart/form-data" class="fbody">
    <input type="hidden" name="form_type" value="visitor">
    <input type="hidden" name="amount" value="1450">
    <input type="hidden" name="method" value="Pending">
    <div class="fc">
      <div class="fc-hd">Your details</div>
      <div class="fr"><label>Full Name *</label><input type="text" name="name" placeholder="Your full name" required></div>
      <div class="fr"><label>Mobile Number *</label><input type="tel" name="mobile" placeholder="10-digit number" required inputmode="numeric"></div>
      <div class="fr"><label>Email Address *</label><input type="email" name="email" placeholder="your@email.com" required></div>
      <div class="fr"><label>Company Name</label><input type="text" name="company" placeholder="Your company"></div>
      <div class="fr"><label>Profession / Category</label><input type="text" name="profession" placeholder="e.g. CA, Doctor, Architect…"></div>
    </div>
    <div class="fc">
      <div class="fc-hd">Invited by</div>
      <div class="fr">
        <label>Member who invited you *</label>
        <select id="v_referrer" name="referrer" required>
          <option value="" selected disabled>— Select Member —</option>
          <?php foreach ($members as $vm):
            $vmn = htmlspecialchars($vm['name']);
          ?>
          <option value="<?= $vmn ?>"><?= $vmn ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="fc">
      <div class="fc-hd">Business Card <span style="font-size:.7rem;font-weight:400;color:var(--gry)">(optional)</span></div>
      <div class="fr">
        <label>Capture or Upload</label>
        <div id="vis_card_wrap" style="border:2px dashed var(--bdr);border-radius:12px;padding:16px;text-align:center;transition:border-color .2s"
             ondragover="event.preventDefault();this.style.borderColor='var(--red)'"
             ondragleave="this.style.borderColor='var(--bdr)'"
             ondrop="event.preventDefault();this.style.borderColor='var(--bdr)';handleCardDrop('vis',event)">
          <div id="vis_card_preview" style="display:none;margin-bottom:8px">
            <img id="vis_card_img" src="" style="max-width:100%;max-height:140px;border-radius:8px;object-fit:contain">
          </div>
          <div id="vis_card_placeholder">
            <div style="display:flex;gap:10px;justify-content:center;margin-bottom:8px">
              <button type="button" onclick="document.getElementById('vis_card_cam').click()"
                      style="background:#D90429;color:#fff;border:none;border-radius:10px;padding:10px 20px;font-size:.85rem;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:6px">
                📷 Camera
              </button>
              <button type="button" onclick="document.getElementById('vis_card_gal').click()"
                      style="background:#fff;color:#333;border:2px solid var(--bdr);border-radius:10px;padding:10px 20px;font-size:.85rem;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:6px">
                🖼 Gallery
              </button>
            </div>
            <div style="font-size:.72rem;color:#bbb">Take photo or choose from gallery · Crop & rotate</div>
          </div>
          <div id="vis_card_name" style="display:none;font-size:.78rem;font-weight:600;color:#1b5e20;margin-top:4px"></div>
        </div>
        <!-- Camera input (rear camera) -->
        <input type="file" id="vis_card_cam" accept="image/*" capture="environment" style="display:none"
               onchange="cardSelected('vis',this)">
        <!-- Gallery input (no capture) -->
        <input type="file" id="vis_card_gal" accept="image/*" style="display:none"
               onchange="cardSelected('vis',this)">
        <!-- Actual form input (receives cropped image) -->
        <input type="file" id="vis_card_inp" name="business_card" style="display:none">
        <button type="button" id="vis_card_clear" style="display:none;margin-top:6px;background:none;border:1px solid #ffcdd2;color:var(--red);border-radius:6px;padding:3px 10px;font-size:.72rem;cursor:pointer"
                onclick="clearCard('vis')">✕ Remove</button>
      </div>
    </div>
    <button type="submit" class="sbtn">Register Arrival</button>
  </form>
  <div class="fftr"><img src="/api/image/powerbi.png" alt="PowerBI"></div>
</div>
</div>

<!-- ════ OBSERVER FORM ════ -->
<div class="scr off" id="s-observer">
<div class="fscr">
  <div class="fhdr">
    <div class="fbar"></div>
    <div class="fhdr-in">
      <button class="back" onclick="go('home')">&#8592;</button>
      <div class="ftxt"><div class="ftitle">Observer Entry</div><div class="fsub">Visiting from another chapter</div></div>
      <span class="fbadge">Observer</span>
    </div>
  </div>
  <form action="/api/process_all.php" method="POST" enctype="multipart/form-data" class="fbody">
    <input type="hidden" name="form_type" value="observer">
    <input type="hidden" name="amount" value="1450">
    <div class="fc">
      <div class="fc-hd">Personal details</div>
      <div class="fr"><label>Full Name *</label><input type="text" name="name" placeholder="Your full name" required></div>
      <div class="fr"><label>Contact Number *</label><input type="tel" name="mobile" placeholder="10-digit number" required inputmode="numeric"></div>
      <div class="fr"><label>Email Address *</label><input type="email" name="email" placeholder="your@email.com" required></div>
    </div>
    <div class="fc">
      <div class="fc-hd">Chapter details</div>
      <div class="fr"><label>Chapter Name *</label><input type="text" name="chapter" placeholder="Your chapter name" required></div>
      <div class="fr"><label>Profession / Category *</label><input type="text" name="category" placeholder="e.g. CA, Architect…" required></div>
    </div>
    <div class="fc">
      <div class="fc-hd">Payment</div>
      <div class="fr">
        <label>Payment Method *</label>
        <select name="method" required>
          <option value="UPI">QR Code (UPI)</option>
          <option value="Cash">Cash</option>
          <option value="Card">Card</option>
          <option value="FinCloud">FinCloud</option>
        </select>
      </div>
    </div>
    <div class="feerow">
      <div><div class="feelbl">Observer Fee</div><div class="feesub">One-time entry for this week</div></div>
      <div class="feeamt">₹1,450</div>
    </div>
    <div class="fc">
      <div class="fc-hd">Business Card <span style="font-size:.7rem;font-weight:400;color:var(--gry)">(optional)</span></div>
      <div class="fr">
        <label>Capture or Upload</label>
        <div id="obs_card_wrap" style="border:2px dashed var(--bdr);border-radius:12px;padding:16px;text-align:center;transition:border-color .2s"
             ondragover="event.preventDefault();this.style.borderColor='var(--red)'"
             ondragleave="this.style.borderColor='var(--bdr)'"
             ondrop="event.preventDefault();this.style.borderColor='var(--bdr)';handleCardDrop('obs',event)">
          <div id="obs_card_preview" style="display:none;margin-bottom:8px">
            <img id="obs_card_img" src="" style="max-width:100%;max-height:140px;border-radius:8px;object-fit:contain">
          </div>
          <div id="obs_card_placeholder">
            <div style="display:flex;gap:10px;justify-content:center;margin-bottom:8px">
              <button type="button" onclick="document.getElementById('obs_card_cam').click()"
                      style="background:#D90429;color:#fff;border:none;border-radius:10px;padding:10px 20px;font-size:.85rem;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:6px">
                📷 Camera
              </button>
              <button type="button" onclick="document.getElementById('obs_card_gal').click()"
                      style="background:#fff;color:#333;border:2px solid var(--bdr);border-radius:10px;padding:10px 20px;font-size:.85rem;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:6px">
                🖼 Gallery
              </button>
            </div>
            <div style="font-size:.72rem;color:#bbb">Take photo or choose from gallery · Crop & rotate</div>
          </div>
          <div id="obs_card_name" style="display:none;font-size:.78rem;font-weight:600;color:#1b5e20;margin-top:4px"></div>
        </div>
        <input type="file" id="obs_card_cam" accept="image/*" capture="environment" style="display:none"
               onchange="cardSelected('obs',this)">
        <input type="file" id="obs_card_gal" accept="image/*" style="display:none"
               onchange="cardSelected('obs',this)">
        <input type="file" id="obs_card_inp" name="business_card" style="display:none">
        <button type="button" id="obs_card_clear" style="display:none;margin-top:6px;background:none;border:1px solid #ffcdd2;color:var(--red);border-radius:6px;padding:3px 10px;font-size:.72rem;cursor:pointer"
                onclick="clearCard('obs')">✕ Remove</button>
      </div>
    </div>
    <button type="submit" class="sbtn">Submit for Approval</button>
  </form>
  <div class="fftr"><img src="/api/image/powerbi.png" alt="PowerBI"></div>
</div>
</div>

<div class="toast" id="toast"></div>

<script>
// ── Navigation ──────────────────────────────────
function go(name) {
    document.querySelectorAll('.scr').forEach(function(s){ s.classList.add('off'); });
    var t = document.getElementById('s-' + name);
    if (t) { t.classList.remove('off'); t.scrollTop = 0; }
}

// ── Toast ────────────────────────────────────────
function toast(msg, type) {
    var t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast show' + (type ? ' '+type : '');
    setTimeout(function(){ t.classList.remove('show'); }, 4000);
}

// ── Handle redirect from process_all.php ─────────
(function(){
    var p = new URLSearchParams(window.location.search);
    var msg = p.get('msg'), scr = p.get('screen');
    var msgs = {
        success:       '✅ Submitted! Awaiting admin approval.',
        error_missing: '❌ Please fill all required fields.',
        error_member:  '❌ Member not found. Check your name.',
        error_db:      '❌ Database error. Please try again.'
    };
    if (scr && document.getElementById('s-'+scr)) go(scr);
    if (msg) setTimeout(function(){ toast(msgs[msg]||'⚠ Something went wrong.', msg==='success'?'ok':'err'); }, 250);
    if (msg || scr) window.history.replaceState({}, '', '/register.php');
})();

// ── Member payment status check (AJAX) ───────────
var _memberCheckTimer = null;
var _lockedWeeks = {}; // dates that are already paid/pending — locked

function onMemberNameInput(val) {
    clearTimeout(_memberCheckTimer);
    val = val.trim();
    if (val.length < 2) {
        _lockedWeeks = {};
        clearMemberLocks();
        return;
    }
    _memberCheckTimer = setTimeout(function(){
        var Sunday = '<?= $Sunday->format('Y-m-d') ?>';
        fetch('/api/member_check.php?name='+encodeURIComponent(val)+'&Sunday='+Sunday)
            .then(function(r){ return r.json(); })
            .then(function(d){
                if (!d.ok) { clearMemberLocks(); return; }
                applyMemberHistory(d);
            }).catch(function(){ clearMemberLocks(); });
    }, 400);
}

function clearMemberLocks() {
    // Remove all lock styles from week cells
    document.querySelectorAll('.wc').forEach(function(c){
        c.style.pointerEvents = '';
        c.style.opacity = '';
        c.classList.remove('full','part');
        var s = document.getElementById('ws_'+c.dataset.date);
        if (s) s.textContent = '— unpaid';
    });
    wS = {}; wP = {};
    renderSum(0);
    syncH();
    document.getElementById('m_sub').disabled = true;
}

function applyMemberHistory(d) {
    _lockedWeeks = {};
    // Reset all cells first
    document.querySelectorAll('.wc').forEach(function(c){
        c.style.pointerEvents = '';
        c.style.opacity = '1';
        c.style.border = '';
        c.classList.remove('full','part');
        var s = document.getElementById('ws_'+c.dataset.date);
        if (s) s.textContent = '— unpaid';
    });
    wS = {}; wP = {};

    // Mark already-paid/pending weeks as locked green
    if (d.history && d.history.length > 0) {
        d.history.forEach(function(h) {
            var date = h.friday_date;
            var cell = document.getElementById('wc_'+date);
            var stat = document.getElementById('ws_'+date);
            if (!cell) return;

            if (h.status === 'Paid') {
                if (h.is_partial) {
                    // Partial — show amber locked
                    cell.classList.add('part');
                    cell.style.pointerEvents = 'none';
                    cell.style.opacity = '0.8';
                    wS[date] = 'part';
                    wP[date] = { paid: parseInt(h.partial_paid), bal: parseInt(h.partial_balance) };
                    if (stat) stat.textContent = '₹'+num(h.partial_paid)+' paid · ₹'+num(h.partial_balance)+' balance';
                } else {
                    // Fully paid — show green locked
                    cell.classList.add('full');
                    cell.style.pointerEvents = 'none';
                    cell.style.opacity = '0.75';
                    wS[date] = 'locked';
                    if (stat) stat.textContent = '✓ Already paid';
                }
                _lockedWeeks[date] = true;
            } else if (h.status === 'Pending') {
                // Pending approval — show amber locked
                cell.style.background = '#fff8e1';
                cell.style.pointerEvents = 'none';
                cell.style.opacity = '0.8';
                wS[date] = 'locked';
                if (stat) stat.textContent = '⏳ Pending approval';
                _lockedWeeks[date] = true;
            }
        });
    }

    // Silent partial auto-fill: if member has unpaid balance, pre-fill it
    var partials = (d.history||[]).filter(function(h){
        return h.status==='Paid' && h.is_partial && h.partial_balance > 0;
    });
    if (partials.length > 0) {
        var bal = parseInt(partials[0].partial_balance) || 0;
        if (bal > 0) {
            setTimeout(function(){
                var af = document.getElementById('ma');
                if (af) { af.value = bal; calcAmt(); }
            }, 150);
            return; // skip reset below
        }
    }
    renderSum(0);
    document.getElementById('ma').value = '';
    document.getElementById('m_sub').disabled = true;
}


function num(n){ return parseInt(n||0).toLocaleString('en-IN'); }

// ── Member amount logic ──────────────────────────
var FEE = 1450, wS = {}, wP = {};

function getDates() {
    return Array.from(document.querySelectorAll('.wc')).map(function(c){ return c.dataset.date; });
}

function calcAmt() {
    var amt = parseInt(document.getElementById('ma').value) || 0;

    // Get only UNLOCKED dates (not already paid/pending)
    var allDates    = getDates();
    var unlockedDates = allDates.filter(function(d){ return !_lockedWeeks[d]; });

    // Reset only unlocked cells (leave locked ones as-is)
    unlockedDates.forEach(function(d){
        if (wS[d] !== 'locked') {
            delete wS[d]; delete wP[d];
            setWC(d, 'none');
        }
    });

    // Distribute amount across unlocked weeks only
    var rem = amt;
    unlockedDates.forEach(function(d){
        if (rem <= 0) return;
        if (rem >= FEE) { wS[d] = 'full'; rem -= FEE; }
        else { wS[d] = 'part'; wP[d] = { paid: rem, bal: FEE - rem }; rem = 0; }
        setWC(d, wS[d]);
    });

    renderSum(amt);
    syncH();
    document.getElementById('m_sub').disabled = amt <= 0;
}

function setWC(date, state) {
    var c = document.getElementById('wc_' + date);
    var s = document.getElementById('ws_' + date);
    if (!c) return;
    c.classList.remove('full','part');
    if (state === 'full') { c.classList.add('full'); s.textContent = '✓ Fully paid'; }
    else if (state === 'part') { c.classList.add('part'); s.textContent = '₹'+wP[date].paid.toLocaleString('en-IN')+' paid · ₹'+wP[date].bal.toLocaleString('en-IN')+' bal'; }
    else { s.textContent = '— unpaid'; }
}

function togW(date) {
    if (_lockedWeeks[date]) return; // Can't toggle already-paid weeks
    if (parseInt(document.getElementById('ma').value) > 0) return;
    wS[date] = wS[date] === 'full' ? 'none' : 'full';
    setWC(date, wS[date]);
    // Count only new (unlocked) full weeks
    var cnt = 0;
    getDates().forEach(function(d){ if (!_lockedWeeks[d] && wS[d]==='full') cnt++; });
    var tot2 = cnt * FEE;
    document.getElementById('ma').value = tot2 || '';
    renderSum(tot2); syncH();
    document.getElementById('m_sub').disabled = tot2 <= 0;
}

function selMonth(m, y, btn) {
    if (parseInt(document.getElementById('ma').value) > 0) { toast('Clear amount first.','err'); return; }
    document.querySelectorAll('.wc').forEach(function(c){
        if (c.dataset.m === m && c.dataset.y === y && !_lockedWeeks[c.dataset.date]) {
            wS[c.dataset.date] = 'full'; setWC(c.dataset.date, 'full');
        }
    });
    var cnt = 0;
    getDates().forEach(function(d){ if (!_lockedWeeks[d] && wS[d]==='full') cnt++; });
    var total = cnt * FEE;
    document.getElementById('ma').value = total || '';
    renderSum(total); syncH();
    document.getElementById('m_sub').disabled = total <= 0;
}

function clearW() {
    document.getElementById('ma').value = '';
    // Only clear unlocked weeks
    getDates().forEach(function(d){
        if (!_lockedWeeks[d]) {
            delete wS[d]; delete wP[d];
            setWC(d,'none');
        }
    });
    renderSum(0); syncH();
    document.getElementById('m_sub').disabled = true;
}

function renderSum(total) {
    // Only count NEW (unlocked) weeks in the display
    var allDts  = getDates();
    var newFull = allDts.filter(function(d){ return !_lockedWeeks[d] && wS[d]==='full'; }).length;
    var pts     = allDts.filter(function(d){ return !_lockedWeeks[d] && wS[d]==='part'; });
    // Re-read balance fresh from wP to avoid stale display
    var bal = 0;
    if (pts.length > 0 && wP[pts[0]]) {
        bal = wP[pts[0]].bal || 0;
    }
    document.getElementById('m_full').textContent = newFull;
    document.getElementById('m_part').textContent = pts.length ? '1 week' : '—';
    document.getElementById('m_bal').textContent  = bal > 0 ? '₹' + bal.toLocaleString('en-IN') : '₹0';
    document.getElementById('m_tot').textContent  = '₹' + (total || 0).toLocaleString('en-IN');
}

function syncH() {
    // Only submit weeks that are NEW (not locked/already paid)
    var fulls   = Object.keys(wS).filter(function(d){ return wS[d]==='full' && !_lockedWeeks[d]; });
    var pts     = Object.keys(wS).filter(function(d){ return wS[d]==='part' && !_lockedWeeks[d]; });
    document.getElementById('m_wj').value  = JSON.stringify(fulls);
    if (pts.length) {
        var pd = pts[0];
        document.getElementById('m_pw').value = pd;
        document.getElementById('m_pp').value = wP[pd].paid;
        document.getElementById('m_pb').value = wP[pd].bal;
    } else {
        document.getElementById('m_pw').value = '';
        document.getElementById('m_pp').value = '0';
        document.getElementById('m_pb').value = '0';
    }
    document.getElementById('m_amt').value = parseInt(document.getElementById('ma').value) || 0;
}

function valMember() {
    syncH();
    var amt = parseInt(document.getElementById('ma').value) || 0;
    if (amt <= 0) { toast('Please enter a payment amount.','err'); return false; }
    if (!Object.values(wS).some(function(v){ return v !== 'none'; })) { toast('No weeks selected.','err'); return false; }
    return true;
}

// ── Kitty logic ──────────────────────────────────
var KD = <?= json_encode($kittyData) ?>;
var KG = 3000;

function loadKitty(val) {
    val = val.trim();
    var box = document.getElementById('kpbox');
    var balbtn = document.getElementById('kbalbtn');
    if (!KD.hasOwnProperty(val)) { box.classList.remove('show'); balbtn.style.display='none'; return; }
    var paid = KD[val];
    var rem  = Math.max(0, KG - paid);
    var pct  = Math.min(100, Math.round(paid / KG * 100));
    document.getElementById('kbar').style.width  = pct + '%';
    document.getElementById('kpct').textContent  = pct + '%';
    document.getElementById('kpaid').textContent = '₹' + paid.toLocaleString('en-IN') + ' paid';
    document.getElementById('kbal').textContent  = rem > 0 ? '₹' + rem.toLocaleString('en-IN') + ' remaining' : '✅ Fully paid!';
    if (rem > 0) { balbtn.textContent = 'Pay balance ₹' + rem.toLocaleString('en-IN'); balbtn.dataset.v = rem; balbtn.style.display='inline-block'; }
    else { balbtn.style.display='none'; }
    box.classList.add('show');
}

function setK(n) { if (n > 0) document.getElementById('k_amt').value = n; }

// ── Custom member picker ─────────────────────────────────────────────────────
// ── Picker — fully mobile/touch-safe ─────────────────────────────────────────
var _justSelected = {};

function pickerShow(id) {
    if (_justSelected[id]) return; // blocked right after selection
    var inp = document.getElementById(
        id==='mp'?'m_name_inp':id==='kp'?'k_name':'v_referrer'
    );
    var list = document.getElementById(id + '_list');
    if (!list) return;
    var q = inp ? inp.value.trim().toLowerCase() : '';
    // Show all items when field is empty, filtered when typing
    var any = false;
    Array.from(list.children).forEach(function(it){
        var show = !q || (it.dataset.name||'').indexOf(q) >= 0;
        it.style.display = show ? '' : 'none';
        if (show) any = true;
    });
    if (any) { list.style.display = 'block'; list.scrollTop = 0; }
}

function pickerFilter(id, val) {
    var list = document.getElementById(id+'_list');
    if (!list) return;
    var q = val.trim().toLowerCase();
    // Only show list when user is typing (q not empty)
    if (!q) { list.style.display = 'none'; return; }
    var any = false;
    Array.from(list.children).forEach(function(it){
        var show = (it.dataset.name||'').indexOf(q) >= 0;
        it.style.display = show ? '' : 'none';
        if (show) any = true;
    });
    list.style.display = any ? 'block' : 'none';
}


function clearPickerBadge(id) {
    var badge = document.getElementById(id === 'mp' ? 'm_selected_badge' : id === 'kp' ? 'k_selected_badge' : 'v_selected_badge');
    if (badge) badge.style.display = 'none';
}

function pickerSelect(id, val, inputId) {
    // Block onfocus from reopening for 400ms
    _justSelected[id] = true;
    setTimeout(function(){ _justSelected[id] = false; }, 400);
    var inp = document.getElementById(inputId);
    var list = document.getElementById(id + '_list');
    if (list) list.style.display = 'none';
    if (!inp) return;
    // Show just the name part (before ' - ')
    var displayName = val.indexOf(' - ') >= 0 ? val.split(' - ')[0] : val;
    inp.value = displayName;

    // Show green selected badge for kitty picker
    if (id === 'kp') {
        var kbadge = document.getElementById('k_selected_badge');
        var knameEl = document.getElementById('k_selected_name');
        if (kbadge && knameEl) {
            knameEl.textContent = displayName;
            kbadge.style.display = 'block';
        }
    }
    // Show badge for visitor (referrer) picker
    if (id === 'vp') {
        var vbadge = document.getElementById('v_selected_badge');
        var vnameEl = document.getElementById('v_selected_name');
        if (vbadge && vnameEl) {
            vnameEl.textContent = displayName;
            vbadge.style.display = 'block';
        }
    }
    // Visual feedback: briefly highlight the input
    inp.style.background = '#f0fff4';
    setTimeout(function(){ inp.style.background = 'transparent'; }, 1200);
}

function pickerKey(e, id) {
    var list = document.getElementById(id+'_list');
    if (!list || list.style.display==='none') return;
    var items = Array.from(list.children).filter(function(it){
        return it.style.display !== 'none';
    });
    var cur = list.querySelector('.pk-active');
    var idx = cur ? items.indexOf(cur) : -1;
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (cur) cur.classList.remove('pk-active');
        var next = items[Math.min(idx+1, items.length-1)];
        if (next) { next.classList.add('pk-active'); next.scrollIntoView({block:'nearest'}); }
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        if (cur) cur.classList.remove('pk-active');
        var prev = items[Math.max(idx-1,0)];
        if (prev) { prev.classList.add('pk-active'); prev.scrollIntoView({block:'nearest'}); }
    } else if (e.key === 'Enter') {
        if (cur) { e.preventDefault(); cur.click(); }
    } else if (e.key === 'Escape' || e.key === 'Tab') {
        list.style.display = 'none';
    }
}

// Close on outside click/touch (desktop)
document.addEventListener('click', function(e){
    document.querySelectorAll('.picker-list').forEach(function(list){
        var wrap = list.closest('.picker-wrap');
        if (wrap && !wrap.contains(e.target)) {
            list.style.display = 'none';
        }
    });
});

// ── Business Card Upload with Crop & Rotate ─────────────────
var _cropPrefix = null;
var _cropper = null;

// Called when camera or gallery input changes
function cardSelected(prefix, inp) {
    if (!inp.files || !inp.files[0]) return;
    var file = inp.files[0];
    if (file.size > 10 * 1024 * 1024) {
        alert('File too large. Max 10MB.');
        inp.value = '';
        return;
    }
    if (!file.type.startsWith('image/')) {
        alert('Please select an image file (JPG, PNG, etc.)');
        inp.value = '';
        return;
    }
    _cropPrefix = prefix;
    var reader = new FileReader();
    reader.onload = function(e) {
        openCropModal(e.target.result);
    };
    reader.readAsDataURL(file);
    // Clear the source input so same file can be re-selected
    setTimeout(function(){ inp.value = ''; }, 100);
}

// Legacy support
function previewCard(prefix, inp) { cardSelected(prefix, inp); }

function openCropModal(imageSrc) {
    var modal = document.getElementById('cropModal');
    var img = document.getElementById('cropImage');

    // Check if Cropper.js is loaded
    if (typeof Cropper === 'undefined') {
        alert('Image editor is loading. Please try again in a moment.');
        return;
    }

    // Destroy previous cropper
    if (_cropper) { _cropper.destroy(); _cropper = null; }

    // Reset image completely
    img.removeAttribute('src');
    img.style.display = 'block';
    img.style.maxWidth = '100%';

    // Show modal
    modal.style.display = 'block';

    // Use setTimeout to let the modal render before setting image
    setTimeout(function() {
        var tempImg = new Image();
        tempImg.onload = function() {
            img.src = imageSrc;
            // Wait for the visible img to render
            setTimeout(function() {
                try {
                    _cropper = new Cropper(img, {
                        viewMode: 1,
                        dragMode: 'move',
                        autoCropArea: 0.9,
                        responsive: true,
                        restore: false,
                        guides: true,
                        center: true,
                        highlight: true,
                        cropBoxMovable: true,
                        cropBoxResizable: true,
                        toggleDragModeOnDblclick: false,
                        background: true,
                        minContainerWidth: 200,
                        minContainerHeight: 200,
                    });
                } catch(e) {
                    console.error('Cropper init error:', e);
                    alert('Could not initialize image editor: ' + e.message);
                }
            }, 200);
        };
        tempImg.onerror = function() {
            alert('Failed to load the image. Please try again.');
            modal.style.display = 'none';
        };
        tempImg.src = imageSrc;
    }, 100);
}

function cropRotate(deg) {
    if (_cropper) _cropper.rotate(deg);
}

function cropZoom(ratio) {
    if (_cropper) _cropper.zoom(ratio);
}

function cropReset() {
    if (_cropper) _cropper.reset();
}

function cropConfirm() {
    if (!_cropper || !_cropPrefix) return;
    var canvas = _cropper.getCroppedCanvas({
        maxWidth: 1600,
        maxHeight: 1600,
        imageSmoothingEnabled: true,
        imageSmoothingQuality: 'high',
    });
    canvas.toBlob(function(blob) {
        // Create a new File from the cropped blob
        var croppedFile = new File([blob], 'business_card.jpg', { type: 'image/jpeg' });
        var dt = new DataTransfer();
        dt.items.add(croppedFile);
        var inp = document.getElementById(_cropPrefix + '_card_inp');
        inp.files = dt.files;
        // Show preview
        document.getElementById(_cropPrefix + '_card_img').src = canvas.toDataURL('image/jpeg', 0.9);
        document.getElementById(_cropPrefix + '_card_preview').style.display = 'block';
        document.getElementById(_cropPrefix + '_card_name').textContent = '✓ business_card.jpg (cropped)';
        document.getElementById(_cropPrefix + '_card_name').style.display = 'block';
        document.getElementById(_cropPrefix + '_card_clear').style.display = 'inline-block';
        document.getElementById(_cropPrefix + '_card_placeholder').style.display = 'none';
        document.getElementById(_cropPrefix + '_card_wrap').style.borderColor = '#1b5e20';
        cropCancel();
    }, 'image/jpeg', 0.9);
}

function cropCancel() {
    if (_cropper) { _cropper.destroy(); _cropper = null; }
    document.getElementById('cropModal').style.display = 'none';
    if (_cropPrefix && !document.getElementById(_cropPrefix + '_card_preview').style.display !== 'block') {
        // If no cropped image was set, clear the input
        var inp = document.getElementById(_cropPrefix + '_card_inp');
        if (!document.getElementById(_cropPrefix + '_card_img').src) inp.value = '';
    }
}

function handleCardDrop(prefix, event) {
    var files = event.dataTransfer.files;
    if (!files || !files[0]) return;
    var inp = document.getElementById(prefix+'_card_inp');
    var dt = new DataTransfer();
    dt.items.add(files[0]);
    inp.files = dt.files;
    previewCard(prefix, inp);
}

function clearCard(prefix) {
    document.getElementById(prefix+'_card_inp').value = '';
    document.getElementById(prefix+'_card_preview').style.display = 'none';
    document.getElementById(prefix+'_card_img').src = '';
    document.getElementById(prefix+'_card_name').style.display = 'none';
    document.getElementById(prefix+'_card_name').textContent = '';
    document.getElementById(prefix+'_card_placeholder').style.display = 'block';
    document.getElementById(prefix+'_card_clear').style.display = 'none';
    document.getElementById(prefix+'_card_wrap').style.borderColor = 'var(--bdr)';
}
</script>

<!-- ── Crop Modal ─────────────────────────────────────────────── -->
<div id="cropModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.85);z-index:9999">
  <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:92%;max-width:600px;background:#111;border-radius:16px;overflow:hidden">
    <!-- Header -->
    <div style="padding:12px 16px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #333">
      <span style="color:#fff;font-weight:700;font-size:.95rem">Crop & Rotate Business Card</span>
      <button onclick="cropCancel()" style="background:none;border:none;color:#aaa;font-size:1.4rem;cursor:pointer;line-height:1">&times;</button>
    </div>
    <!-- Image area — Cropper.js needs explicit height -->
    <div style="width:100%;height:300px;overflow:hidden;background:#000">
      <img id="cropImage" src="" style="display:block;max-width:100%;max-height:100%">
    </div>
    <!-- Controls -->
    <div style="padding:10px 16px;display:flex;gap:6px;justify-content:center;flex-wrap:wrap;border-top:1px solid #333">
      <button type="button" onclick="cropRotate(-90)" style="background:#333;color:#fff;border:none;border-radius:8px;padding:8px 14px;font-size:.82rem;cursor:pointer;font-weight:600">↺ Left</button>
      <button type="button" onclick="cropRotate(90)" style="background:#333;color:#fff;border:none;border-radius:8px;padding:8px 14px;font-size:.82rem;cursor:pointer;font-weight:600">↻ Right</button>
      <button type="button" onclick="cropZoom(0.1)" style="background:#333;color:#fff;border:none;border-radius:8px;padding:8px 14px;font-size:.82rem;cursor:pointer;font-weight:600">+ Zoom</button>
      <button type="button" onclick="cropZoom(-0.1)" style="background:#333;color:#fff;border:none;border-radius:8px;padding:8px 14px;font-size:.82rem;cursor:pointer;font-weight:600">− Zoom</button>
      <button type="button" onclick="cropReset()" style="background:#555;color:#fff;border:none;border-radius:8px;padding:8px 14px;font-size:.82rem;cursor:pointer;font-weight:600">⟲ Reset</button>
    </div>
    <!-- Action buttons -->
    <div style="padding:10px 16px 14px;display:flex;gap:10px;justify-content:center;border-top:1px solid #333">
      <button type="button" onclick="cropCancel()" style="background:#fff;color:#333;border:none;border-radius:10px;padding:10px 28px;font-size:.9rem;font-weight:700;cursor:pointer">Cancel</button>
      <button type="button" onclick="cropConfirm()" style="background:#D90429;color:#fff;border:none;border-radius:10px;padding:10px 28px;font-size:.9rem;font-weight:700;cursor:pointer">✓ Crop & Save</button>
    </div>
  </div>
</div>

</body>
</html>
