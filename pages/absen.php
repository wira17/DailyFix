<?php
require_once __DIR__ . '/../includes/config.php';
requireLogin();

$pageTitle  = 'Absen Saya';
$activePage = 'absen';
$user       = currentUser();
$db         = getDB();
$today      = date('Y-m-d');

$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']==='on' ? 'https' : 'http')
         . '://' . $_SERVER['HTTP_HOST']
         . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/') . '/';

// ── Jadwal aktif ──
$stmtJadwal = $db->prepare("
    SELECT jk.*, j.nama as jadwal_nama, j.hari_kerja,
        s.id as shift_id, s.nama as shift_nama, s.jam_masuk, s.jam_keluar,
        s.toleransi_terlambat_detik,
        IFNULL(s.toleransi_pulang_cepat_detik, 0) as toleransi_pulang_cepat_detik
    FROM jadwal_karyawan jk
    JOIN jadwal j ON j.id = jk.jadwal_id
    JOIN shift s ON s.id = j.shift_id
    WHERE jk.karyawan_id = ?
      AND jk.berlaku_dari <= CURDATE()
      AND (jk.berlaku_sampai IS NULL OR jk.berlaku_sampai >= CURDATE())
    ORDER BY s.jam_masuk ASC
");
$stmtJadwal->execute([$user['id']]);
$semuaJadwal = $stmtJadwal->fetchAll();

$hariIni     = (int)date('N');
$jadwal      = null;
$isHariKerja = false;
foreach ($semuaJadwal as $j) {
    $hk = json_decode($j['hari_kerja'], true) ?? [];
    if (in_array($hariIni, $hk)) { $jadwal = $j; $isHariKerja = true; break; }
}
$jadwalInfo = $jadwal ?? ($semuaJadwal[0] ?? null);

// ── Lokasi karyawan ──
$stmtLokasi = $db->prepare("
    SELECT l.id, l.nama, l.latitude, l.longitude, l.radius_meter
    FROM karyawan_lokasi kl
    JOIN lokasi l ON l.id = kl.lokasi_id
    WHERE kl.karyawan_id = ? AND l.status = 'aktif'
    ORDER BY l.nama
");
$stmtLokasi->execute([$user['id']]);
$lokasiKaryawan = $stmtLokasi->fetchAll();

// ── Absensi hari ini ──
$stmtToday = $db->prepare("SELECT * FROM absensi WHERE karyawan_id = ? AND tanggal = ?");
$stmtToday->execute([$user['id'], $today]);
$absenToday = $stmtToday->fetch();

// ── Status khusus hari ini (dinas/sakit/izin) ──
$statusHariIni   = $absenToday['status_kehadiran'] ?? null;
$isStatusKhusus  = in_array($statusHariIni, ['dinas_luar','sakit','izin']);

$labelStatusKhusus = match($statusHariIni) {
    'dinas_luar' => ['label'=>'Dinas Luar', 'icon'=>'fas fa-briefcase',  'color'=>'#0f4c81', 'bg'=>'#eff6ff'],
    'sakit'      => ['label'=>'Sakit',      'icon'=>'fas fa-hospital',   'color'=>'#ef4444', 'bg'=>'#fef2f2'],
    'izin'       => ['label'=>'Izin',       'icon'=>'fas fa-file-circle-check', 'color'=>'#8b5cf6', 'bg'=>'#faf5ff'],
    default      => null,
};

$namaHari   = ['','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu'][$hariIni];
$namaBulan  = ['','Januari','Februari','Maret','April','Mei','Juni','Juli',
               'Agustus','September','Oktober','November','Desember'][(int)date('n')];
$tglLengkap = $namaHari.', '.date('d').' '.$namaBulan.' '.date('Y');
$sudahMasuk  = !empty($absenToday['waktu_masuk']);
$sudahKeluar = !empty($absenToday['waktu_keluar']);
$jam   = (int)date('H');
$salam = $jam < 11 ? 'Selamat Pagi' : ($jam < 15 ? 'Selamat Siang' : ($jam < 18 ? 'Selamat Sore' : 'Selamat Malam'));

$inisial = strtoupper(substr($user['nama'], 0, 1));
$parts   = explode(' ', $user['nama']);
if (count($parts) >= 2) $inisial = strtoupper(substr($parts[0],0,1).substr($parts[1],0,1));

include __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">

<style>
.abs-page { max-width: 520px; margin: 0 auto; padding-bottom: 20px; }

/* ── Hero ── */
.abs-hero {
    background: linear-gradient(150deg, #0f4c81 0%, #1a6bb5 100%);
    border-radius: 16px; padding: 20px;
    color: #fff; position: relative; overflow: hidden;
    margin-bottom: 14px;
    box-shadow: 0 8px 32px rgba(15,76,129,0.25);
}
.abs-hero::before {
    content:''; position:absolute; width:180px; height:180px; border-radius:50%;
    background:rgba(255,255,255,0.06); right:-40px; top:-60px; pointer-events:none;
}
.abs-hero-top { display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; }
.abs-hero-greet { font-size:12px; opacity:.7; font-weight:600; }
.abs-hero-name  { font-size:17px; font-weight:800; letter-spacing:-0.2px; }
.abs-avatar {
    width:42px; height:42px; border-radius:50%;
    background:rgba(255,255,255,0.18); border:2px solid rgba(255,255,255,0.3);
    display:flex; align-items:center; justify-content:center;
    font-weight:800; font-size:14px;
}
.abs-clock { font-size:46px; font-weight:900; letter-spacing:2px; line-height:1; font-variant-numeric:tabular-nums; text-align:center; }
.abs-date  { text-align:center; font-size:13px; opacity:.7; margin-top:4px; font-weight:600; }
.abs-shift-pill {
    display:flex; align-items:center; gap:7px; width:fit-content;
    background:rgba(255,255,255,0.14); border:1px solid rgba(255,255,255,0.2);
    border-radius:20px; padding:5px 14px; font-size:12.5px; font-weight:700;
    margin: 10px auto 0;
}
.abs-shift-dot { width:8px; height:8px; border-radius:50%; background:#10b981; animation:absDot 1.5s infinite; }
@keyframes absDot{ 0%,100%{opacity:1} 50%{opacity:.4} }

/* ── Status khusus (dinas/sakit/izin) di bawah jam ── */
.abs-status-khusus {
    margin-top: 12px;
    border-radius: 12px;
    padding: 10px 16px;
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 13.5px;
    font-weight: 800;
    color: #fff;
    background: rgba(255,255,255,0.18);
    border: 1.5px solid rgba(255,255,255,0.3);
}
.abs-status-khusus-icon {
    font-size: 22px;
    width: 40px; height: 40px;
    border-radius: 10px;
    background: rgba(255,255,255,0.2);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.abs-status-khusus-text { flex: 1; }
.abs-status-khusus-label { font-size: 15px; font-weight: 900; }
.abs-status-khusus-sub   { font-size: 11.5px; opacity: .8; margin-top: 1px; font-weight: 600; }

.abs-gps-strip {
    background:rgba(255,255,255,0.12); border:1px solid rgba(255,255,255,0.15);
    border-radius:10px; padding:8px 12px;
    display:flex; align-items:center; gap:8px;
    margin-top:10px; font-size:12px; font-weight:600; color:rgba(255,255,255,.85);
}
.abs-gps-dot { width:9px; height:9px; border-radius:50%; background:#f59e0b; flex-shrink:0; animation:absDot 1.5s infinite; box-shadow:0 0 0 3px rgba(245,158,11,.2); }
.abs-gps-dot.ok { background:#10b981; box-shadow:0 0 0 3px rgba(16,185,129,.2); }

/* ── Status row ── */
.abs-status-row { background:#fff; border-radius:14px; padding:14px 16px; display:flex; margin-bottom:14px; box-shadow:0 2px 16px rgba(15,76,129,0.08); }
.abs-status-item { flex:1; text-align:center; position:relative; }
.abs-status-item+.abs-status-item::before { content:''; position:absolute; left:0; top:10%; height:80%; width:1px; background:rgba(0,0,0,0.07); }
.abs-s-label { font-size:10.5px; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:.6px; }
.abs-s-val   { font-size:20px; font-weight:900; color:#cbd5e1; margin-top:2px; font-variant-numeric:tabular-nums; }
.abs-s-val.ok   { color:#10b981; }
.abs-s-val.warn { color:#f59e0b; }

/* ── Section title ── */
.abs-sec-title { font-size:11.5px; font-weight:800; color:#94a3b8; text-transform:uppercase; letter-spacing:.8px; margin:0 0 9px; }

/* ── Action grid ── */
.abs-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:14px; }
.abs-action-btn {
    background:#fff; border:none; border-radius:14px;
    padding:18px 12px 14px; cursor:pointer;
    display:flex; flex-direction:column; align-items:center; gap:8px;
    box-shadow:0 2px 14px rgba(15,76,129,0.08);
    transition:transform .15s, box-shadow .15s;
    position:relative; overflow:hidden;
    -webkit-tap-highlight-color:transparent;
}
.abs-action-btn:active:not([disabled]) { transform:scale(0.95); }
.abs-action-btn[disabled] { opacity:.38; cursor:not-allowed; pointer-events:none; }
.abs-action-btn::before { content:''; position:absolute; top:0; left:0; right:0; height:4px; border-radius:14px 14px 0 0; }
.abs-btn-masuk::before  { background:#10b981; }
.abs-btn-keluar::before { background:#f59e0b; }
.abs-action-icon { width:50px; height:50px; border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:23px; }
.abs-action-label { font-size:13px; font-weight:800; color:#0f172a; }
.abs-action-sub   { font-size:11.5px; color:#64748b; font-weight:600; text-align:center; line-height:1.4; }
.abs-action-done  { font-size:12px; color:#10b981; font-weight:800; }

/* ── Banner nonaktif absen ── */
.abs-locked-banner {
    background: #fff;
    border-radius: 14px;
    padding: 16px;
    text-align: center;
    box-shadow: 0 2px 14px rgba(15,76,129,0.08);
    margin-bottom: 14px;
}

/* ── Full-width button ── */
.abs-full-btn {
    width:100%; background:#fff; border:none; border-radius:14px;
    padding:14px 16px; cursor:pointer;
    display:flex; align-items:center; gap:12px;
    box-shadow:0 2px 14px rgba(15,76,129,0.08);
    transition:transform .15s; margin-bottom:10px;
    text-align:left; -webkit-tap-highlight-color:transparent; position:relative;
}
.abs-full-btn:active { transform:scale(0.98); }
.abs-full-btn::before { content:''; position:absolute; left:0; top:20%; height:60%; width:3.5px; border-radius:0 4px 4px 0; }
.abs-fb-dinas::before { background:#0f4c81; }
.abs-fb-sakit::before { background:#ef4444; }
.abs-fb-izin::before  { background:#8b5cf6; }
.abs-fb-icon { width:44px; height:44px; border-radius:13px; display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:20px; }
.abs-fb-label { font-size:13.5px; font-weight:800; color:#0f172a; }
.abs-fb-sub   { font-size:12px; color:#64748b; font-weight:600; }
.abs-fb-arrow { margin-left:auto; color:#cbd5e1; font-size:18px; }

/* ── Status card ── */
.abs-today-card { background:#fff; border-radius:14px; overflow:hidden; box-shadow:0 2px 16px rgba(15,76,129,0.08); margin-bottom:14px; }
.abs-today-head { padding:12px 16px; display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid rgba(0,0,0,0.05); }
.abs-today-title { font-size:13px; font-weight:800; color:#0f172a; }
.abs-today-body  { padding:12px 16px; display:grid; gap:8px; }
.abs-today-row   { display:flex; justify-content:space-between; align-items:center; padding:9px 12px; background:#f8fafc; border-radius:8px; }
.abs-today-key   { font-size:13px; font-weight:700; color:#0f172a; display:flex; align-items:center; gap:7px; }
.abs-today-val   { font-size:15px; font-weight:900; color:#0f172a; font-variant-numeric:tabular-nums; }
.abs-alert { padding:10px 14px; border-radius:8px; font-size:12.5px; font-weight:700; display:flex; align-items:center; gap:8px; }
.abs-alert-warn { background:#fffbeb; color:#78350f; border-left:3px solid #f59e0b; }
.abs-alert-ok   { background:#ecfdf5; color:#065f46; border-left:3px solid #10b981; }
.abs-foto-grid  { display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-top:4px; }
.abs-foto-item  { text-align:center; }
.abs-foto-label { font-size:11px; color:#64748b; font-weight:700; margin-bottom:5px; }
.abs-foto-item img { width:100%; border-radius:8px; aspect-ratio:1; object-fit:cover; border:2px solid #e2e8f0; }

/* ── Map ── */
.abs-map-card { background:#fff; border-radius:14px; overflow:hidden; box-shadow:0 2px 16px rgba(15,76,129,0.08); margin-bottom:14px; }
.abs-map-head { padding:11px 16px 10px; border-bottom:1px solid rgba(0,0,0,0.05); display:flex; align-items:center; gap:8px; font-size:13px; font-weight:800; color:#0f172a; }
#absMap { width:100%; height:200px; }
.abs-map-info { padding:10px 14px; display:grid; gap:4px; font-size:12px; color:#64748b; font-weight:600; }

/* ── Overlay / Bottom Sheet ── */
.abs-overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,0.55); z-index:1050; align-items:flex-end; justify-content:center; backdrop-filter:blur(2px); -webkit-backdrop-filter:blur(2px); }
.abs-overlay.open { display:flex; animation:absOverlayIn .2s ease; }
@keyframes absOverlayIn{ from{opacity:0} to{opacity:1} }
.abs-sheet { background:#fff; border-radius:24px 24px 0 0; width:100%; max-width:560px; padding-bottom:max(20px,env(safe-area-inset-bottom)); animation:absSheetUp .28s cubic-bezier(.34,1.4,.64,1); max-height:92vh; overflow-y:auto; -webkit-overflow-scrolling:touch; }
@keyframes absSheetUp{ from{transform:translateY(100%)} to{transform:translateY(0)} }
.abs-sheet-handle { width:38px; height:4px; border-radius:2px; background:#e2e8f0; margin:12px auto 0; }
.abs-sheet-header { padding:14px 20px 12px; border-bottom:1px solid rgba(0,0,0,0.05); display:flex; align-items:center; justify-content:space-between; position:sticky; top:0; background:#fff; z-index:2; }
.abs-sheet-title { font-size:16px; font-weight:800; color:#0f172a; }
.abs-sheet-close { width:32px; height:32px; border-radius:50%; background:#f1f5f9; border:none; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:16px; color:#64748b; }
.abs-sheet-body { padding:18px 20px; }

/* ── Step ── */
.abs-steps { display:flex; align-items:center; gap:6px; margin-bottom:16px; }
.abs-step-dot { height:6px; width:28px; border-radius:3px; background:#e2e8f0; transition:all .3s; }
.abs-step-dot.active { background:#0f4c81; }
.abs-step-dot.done   { background:#10b981; }

/* ── Info boxes ── */
.abs-info { background:#f8fafc; border-radius:10px; padding:11px 14px; margin-bottom:14px; display:flex; align-items:center; gap:9px; font-size:12.5px; font-weight:700; color:#0369a1; }
.abs-info.ok      { background:#ecfdf5; color:#065f46; }
.abs-info.warn    { background:#fffbeb; color:#78350f; }
.abs-info.danger  { background:#fef2f2; color:#991b1b; }
.abs-data-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:14px; }
.abs-data-box { background:#f8fafc; border-radius:10px; padding:13px; text-align:center; }
.abs-data-label { font-size:10.5px; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:.5px; margin-bottom:4px; }
.abs-data-val { font-size:20px; font-weight:900; color:#0f172a; font-variant-numeric:tabular-nums; }

/* ── Camera ── */
.abs-cam-wrap { background:#000; border-radius:10px; aspect-ratio:4/3; position:relative; overflow:hidden; margin-bottom:12px; }
.abs-cam-wrap video { width:100%; height:100%; object-fit:cover; transform:scaleX(-1); display:block; }
.abs-face-guide { position:absolute; top:50%; left:50%; transform:translate(-50%,-54%); width:115px; height:145px; border:2.5px dashed rgba(255,255,255,0.75); border-radius:50% 50% 50% 50%/60% 60% 40% 40%; pointer-events:none; }
.abs-face-label { position:absolute; bottom:-22px; left:50%; transform:translateX(-50%); font-size:11px; color:rgba(255,255,255,0.8); white-space:nowrap; font-weight:700; }
.abs-cam-err { display:none; padding:12px; background:#fef2f2; border-radius:10px; color:#991b1b; font-size:13px; text-align:center; margin-bottom:10px; font-weight:600; }
.abs-selfie-preview { width:72%; display:block; margin:0 auto 14px; border-radius:10px; border:3px solid #10b981; }

/* ── Result rows ── */
.abs-result-box { background:#f8fafc; border-radius:10px; padding:14px; margin-bottom:14px; }
.abs-result-row { display:flex; justify-content:space-between; align-items:center; padding:8px 0; border-bottom:1px solid #f1f5f9; }
.abs-result-row:last-child { border-bottom:none; }
.abs-result-key { font-size:13px; color:#64748b; font-weight:600; }
.abs-result-val { font-size:13px; font-weight:800; color:#0f172a; }

/* ── Form ── */
.abs-form-row { margin-bottom:14px; }
.abs-form-label { font-size:12px; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:.5px; margin-bottom:7px; display:block; }
.abs-form-control { width:100%; padding:11px 14px; border:1.5px solid #e2e8f0; border-radius:10px; font-size:14px; font-family:inherit; color:#0f172a; outline:none; transition:border-color .15s; background:#f8fafc; -webkit-appearance:none; }
.abs-form-control:focus { border-color:#0f4c81; background:#fff; box-shadow:0 0 0 3px rgba(15,76,129,0.07); }
textarea.abs-form-control { resize:vertical; min-height:80px; line-height:1.5; }
select.abs-form-control { cursor:pointer; }
.abs-upload-area { border:2px dashed #cbd5e1; border-radius:10px; padding:20px 16px; text-align:center; cursor:pointer; background:#f8fafc; transition:border-color .15s, background .15s; }
.abs-upload-area:hover, .abs-upload-area.has-file { border-color:#10b981; background:#f0fdf4; }
.abs-upload-icon { font-size:28px; margin-bottom:6px; }
.abs-upload-text { font-size:13px; font-weight:700; color:#64748b; }
.abs-upload-sub  { font-size:11.5px; color:#94a3b8; margin-top:3px; }
.abs-upload-area.has-file .abs-upload-text { color:#065f46; }

/* ── Buttons ── */
.abs-btn-row { display:flex; gap:9px; }
.abs-btn-back { flex:1; padding:12px; border:1.5px solid #e2e8f0; border-radius:10px; font-size:14px; font-weight:800; cursor:pointer; background:#f8fafc; color:#0f172a; font-family:inherit; transition:transform .15s; }
.abs-btn-back:active { transform:scale(0.97); }
.abs-btn-primary { flex:2; padding:13px; border:none; border-radius:10px; font-size:14px; font-weight:800; cursor:pointer; color:#fff; font-family:inherit; transition:transform .15s, opacity .15s; display:flex; align-items:center; justify-content:center; gap:7px; }
.abs-btn-primary.full { flex:none; width:100%; }
.abs-btn-primary:active { transform:scale(0.97); }
.abs-btn-primary:disabled { opacity:.5; cursor:not-allowed; }
.abs-bg-green  { background:linear-gradient(135deg,#10b981,#059669); }
.abs-bg-amber  { background:linear-gradient(135deg,#f59e0b,#d97706); }
.abs-bg-blue   { background:linear-gradient(135deg,#0f4c81,#1a6bb5); }
.abs-bg-red    { background:linear-gradient(135deg,#ef4444,#dc2626); }
.abs-bg-purple { background:linear-gradient(135deg,#8b5cf6,#7c3aed); }

/* ── Notif ── */
.abs-notif-circle { width:72px; height:72px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:34px; margin:0 auto 12px; animation:absPopIn .4s cubic-bezier(.34,1.56,.64,1); }
@keyframes absPopIn{ from{transform:scale(.5);opacity:0} to{transform:scale(1);opacity:1} }
.abs-notif-title { font-size:18px; font-weight:900; color:#0f172a; text-align:center; margin-bottom:5px; }
.abs-notif-sub   { font-size:13px; color:#64748b; font-weight:600; text-align:center; margin-bottom:18px; }

/* Leaflet */
.leaflet-pane,.leaflet-control,.leaflet-top,.leaflet-bottom { z-index:5 !important; }
</style>

<div class="abs-page">

    <!-- HERO -->
    <div class="abs-hero">
        <div class="abs-hero-top">
            <div>
                <div class="abs-hero-greet"><?= htmlspecialchars($salam) ?>,</div>
                <div class="abs-hero-name"><?= htmlspecialchars($user['nama']) ?></div>
            </div>
            <div class="abs-avatar"><?= htmlspecialchars($inisial) ?></div>
        </div>

        <div class="abs-clock" id="absClock">--:--:--</div>
        <div class="abs-date"><?= $tglLengkap ?></div>

        <?php if ($jadwalInfo && !$isStatusKhusus): ?>
        <div class="abs-shift-pill">
            <div class="abs-shift-dot"></div>
            <?= htmlspecialchars($jadwalInfo['shift_nama']) ?> &middot;
            <?= substr($jadwalInfo['jam_masuk'],0,5) ?> – <?= substr($jadwalInfo['jam_keluar'],0,5) ?>
        </div>
        <?php endif; ?>

        <?php if ($isStatusKhusus && $labelStatusKhusus): ?>
        <!-- Banner status khusus di bawah jam -->
        <div class="abs-status-khusus">
            <div class="abs-status-khusus-icon">
                <i class="<?= $labelStatusKhusus['icon'] ?>"></i>
            </div>
            <div class="abs-status-khusus-text">
                <div class="abs-status-khusus-label">Anda hari ini <?= $labelStatusKhusus['label'] ?></div>
                <div class="abs-status-khusus-sub">Absensi masuk/keluar tidak diperlukan</div>
            </div>
        </div>
        <?php endif; ?>

        <div class="abs-gps-strip">
            <div class="abs-gps-dot" id="absGpsDot"></div>
            <span id="absGpsText" style="flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">Mendeteksi lokasi GPS...</span>
        </div>
    </div>

    <!-- STATUS MASUK / KELUAR / DURASI -->
    <div class="abs-status-row">
        <div class="abs-status-item">
            <div class="abs-s-label">Masuk</div>
            <div class="abs-s-val <?= $sudahMasuk ? 'ok' : '' ?>" id="dispMasuk">
                <?= $sudahMasuk ? date('H:i', strtotime($absenToday['waktu_masuk'])) : '--:--' ?>
            </div>
        </div>
        <div class="abs-status-item">
            <div class="abs-s-label">Keluar</div>
            <div class="abs-s-val <?= $sudahKeluar ? 'warn' : '' ?>" id="dispKeluar">
                <?= $sudahKeluar ? date('H:i', strtotime($absenToday['waktu_keluar'])) : '--:--' ?>
            </div>
        </div>
        <div class="abs-status-item">
            <div class="abs-s-label">Durasi</div>
            <div class="abs-s-val" id="dispDurasi">
                <?= ($absenToday && $absenToday['durasi_kerja']) ? formatDurasi($absenToday['durasi_kerja']) : '--' ?>
            </div>
        </div>
    </div>

    <!-- TOMBOL ABSEN -->
    <?php if ($isStatusKhusus): ?>
    <!-- Absen dikunci karena status khusus -->
    <div class="abs-locked-banner">
        <?php
        $ic  = $labelStatusKhusus['icon'];
        $lbl = $labelStatusKhusus['label'];
        $clr = $labelStatusKhusus['color'];
        $bg  = $labelStatusKhusus['bg'];
        ?>
        <div style="width:54px;height:54px;border-radius:14px;background:<?= $bg ?>;display:flex;align-items:center;justify-content:center;margin:0 auto 8px">
            <i class="<?= $ic ?>" style="color:<?= $clr ?>;font-size:24px"></i>
        </div>
        <div style="font-size:14px;font-weight:800;color:#0f172a;margin-bottom:4px">Anda hari ini <?= $lbl ?></div>
        <div style="font-size:12px;color:#64748b;font-weight:600">Absen masuk &amp; keluar tidak diperlukan</div>
    </div>

    <?php elseif ($isHariKerja): ?>
    <div class="abs-sec-title">Absensi Hari Ini</div>
    <div class="abs-grid">
        <!-- Tombol Masuk -->
        <?php if (!$sudahMasuk): ?>
        <button class="abs-action-btn abs-btn-masuk" id="btnMasuk" onclick="absOpenAbsen('masuk')" disabled>
            <div class="abs-action-icon" style="background:#ecfdf5"><i class="fas fa-fingerprint" style="color:#10b981;font-size:22px"></i></div>
            <div class="abs-action-label">Absen Masuk</div>
            <div class="abs-action-sub" id="masukSub">Menunggu GPS...</div>
        </button>
        <?php else: ?>
        <div class="abs-action-btn abs-btn-masuk" style="cursor:default;pointer-events:none">
            <div class="abs-action-icon" style="background:#ecfdf5"><i class="fas fa-check-circle" style="color:#10b981;font-size:22px"></i></div>
            <div class="abs-action-label">Absen Masuk</div>
            <div class="abs-action-done"><?= date('H:i:s', strtotime($absenToday['waktu_masuk'])) ?></div>
        </div>
        <?php endif; ?>

        <!-- Tombol Keluar -->
        <?php if (!$sudahMasuk): ?>
        <button class="abs-action-btn abs-btn-keluar" disabled style="opacity:.38;cursor:not-allowed;pointer-events:none">
            <div class="abs-action-icon" style="background:#fffbeb"><i class="fas fa-door-open" style="color:#f59e0b;font-size:22px"></i></div>
            <div class="abs-action-label">Absen Keluar</div>
            <div class="abs-action-sub">Belum absen masuk</div>
        </button>
        <?php elseif ($sudahMasuk && !$sudahKeluar): ?>
        <button class="abs-action-btn abs-btn-keluar" id="btnKeluar" onclick="absOpenAbsen('keluar')" disabled>
            <div class="abs-action-icon" style="background:#fffbeb"><i class="fas fa-door-open" style="color:#f59e0b;font-size:22px"></i></div>
            <div class="abs-action-label">Absen Keluar</div>
            <div class="abs-action-sub" id="keluarSub">Menunggu GPS...</div>
        </button>
        <?php else: ?>
        <div class="abs-action-btn abs-btn-keluar" style="cursor:default;pointer-events:none">
            <div class="abs-action-icon" style="background:#fffbeb"><i class="fas fa-check-circle" style="color:#f59e0b;font-size:22px"></i></div>
            <div class="abs-action-label">Absen Keluar</div>
            <div class="abs-action-done"><?= date('H:i:s', strtotime($absenToday['waktu_keluar'])) ?></div>
        </div>
        <?php endif; ?>
    </div>

    <?php elseif ($jadwalInfo): ?>
    <div style="background:#fff;border-radius:14px;padding:16px;text-align:center;box-shadow:0 2px 14px rgba(15,76,129,0.08);margin-bottom:14px;color:#94a3b8;font-size:13px;font-weight:700">
        <i class="fas fa-calendar-xmark" style="font-size:22px;display:block;margin-bottom:6px;color:#e2e8f0"></i>
        Hari ini bukan hari kerja
    </div>
    <?php else: ?>
    <div class="abs-info danger" style="margin-bottom:14px">
        <i class="fas fa-triangle-exclamation"></i> Tidak ada jadwal aktif. Hubungi admin.
    </div>
    <?php endif; ?>

    <!-- STATUS ABSEN HARI INI -->
    <?php if ($absenToday && !$isStatusKhusus): ?>
    <div class="abs-today-card">
        <div class="abs-today-head">
            <span class="abs-today-title"><i class="fas fa-clipboard-check" style="color:#0f4c81;margin-right:6px"></i>Status Absen Hari Ini</span>
            <?= badgeStatus($absenToday['status_kehadiran']) ?>
        </div>
        <div class="abs-today-body">
            <?php if ($absenToday['waktu_masuk']): ?>
            <div class="abs-today-row">
                <span class="abs-today-key"><i class="fas fa-sign-in-alt" style="color:#10b981"></i> Masuk</span>
                <span class="abs-today-val"><?= date('H:i:s', strtotime($absenToday['waktu_masuk'])) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($absenToday['waktu_keluar']): ?>
            <div class="abs-today-row">
                <span class="abs-today-key"><i class="fas fa-sign-out-alt" style="color:#f59e0b"></i> Keluar</span>
                <span class="abs-today-val"><?= date('H:i:s', strtotime($absenToday['waktu_keluar'])) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($absenToday['terlambat_detik'] > 0): ?>
            <div class="abs-alert abs-alert-warn">
                <i class="fas fa-clock"></i> Terlambat: <strong><?= formatTerlambat($absenToday['terlambat_detik']) ?></strong>
            </div>
            <?php endif; ?>
            <?php if ($absenToday['durasi_kerja']): ?>
            <div class="abs-alert abs-alert-ok">
                <i class="fas fa-stopwatch"></i> Durasi Kerja: <strong><?= formatDurasi($absenToday['durasi_kerja']) ?></strong>
            </div>
            <?php endif; ?>
            <?php
            $fotoMasuk  = $absenToday['foto_masuk']  ?? '';
            $fotoKeluar = $absenToday['foto_keluar']  ?? '';
            if ($fotoMasuk && strpos($fotoMasuk,'data:') !== 0 && strpos($fotoMasuk,'http') !== 0)
                $fotoMasuk = $baseUrl . ltrim($fotoMasuk, '/');
            if ($fotoKeluar && strpos($fotoKeluar,'data:') !== 0 && strpos($fotoKeluar,'http') !== 0)
                $fotoKeluar = $baseUrl . ltrim($fotoKeluar, '/');
            ?>
            <?php if ($fotoMasuk || $fotoKeluar): ?>
            <div class="abs-foto-grid">
                <?php if ($fotoMasuk): ?>
                <div class="abs-foto-item">
                    <div class="abs-foto-label"><i class="fas fa-sign-in-alt" style="color:#10b981"></i> Foto Masuk</div>
                    <img src="<?= htmlspecialchars($fotoMasuk) ?>" alt="Foto Masuk" onerror="this.style.display='none'">
                </div>
                <?php endif; ?>
                <?php if ($fotoKeluar): ?>
                <div class="abs-foto-item">
                    <div class="abs-foto-label"><i class="fas fa-sign-out-alt" style="color:#f59e0b"></i> Foto Keluar</div>
                    <img src="<?= htmlspecialchars($fotoKeluar) ?>" alt="Foto Keluar" onerror="this.style.display='none'">
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- LAPORAN KEHADIRAN -->
    <div class="abs-sec-title">Laporan Kehadiran</div>
    <button class="abs-full-btn abs-fb-dinas" onclick="absOpenSheet('dinas')">
        <div class="abs-fb-icon" style="background:#eff6ff"><i class="fas fa-briefcase" style="color:#0f4c81;font-size:19px"></i></div>
        <div><div class="abs-fb-label">Dinas Luar</div><div class="abs-fb-sub">Upload surat tugas &amp; keterangan</div></div>
        <div class="abs-fb-arrow"><i class="fas fa-chevron-right"></i></div>
    </button>
    <button class="abs-full-btn abs-fb-sakit" onclick="absOpenSheet('sakit')">
        <div class="abs-fb-icon" style="background:#fef2f2"><i class="fas fa-hospital" style="color:#ef4444;font-size:19px"></i></div>
        <div><div class="abs-fb-label">Sakit</div><div class="abs-fb-sub">Upload surat sakit &amp; keterangan</div></div>
        <div class="abs-fb-arrow"><i class="fas fa-chevron-right"></i></div>
    </button>
    <button class="abs-full-btn abs-fb-izin" onclick="absOpenSheet('izin')">
        <div class="abs-fb-icon" style="background:#faf5ff"><i class="fas fa-file-circle-check" style="color:#8b5cf6;font-size:19px"></i></div>
        <div><div class="abs-fb-label">Izin</div><div class="abs-fb-sub">Upload surat izin &amp; keterangan</div></div>
        <div class="abs-fb-arrow"><i class="fas fa-chevron-right"></i></div>
    </button>

    <!-- PETA -->
    <?php if (!empty($lokasiKaryawan)): ?>
    <div class="abs-map-card">
        <div class="abs-map-head"><i class="fas fa-map-location-dot" style="color:#0f4c81"></i> Peta Lokasi Absen</div>
        <div id="absMap"></div>
        <div class="abs-map-info">
            <div id="absCoordInfo"><i class="fas fa-crosshairs"></i> Mendeteksi koordinat...</div>
            <div id="absDistInfo"><i class="fas fa-ruler"></i> Menghitung jarak...</div>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /.abs-page -->

<!-- ════ BOTTOM SHEET: ABSEN MASUK / KELUAR ════ -->
<div class="abs-overlay" id="overlayAbsen">
    <div class="abs-sheet">
        <div class="abs-sheet-handle"></div>
        <div class="abs-sheet-header">
            <div class="abs-sheet-title" id="absSheetTitle">Absen Masuk</div>
            <button class="abs-sheet-close" onclick="absCloseAbsen()"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="abs-sheet-body">
            <!-- Step 1 -->
            <div id="absStep1">
                <div class="abs-steps"><div class="abs-step-dot active" id="sd1"></div><div class="abs-step-dot" id="sd2"></div><div class="abs-step-dot" id="sd3"></div></div>
                <div class="abs-info" id="absGpsInfoBox"><i class="fas fa-location-dot"></i><span id="absGpsInfoText">Memuat data lokasi...</span></div>
                <div class="abs-data-grid">
                    <div class="abs-data-box"><div class="abs-data-label">Waktu</div><div class="abs-data-val" id="absStep1Clock" style="font-size:22px">--:--</div></div>
                    <div class="abs-data-box"><div class="abs-data-label">Jadwal</div><div class="abs-data-val" id="absStep1Jadwal" style="font-size:15px"><?= $jadwalInfo ? substr($jadwalInfo['jam_masuk'],0,5) : '--:--' ?></div></div>
                </div>
                <div class="abs-data-box" style="margin-bottom:14px"><div class="abs-data-label">Lokasi Terdeteksi</div><div class="abs-data-val" id="absStep1Lokasi" style="font-size:13px;color:#10b981">--</div></div>
                <button class="abs-btn-primary full abs-bg-blue" id="absBtn1Lanjut" onclick="absGoStep2()" disabled><i class="fas fa-camera"></i> Ambil Foto Selfie</button>
            </div>
            <!-- Step 2 -->
            <div id="absStep2" style="display:none">
                <div class="abs-steps"><div class="abs-step-dot done"></div><div class="abs-step-dot active"></div><div class="abs-step-dot"></div></div>
                <p style="font-size:12px;color:#64748b;font-weight:700;text-align:center;margin-bottom:10px"><i class="fas fa-circle-info"></i> Posisikan wajah dalam panduan oval</p>
                <div class="abs-cam-wrap">
                    <video id="absVideoEl" autoplay playsinline muted></video>
                    <div class="abs-face-guide"><div class="abs-face-label">Wajah di sini</div></div>
                </div>
                <div class="abs-cam-err" id="absCamErr"><i class="fas fa-video-slash"></i> Kamera tidak dapat diakses.</div>
                <canvas id="absCaptureCanvas" style="display:none"></canvas>
                <div class="abs-btn-row">
                    <button class="abs-btn-back" onclick="absGoStep1()"><i class="fas fa-arrow-left"></i> Kembali</button>
                    <button class="abs-btn-primary abs-bg-blue" onclick="absCapturePhoto()"><i class="fas fa-camera"></i> Ambil Foto</button>
                </div>
            </div>
            <!-- Step 3 -->
            <div id="absStep3" style="display:none">
                <div class="abs-steps"><div class="abs-step-dot done"></div><div class="abs-step-dot done"></div><div class="abs-step-dot active"></div></div>
                <div class="abs-info ok" style="justify-content:center;margin-bottom:12px"><i class="fas fa-check-circle"></i> Foto berhasil diambil</div>
                <img id="absSelfiePreview" class="abs-selfie-preview" alt="Selfie">
                <div class="abs-result-box" style="margin-bottom:14px">
                    <div class="abs-result-row"><span class="abs-result-key"><i class="fas fa-clock"></i> Waktu</span><span class="abs-result-val" id="absStep3Waktu">--:--:--</span></div>
                    <div class="abs-result-row"><span class="abs-result-key"><i class="fas fa-location-dot"></i> Lokasi</span><span class="abs-result-val" id="absStep3Lokasi">--</span></div>
                    <div class="abs-result-row"><span class="abs-result-key"><i class="fas fa-ruler"></i> Jarak</span><span class="abs-result-val" id="absStep3Jarak">--</span></div>
                    <div class="abs-result-row" style="border:none"><span class="abs-result-key"><i class="fas fa-circle-info"></i> Status</span><span class="abs-result-val" style="color:#10b981" id="absStep3Status">--</span></div>
                </div>
                <div class="abs-btn-row">
                    <button class="abs-btn-back" onclick="absRetakePhoto()"><i class="fas fa-rotate-left"></i> Ulangi</button>
                    <button class="abs-btn-primary abs-bg-green" id="absSubmitBtn" onclick="absSubmitAbsen()"><i class="fas fa-check"></i> Konfirmasi Absen</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- SHEET DINAS -->
<div class="abs-overlay" id="overlayDinas">
    <div class="abs-sheet">
        <div class="abs-sheet-handle"></div>
        <div class="abs-sheet-header">
            <div class="abs-sheet-title"><i class="fas fa-briefcase" style="color:#0f4c81;margin-right:6px"></i>Dinas Luar</div>
            <button class="abs-sheet-close" onclick="absCloseSheet('Dinas')"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="abs-sheet-body">
            <div class="abs-form-row"><label class="abs-form-label">Tanggal Dinas</label><input type="date" class="abs-form-control" id="dinasTgl"></div>
            <div class="abs-form-row"><label class="abs-form-label">Tujuan / Tempat Dinas</label><input type="text" class="abs-form-control" id="dinasTujuan" placeholder="Contoh: Jakarta, PT. ABC"></div>
            <div class="abs-form-row"><label class="abs-form-label">Keterangan</label><textarea class="abs-form-control" id="dinasKet" placeholder="Jelaskan keperluan dinas luar..."></textarea></div>
            <div class="abs-form-row">
                <label class="abs-form-label">Surat Tugas <span style="color:#ef4444">*</span></label>
                <div class="abs-upload-area" id="dinasUploadArea" onclick="document.getElementById('dinasBukti').click()">
                    <div class="abs-upload-icon">📎</div><div class="abs-upload-text">Tap untuk upload file</div><div class="abs-upload-sub">JPG, PNG, PDF · Maks 5MB</div>
                </div>
                <input type="file" id="dinasBukti" accept="image/*,.pdf" style="display:none" onchange="absHandleFile('dinasBukti','dinasUploadArea')">
            </div>
            <button class="abs-btn-primary full abs-bg-blue" onclick="absSubmitLaporan('dinas')"><i class="fas fa-paper-plane"></i> Kirim Laporan Dinas</button>
        </div>
    </div>
</div>

<!-- SHEET SAKIT -->
<div class="abs-overlay" id="overlaySakit">
    <div class="abs-sheet">
        <div class="abs-sheet-handle"></div>
        <div class="abs-sheet-header">
            <div class="abs-sheet-title"><i class="fas fa-hospital" style="color:#ef4444;margin-right:6px"></i>Sakit</div>
            <button class="abs-sheet-close" onclick="absCloseSheet('Sakit')"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="abs-sheet-body">
            <div class="abs-form-row"><label class="abs-form-label">Tanggal Sakit</label><input type="date" class="abs-form-control" id="sakitTgl"></div>
            <div class="abs-form-row"><label class="abs-form-label">Keterangan</label><textarea class="abs-form-control" id="sakitKet" placeholder="Jelaskan kondisi sakit Anda..."></textarea></div>
            <div class="abs-form-row">
                <label class="abs-form-label">Surat Keterangan Sakit <span style="color:#64748b;font-weight:600;text-transform:none;font-size:11px">(opsional)</span></label>
                <div class="abs-upload-area" id="sakitUploadArea" onclick="document.getElementById('sakitBukti').click()">
                    <div class="abs-upload-icon">🏥</div><div class="abs-upload-text">Upload surat dokter</div><div class="abs-upload-sub">JPG, PNG, PDF · Maks 5MB (opsional)</div>
                </div>
                <input type="file" id="sakitBukti" accept="image/*,.pdf" style="display:none" onchange="absHandleFile('sakitBukti','sakitUploadArea')">
            </div>
            <button class="abs-btn-primary full abs-bg-red" onclick="absSubmitLaporan('sakit')"><i class="fas fa-paper-plane"></i> Kirim Laporan Sakit</button>
        </div>
    </div>
</div>

<!-- SHEET IZIN -->
<div class="abs-overlay" id="overlayIzin">
    <div class="abs-sheet">
        <div class="abs-sheet-handle"></div>
        <div class="abs-sheet-header">
            <div class="abs-sheet-title"><i class="fas fa-file-circle-check" style="color:#8b5cf6;margin-right:6px"></i>Izin</div>
            <button class="abs-sheet-close" onclick="absCloseSheet('Izin')"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="abs-sheet-body">
            <div class="abs-form-row"><label class="abs-form-label">Tanggal Izin</label><input type="date" class="abs-form-control" id="izinTgl"></div>
            <div class="abs-form-row"><label class="abs-form-label">Jenis Izin</label>
                <select class="abs-form-control" id="izinJenis">
                    <option value="">Pilih jenis izin...</option>
                    <option value="keluarga">Urusan Keluarga</option>
                    <option value="pribadi">Keperluan Pribadi</option>
                    <option value="darurat">Kondisi Darurat</option>
                    <option value="lainnya">Lainnya</option>
                </select>
            </div>
            <div class="abs-form-row"><label class="abs-form-label">Keterangan</label><textarea class="abs-form-control" id="izinKet" placeholder="Jelaskan keperluan izin Anda..."></textarea></div>
            <div class="abs-form-row">
                <label class="abs-form-label">Surat Izin <span style="color:#64748b;font-weight:600;text-transform:none;font-size:11px">(opsional)</span></label>
                <div class="abs-upload-area" id="izinUploadArea" onclick="document.getElementById('izinBukti').click()">
                    <div class="abs-upload-icon">📋</div><div class="abs-upload-text">Upload surat izin</div><div class="abs-upload-sub">JPG, PNG, PDF · Maks 5MB (opsional)</div>
                </div>
                <input type="file" id="izinBukti" accept="image/*,.pdf" style="display:none" onchange="absHandleFile('izinBukti','izinUploadArea')">
            </div>
            <button class="abs-btn-primary full abs-bg-purple" onclick="absSubmitLaporan('izin')"><i class="fas fa-paper-plane"></i> Kirim Permohonan Izin</button>
        </div>
    </div>
</div>

<!-- NOTIFIKASI -->
<div class="abs-overlay" id="overlayNotif">
    <div class="abs-sheet">
        <div class="abs-sheet-handle"></div>
        <div class="abs-sheet-body" style="text-align:center;padding-top:20px">
            <div class="abs-notif-circle" id="absNotifCircle">✅</div>
            <div class="abs-notif-title" id="absNotifTitle">Berhasil!</div>
            <div class="abs-notif-sub"   id="absNotifSub"></div>
            <div class="abs-result-box"  id="absNotifRows"></div>
            <button class="abs-btn-primary full abs-bg-blue" id="absNotifBtn" onclick="absCloseNotif()"><i class="fas fa-check"></i> Tutup</button>
        </div>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const ABS_LOKASI = <?= json_encode(array_map(fn($l) => [
    'id'           => (int)$l['id'],
    'nama'         => $l['nama'],
    'latitude'     => (float)$l['latitude'],
    'longitude'    => (float)$l['longitude'],
    'radius_meter' => (int)$l['radius_meter'],
], $lokasiKaryawan)) ?>;

const ABS_SUDAH_MASUK  = <?= $sudahMasuk  ? 'true' : 'false' ?>;
const ABS_SUDAH_KELUAR = <?= $sudahKeluar ? 'true' : 'false' ?>;
const ABS_MASUK_TS     = <?= ($sudahMasuk && !$sudahKeluar) ? strtotime($absenToday['waktu_masuk']).'000' : '0' ?>;
const ABS_JAM_MASUK    = '<?= $jadwalInfo ? substr($jadwalInfo['jam_masuk'],0,5) : '' ?>';
const ABS_JAM_KELUAR   = '<?= $jadwalInfo ? substr($jadwalInfo['jam_keluar'],0,5) : '' ?>';

let absLat=null, absLng=null, absAcc=9999, absSpeed=null;
let absLokasiMatch=null, absGpsWatchId=null;
let absAbsenType=null, absPhotoData=null, absCameraStream=null;
let absMap=null, absMyMarker=null;

const absMapCenter = ABS_LOKASI.length>0
    ? [ABS_LOKASI.reduce((s,l)=>s+l.latitude,0)/ABS_LOKASI.length,
       ABS_LOKASI.reduce((s,l)=>s+l.longitude,0)/ABS_LOKASI.length]
    : [-6.2088, 106.8456];

if (document.getElementById('absMap')) {
    absMap = L.map('absMap',{zoomControl:false,attributionControl:false}).setView(absMapCenter,15);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(absMap);
    ABS_LOKASI.forEach(l=>{
        L.marker([l.latitude,l.longitude],{icon:L.divIcon({html:`<div style="background:#0f4c81;color:#fff;padding:4px 10px;border-radius:8px;font-size:11px;font-weight:800;white-space:nowrap;box-shadow:0 2px 8px rgba(0,0,0,.2)">📍 ${l.nama}</div>`,className:'',iconAnchor:[0,0]})}).addTo(absMap);
        L.circle([l.latitude,l.longitude],{radius:l.radius_meter,color:'#0f4c81',fillColor:'#0f4c81',fillOpacity:.08,weight:1.5}).addTo(absMap);
    });
}

function absPad(n){ return String(n).padStart(2,'0'); }

function absTick(){
    const n=new Date();
    const t=absPad(n.getHours())+':'+absPad(n.getMinutes())+':'+absPad(n.getSeconds());
    const el=document.getElementById('absClock'); if(el) el.textContent=t;
    const s1=document.getElementById('absStep1Clock'); if(s1) s1.textContent=absPad(n.getHours())+':'+absPad(n.getMinutes());
    if(ABS_SUDAH_MASUK && !ABS_SUDAH_KELUAR && ABS_MASUK_TS>0){
        const diff=Math.floor((Date.now()-ABS_MASUK_TS)/60000);
        const h=Math.floor(diff/3600),m=Math.floor((diff%3600)/60);
        const dd=document.getElementById('dispDurasi');
        if(dd){ dd.textContent=(h>0?h+'j ':'')+m+'m'; dd.style.color='#0f172a'; }
    }
}
setInterval(absTick,1000); absTick();

function absHaversine(la1,lo1,la2,lo2){
    const R=6371000,dL=(la2-la1)*Math.PI/180,dG=(lo2-lo1)*Math.PI/180;
    const a=Math.sin(dL/2)**2+Math.cos(la1*Math.PI/180)*Math.cos(la2*Math.PI/180)*Math.sin(dG/2)**2;
    return Math.round(R*2*Math.atan2(Math.sqrt(a),Math.sqrt(1-a)));
}

function absUpdateGPS(pos){
    absLat=pos.coords.latitude; absLng=pos.coords.longitude;
    absAcc=pos.coords.accuracy??9999; absSpeed=pos.coords.speed;

    absLokasiMatch=null;
    let minJ=Infinity, minN='-';
    ABS_LOKASI.forEach(l=>{
        const j=absHaversine(absLat,absLng,l.latitude,l.longitude);
        if(j<minJ){minJ=j;minN=l.nama;}
        if(j<=l.radius_meter&&(!absLokasiMatch||j<absLokasiMatch.jarak))
            absLokasiMatch={id:l.id,nama:l.nama,jarak:j};
    });

    const gpsOk=absLokasiMatch!==null&&absAcc<=150;
    const dot=document.getElementById('absGpsDot'),txt=document.getElementById('absGpsText');
    if(dot) dot.classList.toggle('ok',absLokasiMatch!==null);
    if(txt) txt.textContent=absLokasiMatch
        ?`Zona ${absLokasiMatch.nama} (${absLokasiMatch.jarak}m) ±${Math.round(absAcc)}m ✅`
        :`Di luar zona · Terdekat: ${minN} (${minJ}m)`;

    const bM=document.getElementById('btnMasuk'),bK=document.getElementById('btnKeluar');
    if(bM&&!ABS_SUDAH_MASUK){ bM.disabled=!gpsOk; const s=document.getElementById('masukSub'); if(s) s.textContent=gpsOk?'Tap untuk absen masuk':(absAcc>150?`GPS kurang akurat (±${Math.round(absAcc)}m)`:'Di luar zona kerja'); }
    if(bK&&ABS_SUDAH_MASUK&&!ABS_SUDAH_KELUAR){ bK.disabled=!gpsOk; const s=document.getElementById('keluarSub'); if(s) s.textContent=gpsOk?'Tap untuk absen keluar':'Di luar zona kerja'; }

    const ci=document.getElementById('absCoordInfo'),di=document.getElementById('absDistInfo');
    if(ci) ci.innerHTML=`<i class="fas fa-crosshairs"></i> ${absLat.toFixed(6)}, ${absLng.toFixed(6)} ±${Math.round(absAcc)}m`;
    if(di){
        if(absLokasiMatch) di.innerHTML=`<i class="fas fa-check-circle" style="color:#10b981"></i> Dalam zona ${absLokasiMatch.nama} (${absLokasiMatch.jarak}m)`;
        else if(ABS_LOKASI.length>0) di.innerHTML=`<i class="fas fa-times" style="color:#ef4444"></i> Di luar zona · ${minN} ${minJ}m`;
    }
    if(absMap){
        if(absMyMarker) absMyMarker.setLatLng([absLat,absLng]);
        else absMyMarker=L.marker([absLat,absLng],{icon:L.divIcon({html:'<div style="background:#00c9a7;color:#fff;padding:4px 10px;border-radius:8px;font-size:11px;font-weight:800;box-shadow:0 2px 8px rgba(0,0,0,.2)">📱 Saya</div>',className:'',iconAnchor:[0,0]})}).addTo(absMap);
    }
    const gi=document.getElementById('absGpsInfoBox'),gt=document.getElementById('absGpsInfoText'),sl=document.getElementById('absStep1Lokasi');
    if(gi&&gt){
        gi.className='abs-info '+(gpsOk?'ok':(absLokasiMatch?'warn':'danger'));
        gt.textContent=gpsOk?`Dalam zona ${absLokasiMatch.nama} · ${absLokasiMatch.jarak}m · ±${Math.round(absAcc)}m`
            :(absLokasiMatch?`Akurasi GPS rendah (±${Math.round(absAcc)}m)`:`Di luar semua zona · ${minN} ${minJ}m`);
    }
    if(sl) sl.textContent=absLokasiMatch?`${absLokasiMatch.nama} (${absLokasiMatch.jarak}m dari titik)`:`Tidak dalam zona — ${minN} ${minJ}m`;
    const bl=document.getElementById('absBtn1Lanjut'); if(bl) bl.disabled=!gpsOk;
}

function absGpsError(err){
    const dot=document.getElementById('absGpsDot'),txt=document.getElementById('absGpsText');
    if(dot){dot.style.background='#ef4444';dot.style.boxShadow='0 0 0 3px rgba(239,68,68,.2)';}
    if(txt) txt.textContent=err.code===1?'Izin lokasi ditolak':'GPS tidak tersedia';
}

navigator.geolocation&&(absGpsWatchId=navigator.geolocation.watchPosition(absUpdateGPS,absGpsError,{enableHighAccuracy:true,timeout:20000,maximumAge:5000}));

// ── Absen logic ──
function absOpenAbsen(type){
    if(!absLat||!absLng){alert('GPS belum terdeteksi. Tunggu sebentar.');return;}
    absAbsenType=type; absPhotoData=null;
    document.getElementById('absSheetTitle').textContent=type==='masuk'?'🟢 Absen Masuk':'🟡 Absen Keluar';
    document.getElementById('absStep1Jadwal').textContent=type==='masuk'?ABS_JAM_MASUK:ABS_JAM_KELUAR;
    const sb=document.getElementById('absSubmitBtn');
    if(sb) sb.className='abs-btn-primary '+(type==='masuk'?'abs-bg-green':'abs-bg-amber');
    absShowStep(1);
    document.getElementById('overlayAbsen').classList.add('open');
}
function absCloseAbsen(){ document.getElementById('overlayAbsen').classList.remove('open'); absStopCamera(); }
function absShowStep(n){ ['absStep1','absStep2','absStep3'].forEach((id,i)=>{ document.getElementById(id).style.display=(i+1===n)?'block':'none'; }); }
function absGoStep1(){ absStopCamera(); absShowStep(1); }
function absGoStep2(){
    absShowStep(2);
    document.getElementById('absCamErr').style.display='none';
    navigator.mediaDevices.getUserMedia({video:{facingMode:'user',width:{ideal:640},height:{ideal:480}},audio:false})
        .then(s=>{ absCameraStream=s; document.getElementById('absVideoEl').srcObject=s; })
        .catch(()=>{ document.getElementById('absCamErr').style.display='block'; });
}
function absStopCamera(){
    if(absCameraStream){absCameraStream.getTracks().forEach(t=>t.stop());absCameraStream=null;}
    const v=document.getElementById('absVideoEl'); if(v) v.srcObject=null;
}
function absCapturePhoto(){
    const video=document.getElementById('absVideoEl'),canvas=document.getElementById('absCaptureCanvas');
    if(!video.srcObject){alert('Kamera belum siap.');return;}
    const MAX=480,ratio=Math.min(1,MAX/(video.videoWidth||640));
    canvas.width=Math.round((video.videoWidth||640)*ratio);
    canvas.height=Math.round((video.videoHeight||480)*ratio);
    const ctx=canvas.getContext('2d');
    ctx.translate(canvas.width,0); ctx.scale(-1,1);
    ctx.drawImage(video,0,0,canvas.width,canvas.height);
    absPhotoData=canvas.toDataURL('image/jpeg',0.7);
    document.getElementById('absSelfiePreview').src=absPhotoData;
    absStopCamera();
    const n=new Date();
    document.getElementById('absStep3Waktu').textContent=absPad(n.getHours())+':'+absPad(n.getMinutes())+':'+absPad(n.getSeconds());
    document.getElementById('absStep3Lokasi').textContent=absLokasiMatch?absLokasiMatch.nama:'-';
    document.getElementById('absStep3Jarak').textContent=absLokasiMatch?absLokasiMatch.jarak+'m dari titik':'-';
    document.getElementById('absStep3Status').textContent='✅ Lokasi Valid';
    absShowStep(3);
}
function absRetakePhoto(){ absPhotoData=null; absGoStep2(); }
function absSubmitAbsen(){
    if(!absPhotoData){alert('Foto wajah belum diambil.');return;}
    const btn=document.getElementById('absSubmitBtn');
    btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Menyimpan...';
    fetch('../api/absen.php',{
        method:'POST',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({type:absAbsenType,lat:absLat,lng:absLng,foto:absPhotoData,accuracy:absAcc,gps_age:0,speed:absSpeed,lokasi_id:absLokasiMatch?absLokasiMatch.id:null,lokasi_nama:absLokasiMatch?absLokasiMatch.nama:null})
    })
    .then(r=>{ if(!r.headers.get('content-type')?.includes('application/json')) return r.text().then(t=>{throw new Error(t.substring(0,300));}); return r.json(); })
    .then(data=>{ absCloseAbsen(); data.success?absShowNotif(data):absShowNotifGagal(data.message||'Terjadi kesalahan.'); })
    .catch(err=>{ absCloseAbsen(); absShowNotifGagal(err.message||'Gagal menghubungi server.'); });
}

// ── Laporan logic ──
function absOpenSheet(type){
    const today=new Date().toISOString().split('T')[0];
    if(type==='dinas') document.getElementById('dinasTgl').value=today;
    else if(type==='sakit') document.getElementById('sakitTgl').value=today;
    else if(type==='izin') document.getElementById('izinTgl').value=today;
    document.getElementById('overlay'+type.charAt(0).toUpperCase()+type.slice(1)).classList.add('open');
}
function absCloseSheet(type){ document.getElementById('overlay'+type).classList.remove('open'); }

function absHandleFile(inputId,areaId){
    const input=document.getElementById(inputId),area=document.getElementById(areaId);
    if(!input||!input.files||!input.files[0]) return;
    const file=input.files[0];
    area.classList.add('has-file');
    area.innerHTML=`<div class="abs-upload-icon">✅</div><div class="abs-upload-text">${file.name}</div><div class="abs-upload-sub">${(file.size/1024/1024).toFixed(2)} MB · Tap untuk ganti</div>`;
    area.onclick=()=>input.click();
}

function absSubmitLaporan(type){
    let tgl,ket,buktiInput,extraData={};
    if(type==='dinas'){
        tgl=document.getElementById('dinasTgl').value;
        const tj=document.getElementById('dinasTujuan').value.trim();
        ket=document.getElementById('dinasKet').value.trim();
        buktiInput=document.getElementById('dinasBukti');
        if(!tgl||!tj||!ket){alert('Tanggal, tujuan, dan keterangan wajib diisi!');return;}
        if(!buktiInput.files||!buktiInput.files[0]){alert('Surat tugas wajib diupload!');return;}
        extraData.tujuan=tj;
    } else if(type==='sakit'){
        tgl=document.getElementById('sakitTgl').value;
        ket=document.getElementById('sakitKet').value.trim();
        buktiInput=document.getElementById('sakitBukti');
        if(!tgl||!ket){alert('Tanggal dan keterangan wajib diisi!');return;}
        // bukti opsional untuk sakit
    } else if(type==='izin'){
        tgl=document.getElementById('izinTgl').value;
        const jn=document.getElementById('izinJenis').value;
        ket=document.getElementById('izinKet').value.trim();
        buktiInput=document.getElementById('izinBukti');
        if(!tgl||!jn||!ket){alert('Semua field wajib diisi!');return;}
        extraData.jenis=jn;
    }
    const fd=new FormData();
    fd.append('type',type); fd.append('tanggal',tgl); fd.append('keterangan',ket);
    Object.entries(extraData).forEach(([k,v])=>fd.append(k,v));
    if(buktiInput&&buktiInput.files&&buktiInput.files[0]) fd.append('bukti',buktiInput.files[0]);
    absCloseSheet(type.charAt(0).toUpperCase()+type.slice(1));
    fetch('../api/laporan.php',{method:'POST',body:fd})
        .then(r=>{ if(!r.headers.get('content-type')?.includes('application/json')) return r.text().then(t=>{throw new Error(t.substring(0,200));}); return r.json(); })
        .then(data=>{ data.success?absShowNotifLaporan(type,data):absShowNotifGagal(data.message||'Gagal menyimpan laporan.'); })
        .catch(err=>absShowNotifGagal(err.message||'Gagal menghubungi server.'));
}

// ── Notif ──
function absShowNotif(data){
    const notif=data.notif||{},color=notif.color||'#10b981';
    const nc=document.getElementById('absNotifCircle');
    nc.textContent=notif.icon||'✅'; nc.style.cssText=`background:${notif.bg||'#ecfdf5'};width:72px;height:72px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:34px;margin:0 auto 12px;animation:absPopIn .4s cubic-bezier(.34,1.56,.64,1)`;
    document.getElementById('absNotifTitle').textContent=notif.title||'Berhasil!';
    document.getElementById('absNotifSub').textContent=data.lokasi||'';
    document.getElementById('absNotifBtn').className='abs-btn-primary full '+(notif.type==='terlambat'?'abs-bg-amber':notif.type==='pulang_cepat'?'abs-bg-purple':'abs-bg-green');
    document.getElementById('absNotifRows').innerHTML=`
        <div class="abs-result-row"><span class="abs-result-key">Jam Absen</span><span class="abs-result-val" style="color:${color}">${data.jam_absen||''}</span></div>
        <div class="abs-result-row"><span class="abs-result-key">Jam Jadwal</span><span class="abs-result-val">${data.jam_shift||''}</span></div>
        <div class="abs-result-row" style="border:none"><span class="abs-result-key">Lokasi</span><span class="abs-result-val">${data.lokasi||'-'}</span></div>
        ${data.warning?`<div style="margin-top:8px;padding:8px 12px;background:#fffbeb;border-radius:8px;font-size:12px;font-weight:700;color:#78350f;text-align:left"><i class="fas fa-shield-halved"></i> ${data.warning}</div>`:''}`;
    document.getElementById('overlayNotif').classList.add('open');
}

function absShowNotifLaporan(type, data){
    const cfg = {
        dinas: { icon:'🏢', bg:'#eff6ff', title:'Dinas Luar Tercatat!',  btn:'abs-bg-blue'   },
        sakit: { icon:'🏥', bg:'#fef2f2', title:'Sakit Tercatat!',       btn:'abs-bg-red'    },
        izin:  { icon:'📋', bg:'#faf5ff', title:'Izin Tercatat!',        btn:'abs-bg-purple' },
    }[type];
    const nc=document.getElementById('absNotifCircle');
    nc.textContent=cfg.icon;
    nc.style.cssText=`background:${cfg.bg};width:72px;height:72px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:34px;margin:0 auto 12px;animation:absPopIn .4s cubic-bezier(.34,1.56,.64,1)`;
    document.getElementById('absNotifTitle').textContent=cfg.title;
    document.getElementById('absNotifSub').textContent='';  // tidak ada teks approval
    document.getElementById('absNotifBtn').className='abs-btn-primary full '+cfg.btn;
    document.getElementById('absNotifRows').innerHTML=`
        <div class="abs-result-row"><span class="abs-result-key">Tanggal</span><span class="abs-result-val">${data.tanggal||'-'}</span></div>
        <div class="abs-result-row" style="border:none"><span class="abs-result-key">Status</span><span class="abs-result-val" style="color:#10b981">✅ Berhasil Dicatat</span></div>`;
    document.getElementById('overlayNotif').classList.add('open');
}

function absShowNotifGagal(msg){
    const nc=document.getElementById('absNotifCircle');
    nc.textContent='❌'; nc.style.cssText='background:#fef2f2;width:72px;height:72px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:34px;margin:0 auto 12px';
    document.getElementById('absNotifTitle').textContent='Gagal!';
    document.getElementById('absNotifSub').textContent='';
    document.getElementById('absNotifBtn').className='abs-btn-primary full abs-bg-red';
    document.getElementById('absNotifRows').innerHTML=`<div style="padding:10px;color:#991b1b;font-size:13px;font-weight:700;text-align:center">${msg}</div>`;
    document.getElementById('overlayNotif').classList.add('open');
}
function absCloseNotif(){ document.getElementById('overlayNotif').classList.remove('open'); location.reload(); }

document.querySelectorAll('.abs-overlay').forEach(o=>{
    o.addEventListener('click',e=>{ if(e.target===o) o.classList.remove('open'); });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>