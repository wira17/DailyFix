<?php
require_once __DIR__ . '/../includes/config.php';
requireAdmin();

$pageTitle  = 'Rekap Semua Karyawan';
$activePage = 'rekap_admin';
$user       = currentUser();
$db         = getDB();

$monthsId = ['','Januari','Februari','Maret','April','Mei','Juni',
             'Juli','Agustus','September','Oktober','November','Desember'];
$hariId   = ['Sun'=>'Min','Mon'=>'Sen','Tue'=>'Sel','Wed'=>'Rab',
             'Thu'=>'Kam','Fri'=>'Jum','Sat'=>'Sab'];

$mode_filter   = $_GET['mode_filter']   ?? 'bulanan';
$bulan         = (int)($_GET['bulan']   ?? date('m'));
$tahun         = (int)($_GET['tahun']   ?? date('Y'));
$tgl_dari      = $_GET['tgl_dari']      ?? date('Y-m-01');
$tgl_sampai    = $_GET['tgl_sampai']    ?? date('Y-m-d');
$karyawan_id   = (int)($_GET['karyawan_id']   ?? 0);
$departemen_id = (int)($_GET['departemen_id'] ?? 0);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl_dari))   $tgl_dari   = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl_sampai)) $tgl_sampai = date('Y-m-d');

if ($mode_filter === 'periode') {
    $period_label = 'Periode ' . date('d/m/Y', strtotime($tgl_dari)) . ' – ' . date('d/m/Y', strtotime($tgl_sampai));
    $date_cond    = "a.tanggal BETWEEN ? AND ?";
    $date_params  = [$tgl_dari, $tgl_sampai];
} else {
    $period       = $tahun . '-' . str_pad($bulan, 2, '0', STR_PAD_LEFT);
    $period_label = $monthsId[$bulan] . ' ' . $tahun;
    $date_cond    = "DATE_FORMAT(a.tanggal,'%Y-%m')=?";
    $date_params  = [$period];
}

$stmtDep = $db->prepare("SELECT id, nama FROM departemen WHERE perusahaan_id=? ORDER BY nama");
$stmtDep->execute([$user['perusahaan_id']]);
$allDepartemen = $stmtDep->fetchAll();

$sql = "SELECT k.id, k.nik, k.nama,
    d.nama as departemen_nama,
    SUM(CASE WHEN a.status_kehadiran IN ('hadir','terlambat') THEN 1 ELSE 0 END)  AS hadir,
    SUM(CASE WHEN a.status_kehadiran = 'terlambat'   THEN 1 ELSE 0 END)           AS terlambat,
    SUM(CASE WHEN a.status_kehadiran = 'absen'       THEN 1 ELSE 0 END)           AS absen,
    SUM(CASE WHEN a.status_kehadiran = 'izin'        THEN 1 ELSE 0 END)           AS izin,
    SUM(CASE WHEN a.status_kehadiran = 'sakit'       THEN 1 ELSE 0 END)           AS sakit,
    SUM(CASE WHEN a.status_kehadiran = 'dinas_luar'  THEN 1 ELSE 0 END)           AS dinas_luar,
    SUM(CASE WHEN IFNULL(a.pulang_cepat_detik,0) > 0 THEN 1 ELSE 0 END)          AS pulang_cepat,
    SUM(COALESCE(a.terlambat_detik,0))                                            AS total_terlambat_detik,
    SUM(COALESCE(a.pulang_cepat_detik,0))                                         AS total_pulang_cepat_detik,
    SUM(COALESCE(a.durasi_kerja,0))                                               AS total_durasi
    FROM karyawan k
    LEFT JOIN departemen d ON d.id = k.departemen_id
    LEFT JOIN absensi a ON a.karyawan_id = k.id AND {$date_cond}
    WHERE k.perusahaan_id=? AND k.role='karyawan'";

$params = array_merge($date_params, [$user['perusahaan_id']]);
if ($departemen_id) { $sql .= " AND k.departemen_id=?"; $params[] = $departemen_id; }
if ($karyawan_id)   { $sql .= " AND k.id=?";            $params[] = $karyawan_id; }
$sql .= " GROUP BY k.id ORDER BY d.nama, k.nama";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rekapKaryawan = $stmt->fetchAll();

$detailAbsen = [];
$selectedK   = null;
if ($karyawan_id) {
    // Ambil juga keterangan & bukti_file
    $sqlD = "SELECT a.*, s.nama as shift_nama, s.jam_masuk, s.jam_keluar, l.nama as lokasi_nama
        FROM absensi a
        LEFT JOIN shift s  ON s.id = a.shift_id
        LEFT JOIN lokasi l ON l.id = a.lokasi_id
        WHERE a.karyawan_id=? AND {$date_cond}
        ORDER BY a.tanggal";
    $stmtD = $db->prepare($sqlD);
    $stmtD->execute(array_merge([$karyawan_id], $date_params));
    $detailAbsen = $stmtD->fetchAll();

    $stmtK = $db->prepare("SELECT k.*, dep.nama as departemen_nama FROM karyawan k LEFT JOIN departemen dep ON dep.id=k.departemen_id WHERE k.id=? AND k.perusahaan_id=?");
    $stmtK->execute([$karyawan_id, $user['perusahaan_id']]);
    $selectedK = $stmtK->fetch();
}

$stmtAK = $db->prepare("SELECT id,nama,nik FROM karyawan WHERE perusahaan_id=? AND role='karyawan' ORDER BY nama");
$stmtAK->execute([$user['perusahaan_id']]);
$allKaryawan = $stmtAK->fetchAll();

// ── Ambil semua bukti dinas/sakit/izin untuk tabel ringkasan ──
$sqlBukti = "SELECT a.karyawan_id, a.tanggal, a.status_kehadiran, a.keterangan, a.bukti_file,
    k.nama as karyawan_nama
    FROM absensi a
    JOIN karyawan k ON k.id = a.karyawan_id
    WHERE k.perusahaan_id = ?
      AND a.status_kehadiran IN ('dinas_luar','sakit','izin')
      AND {$date_cond}
    ORDER BY a.karyawan_id, a.tanggal";
$stmtBukti = $db->prepare($sqlBukti);
$stmtBukti->execute(array_merge([$user['perusahaan_id']], $date_params));
$allBuktiRows = $stmtBukti->fetchAll();

// Index per karyawan_id: array of record
$buktiPerKaryawan = [];
foreach ($allBuktiRows as $bk) {
    $buktiPerKaryawan[$bk['karyawan_id']][] = $bk;
}

function hitungHariKerja($dari, $sampai) {
    $count = 0; $d = strtotime($dari); $s = strtotime($sampai);
    while ($d <= $s) { if ((int)date('N',$d) <= 5) $count++; $d = strtotime('+1 day',$d); }
    return $count;
}
if ($mode_filter === 'periode') {
    $jumlahHariKerja = hitungHariKerja($tgl_dari, $tgl_sampai);
} else {
    $jumlahHariKerja = hitungHariKerja(
        $tahun.'-'.str_pad($bulan,2,'0',STR_PAD_LEFT).'-01',
        $tahun.'-'.str_pad($bulan,2,'0',STR_PAD_LEFT).'-'.cal_days_in_month(CAL_GREGORIAN,$bulan,$tahun)
    );
}

$filterQs = http_build_query([
    'mode_filter'=>$mode_filter,'bulan'=>$bulan,'tahun'=>$tahun,
    'tgl_dari'=>$tgl_dari,'tgl_sampai'=>$tgl_sampai,
    'karyawan_id'=>$karyawan_id,'departemen_id'=>$departemen_id,
]);

// Base URL untuk path file bukti
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']==='on' ? 'https' : 'http')
         . '://' . $_SERVER['HTTP_HOST']
         . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/') . '/';

function filterDeptKaryawan($allDepartemen, $allKaryawan, $departemen_id, $karyawan_id) {
    ob_start(); ?>
    <div class="fg"><label class="flbl">Departemen</label>
        <select name="departemen_id" class="form-select">
            <option value="">Semua Dept.</option>
            <?php foreach($allDepartemen as $d): ?>
            <option value="<?= $d['id'] ?>" <?= $d['id']==$departemen_id?'selected':'' ?>><?= htmlspecialchars($d['nama']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="fg"><label class="flbl">Karyawan</label>
        <select name="karyawan_id" class="form-select">
            <option value="">Semua Karyawan</option>
            <?php foreach($allKaryawan as $k): ?>
            <option value="<?= $k['id'] ?>" <?= $k['id']==$karyawan_id?'selected':'' ?>><?= htmlspecialchars($k['nama']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php return ob_get_clean();
}

function statusConfig($st) {
    return match($st) {
        'hadir'      => ['Hadir',      '#10b981','#ecfdf5'],
        'terlambat'  => ['Terlambat',  '#f59e0b','#fffbeb'],
        'absen'      => ['Absen',      '#ef4444','#fef2f2'],
        'izin'       => ['Izin',       '#8b5cf6','#faf5ff'],
        'sakit'      => ['Sakit',      '#f43f5e','#fff1f2'],
        'dinas_luar' => ['Dinas Luar', '#0f4c81','#eff6ff'],
        'cuti'       => ['Cuti',       '#6366f1','#eef2ff'],
        default      => [ucfirst($st), '#64748b','#f8fafc'],
    };
}

include __DIR__ . '/../includes/header.php';
?>
<style>
*, *::before, *::after { box-sizing: border-box; }

/* ══ FILTER ══ */
.ra-filter { background:#fff; border-radius:12px; border:1px solid var(--border); box-shadow:0 1px 8px rgba(15,76,129,.06); margin-bottom:14px; overflow:hidden; }
.ra-tabs   { display:flex; border-bottom:1px solid var(--border); }
.ra-tab    { flex:1; padding:10px; font-size:12.5px; font-weight:700; text-align:center; cursor:pointer; color:var(--text-muted); border:none; background:transparent; transition:all .18s; }
.ra-tab.active { color:#0f4c81; border-bottom:3px solid #0f4c81; background:#f0f7ff; }
.ra-tab:hover:not(.active) { background:#f8fafc; }
.ra-filter-body { padding:12px 14px; }
.ra-pane { display:none; }
.ra-pane.active { display:block; }
.frow { display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap; width:100%; }
.fg   { display:flex; flex-direction:column; gap:3px; flex:1; min-width:0; }
.fg-xs{ flex:0 0 auto; }
.flbl { font-size:11px; font-weight:700; color:#64748b; white-space:nowrap; }

/* ══ SUMMARY ══ */
.ra-summary { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:14px; width:100%; }
@media(max-width:600px){ .ra-summary{ grid-template-columns:repeat(2,1fr); } }
.ra-sum { background:#fff; border-radius:12px; padding:12px 8px; text-align:center; box-shadow:0 1px 8px rgba(15,76,129,.06); border-top:3px solid transparent; }
.ra-sum-n { font-size:22px; font-weight:900; line-height:1; }
.ra-sum-l { font-size:10px; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:.3px; margin-top:4px; }
.ra-sum-s { font-size:9.5px; color:#94a3b8; margin-top:2px; font-weight:600; }

/* ══ LEGENDA ══ */
.ra-legend { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:10px; }
.ra-leg { display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:700; color:#64748b; }
.ra-dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; }

/* ══ CARD ══ */
.ra-card { background:#fff; border-radius:12px; border:1px solid var(--border); box-shadow:0 1px 8px rgba(15,76,129,.06); margin-bottom:14px; overflow:hidden; width:100%; }
.ra-card-hd { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:6px; padding:11px 14px; border-bottom:1px solid #f1f5f9; background:#f8fafc; }
.ra-card-hd h3 { margin:0; font-size:13px; font-weight:800; color:#0f172a; }

/* ══ TABEL RINGKASAN ══ */
.ra-tbl { width:100%; border-collapse:collapse; table-layout:fixed; }
.ra-tbl thead tr { background:#f8fafc; }
.ra-tbl th { padding:7px 4px; font-size:9.5px; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:.3px; text-align:center; border-bottom:2px solid #e2e8f0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.ra-tbl th.al { text-align:left; padding-left:10px; }
.ra-tbl td { padding:7px 4px; font-size:11.5px; color:#0f172a; border-bottom:1px solid #f1f5f9; vertical-align:middle; text-align:center; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.ra-tbl td.al { text-align:left; padding-left:10px; }
.ra-tbl tbody tr:last-child td { border-bottom:none; }
.ra-tbl tbody tr:hover td { background:#fafbff; }
.ra-we td { background:#f8f9ff!important; }
.ra-dept-lbl { background:linear-gradient(90deg,#e8f0fe,#f0f7ff); border-left:4px solid #0f4c81; padding:6px 12px; font-size:10.5px; font-weight:800; color:#0f4c81; text-transform:uppercase; letter-spacing:.4px; }
.sp { display:inline-flex; align-items:center; gap:2px; padding:2px 6px; border-radius:20px; font-size:10px; font-weight:800; white-space:nowrap; }
.ra-prog { display:flex; align-items:center; gap:3px; }
.ra-prog-bar { flex:1; background:#e5e7eb; border-radius:3px; height:5px; min-width:0; }
.ra-prog-fill { height:5px; border-radius:3px; }
.ra-pct { font-size:10.5px; font-weight:800; min-width:26px; text-align:right; flex-shrink:0; }
.av { width:26px; height:26px; border-radius:50%; background:linear-gradient(135deg,#0f4c81,#00c9a7); display:flex; align-items:center; justify-content:center; color:#fff; font-weight:800; font-size:11px; flex-shrink:0; }
.ra-badge { display:inline-flex; align-items:center; gap:5px; background:#eef3fa; color:#0f4c81; border-radius:7px; padding:4px 10px; font-size:11px; font-weight:700; border:1px solid #b8d0eb; }

/* ══ TABEL DETAIL ══ */
.ra-dtbl { width:100%; border-collapse:collapse; table-layout:fixed; }
.ra-dtbl th { padding:7px 6px; font-size:9.5px; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:.3px; text-align:left; border-bottom:2px solid #e2e8f0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.ra-dtbl td { padding:7px 6px; font-size:11.5px; color:#0f172a; border-bottom:1px solid #f1f5f9; vertical-align:middle; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.ra-dtbl tbody tr:last-child td { border-bottom:none; }
.ra-dtbl tbody tr:hover td { background:#fafbff; }
.ra-dtbl tfoot td { background:#f0f4f8; font-weight:800; font-size:11.5px; padding:7px 6px; }
.ra-we-d td { background:#f8f9ff!important; }

/* ══ ROW KETERANGAN (sub-row di bawah row dinas/sakit/izin) ══ */
.ra-ket-row td {
    padding: 0 6px 8px 6px !important;
    border-bottom: 1px solid #f1f5f9;
    background: #fafbff;
    white-space: normal !important;
}
.ra-ket-box {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    background: #f0f4f8;
    border-radius: 8px;
    padding: 8px 10px;
    font-size: 11px;
    color: #475569;
    font-weight: 600;
    line-height: 1.5;
}
.ra-ket-box-icon {
    flex-shrink: 0;
    font-size: 13px;
    margin-top: 1px;
}

/* ══ BUKTI BADGE ══ */
.bukti-btn {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 8px; border-radius: 6px; font-size: 10.5px; font-weight: 800;
    text-decoration: none; border: 1px solid transparent;
    transition: opacity .15s;
}
.bukti-btn:hover { opacity: .8; }
.bukti-btn-dinas  { background:#eff6ff; color:#1d4ed8; border-color:#bfdbfe; }
.bukti-btn-sakit  { background:#fff1f2; color:#be123c; border-color:#fecdd3; }
.bukti-btn-izin   { background:#faf5ff; color:#6d28d9; border-color:#ddd6fe; }
.bukti-btn-pdf    { background:#fef2f2; color:#991b1b; border-color:#fecaca; }

/* ══ TOMBOL MATA DL (di tabel ringkasan) ══ */
.dl-eye-btn {
    width: 22px; height: 22px; border-radius: 6px;
    background: #dbeafe; border: 1px solid #93c5fd;
    color: #1d4ed8; cursor: pointer; font-size: 11px;
    display: inline-flex; align-items: center; justify-content: center;
    transition: background .15s, transform .1s;
    flex-shrink: 0;
}
.dl-eye-btn:hover { background: #bfdbfe; transform: scale(1.1); }

/* ══ MODAL BUKTI ══ */
.bukti-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(15,23,42,.7); z-index: 2000;
    align-items: center; justify-content: center;
    backdrop-filter: blur(3px);
}
.bukti-overlay.open { display: flex; }
.bukti-modal {
    background: #fff; border-radius: 16px;
    max-width: 640px; width: 94%; max-height: 88vh;
    overflow: hidden; display: flex; flex-direction: column;
    box-shadow: 0 24px 64px rgba(0,0,0,.25);
    animation: bmIn .22s ease;
}
@keyframes bmIn { from{transform:scale(.93);opacity:0} to{transform:scale(1);opacity:1} }
.bukti-modal-head {
    padding: 14px 18px; border-bottom: 1px solid #f1f5f9;
    display: flex; align-items: center; justify-content: space-between;
    background: #f8fafc; flex-shrink: 0;
}
.bukti-modal-title { font-size: 14px; font-weight: 800; color: #0f172a; }
.bukti-modal-close {
    width: 30px; height: 30px; border-radius: 50%;
    background: #e2e8f0; border: none; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 15px; color: #64748b;
}
.bukti-modal-body { flex: 1; overflow-y: auto; padding: 16px; }
.bukti-modal-meta { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 14px; }
.bukti-meta-item { background: #f8fafc; border-radius: 8px; padding: 8px 12px; }
.bukti-meta-label { font-size: 10px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: .4px; margin-bottom: 2px; }
.bukti-meta-val   { font-size: 13px; font-weight: 700; color: #0f172a; }
.bukti-ket-box { background: #f8fafc; border-radius: 8px; padding: 10px 14px; margin-bottom: 14px; font-size: 13px; color: #374151; line-height: 1.6; border-left: 3px solid #e2e8f0; }
.bukti-img { width: 100%; border-radius: 10px; border: 2px solid #e2e8f0; display: block; }
.bukti-pdf-link {
    display: flex; align-items: center; gap: 10px; padding: 14px 16px;
    background: #fef2f2; border-radius: 10px; border: 1px solid #fecaca;
    text-decoration: none; color: #991b1b; font-weight: 700; font-size: 13px;
}
.bukti-no-file { text-align: center; padding: 24px; color: #94a3b8; font-size: 13px; font-weight: 600; }

/* ══ MOBILE ══ */
.desk { display:block; }
.mob  { display:none; }
@media(max-width:768px){ .desk { display:none!important; } .mob { display:block; } }
.mob-card { background:#fff; border-radius:12px; padding:12px 14px; margin-bottom:8px; box-shadow:0 1px 8px rgba(15,76,129,.06); border:1px solid #f1f5f9; }
.mob-head { display:flex; align-items:center; gap:8px; margin-bottom:10px; }
.mob-name { font-weight:800; font-size:13px; color:#0f172a; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.mob-sub  { font-size:11px; color:#64748b; font-weight:600; }
.mob-g4   { display:grid; grid-template-columns:repeat(4,1fr); gap:5px; margin-bottom:7px; }
.mob-g3   { display:grid; grid-template-columns:repeat(3,1fr); gap:5px; margin-bottom:7px; }
.mob-st   { text-align:center; background:#f8fafc; border-radius:7px; padding:6px 3px; }
.mob-n    { font-size:15px; font-weight:900; line-height:1; }
.mob-l    { font-size:9px; color:#64748b; font-weight:700; margin-top:1px; }
.mob-acts { display:flex; gap:6px; }
.mob-acts a { flex:1; text-align:center; }
.di { background:#f8fafc; border-radius:10px; padding:10px 12px; margin-bottom:6px; border-left:4px solid #e2e8f0; }
.di.hadir      { border-left-color:#10b981; }
.di.terlambat  { border-left-color:#f59e0b; }
.di.absen      { border-left-color:#ef4444; }
.di.izin       { border-left-color:#8b5cf6; }
.di.sakit      { border-left-color:#f43f5e; }
.di.dinas_luar { border-left-color:#0f4c81; }
.di-hd  { display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; }
.di-row { display:flex; justify-content:space-between; align-items:center; padding:4px 0; border-bottom:1px solid #f1f5f9; font-size:12px; }
.di-row:last-child { border-bottom:none; }
.di-key { color:#64748b; font-weight:600; display:flex; align-items:center; gap:4px; }
.di-val { font-weight:700; color:#0f172a; }
</style>

<!-- PAGE HEADER -->
<div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:14px">
    <div>
        <h2 style="margin:0 0 3px">Rekap Semua Karyawan</h2>
        <p style="margin:0;color:#64748b;font-size:13px">Laporan kehadiran — <?= htmlspecialchars($period_label) ?>
            <?php if($departemen_id): ?>
            <span class="ra-badge" style="margin-left:6px"><i class="fas fa-building"></i>
                <?php foreach($allDepartemen as $dep): if($dep['id']==$departemen_id) echo htmlspecialchars($dep['nama']); endforeach; ?>
            </span>
            <?php endif; ?>
        </p>
    </div>
    <div style="display:flex;gap:7px;flex-wrap:wrap">
        <a href="cetak_rekap.php?<?= $filterQs ?>" target="_blank" class="btn btn-primary btn-sm"><i class="fas fa-print"></i> Cetak PDF</a>
        <a href="../api/export_rekap.php?<?= $filterQs ?>&all=1" class="btn btn-outline btn-sm"><i class="fas fa-download"></i> CSV</a>
    </div>
</div>

<!-- FILTER -->
<div class="ra-filter">
    <div class="ra-tabs">
        <button type="button" class="ra-tab <?= $mode_filter==='bulanan'?'active':'' ?>" onclick="raTab('bulanan',this)"><i class="fas fa-calendar-alt"></i> Bulanan</button>
        <button type="button" class="ra-tab <?= $mode_filter==='periode'?'active':'' ?>" onclick="raTab('periode',this)"><i class="fas fa-calendar-range"></i> Rentang Tanggal</button>
    </div>
    <div class="ra-filter-body">
        <form method="GET" id="raForm">
            <input type="hidden" name="mode_filter" id="raMode" value="<?= htmlspecialchars($mode_filter) ?>">
            <div class="ra-pane <?= $mode_filter==='bulanan'?'active':'' ?>" id="pane-bulanan">
                <div class="frow">
                    <div class="fg fg-xs"><label class="flbl">Bulan</label>
                        <select name="bulan" class="form-select">
                            <?php for($m=1;$m<=12;$m++): ?><option value="<?= $m ?>" <?= $m==$bulan?'selected':'' ?>><?= $monthsId[$m] ?></option><?php endfor; ?>
                        </select>
                    </div>
                    <div class="fg fg-xs"><label class="flbl">Tahun</label>
                        <select name="tahun" class="form-select">
                            <?php for($y=date('Y');$y>=2024;$y--): ?><option value="<?= $y ?>" <?= $y==$tahun?'selected':'' ?>><?= $y ?></option><?php endfor; ?>
                        </select>
                    </div>
                    <?= filterDeptKaryawan($allDepartemen,$allKaryawan,$departemen_id,$karyawan_id) ?>
                    <div class="fg fg-xs"><label class="flbl">&nbsp;</label>
                        <div style="display:flex;gap:5px">
                            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> Tampilkan</button>
                            <?php if($karyawan_id||$departemen_id): ?>
                            <a href="?mode_filter=bulanan&bulan=<?= $bulan ?>&tahun=<?= $tahun ?>" class="btn btn-outline btn-sm" title="Reset"><i class="fas fa-times"></i></a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="ra-pane <?= $mode_filter==='periode'?'active':'' ?>" id="pane-periode">
                <div class="frow">
                    <div class="fg fg-xs"><label class="flbl">Dari</label>
                        <input type="date" name="tgl_dari" class="form-select" value="<?= htmlspecialchars($tgl_dari) ?>" max="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="fg fg-xs"><label class="flbl">Sampai</label>
                        <input type="date" name="tgl_sampai" class="form-select" value="<?= htmlspecialchars($tgl_sampai) ?>" max="<?= date('Y-m-d') ?>">
                    </div>
                    <?= filterDeptKaryawan($allDepartemen,$allKaryawan,$departemen_id,$karyawan_id) ?>
                    <div class="fg fg-xs"><label class="flbl">&nbsp;</label>
                        <div style="display:flex;gap:5px">
                            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> Tampilkan</button>
                            <?php if($karyawan_id||$departemen_id): ?>
                            <a href="?mode_filter=periode&tgl_dari=<?= $tgl_dari ?>&tgl_sampai=<?= $tgl_sampai ?>" class="btn btn-outline btn-sm" title="Reset"><i class="fas fa-times"></i></a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php if($mode_filter==='periode'): ?>
                <div style="margin-top:6px;font-size:11px;color:var(--text-muted)"><i class="fas fa-info-circle"></i> Rentang: <strong><?= (int)ceil((strtotime($tgl_sampai)-strtotime($tgl_dari))/86400)+1 ?> hari</strong> · Hari kerja: <strong><?= $jumlahHariKerja ?> hari</strong></div>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- SUMMARY -->
<?php $totH=$totT=$totA=$totI=$totS=$totD=$totPC=0;
foreach($rekapKaryawan as $r){ $totH+=(int)$r['hadir'];$totT+=(int)$r['terlambat'];$totA+=(int)$r['absen'];$totI+=(int)$r['izin'];$totS+=(int)$r['sakit'];$totD+=(int)$r['dinas_luar'];$totPC+=(int)$r['pulang_cepat']; } ?>
<div class="ra-summary">
    <div class="ra-sum" style="border-top-color:#10b981"><div class="ra-sum-n" style="color:#10b981"><?= $totH ?></div><div class="ra-sum-l"><i class="fas fa-user-check"></i> Hadir</div><div class="ra-sum-s"><?= $totT ?> terlambat</div></div>
    <div class="ra-sum" style="border-top-color:#ef4444"><div class="ra-sum-n" style="color:#ef4444"><?= $totA ?></div><div class="ra-sum-l"><i class="fas fa-user-xmark"></i> Absen</div><div class="ra-sum-s">Tidak keterangan</div></div>
    <div class="ra-sum" style="border-top-color:#8b5cf6"><div class="ra-sum-n" style="color:#8b5cf6"><?= $totI+$totS ?></div><div class="ra-sum-l"><i class="fas fa-file-medical"></i> Izin/Sakit</div><div class="ra-sum-s"><?= $totI ?> izin · <?= $totS ?> sakit</div></div>
    <div class="ra-sum" style="border-top-color:#0f4c81"><div class="ra-sum-n" style="color:#0f4c81"><?= $totD ?></div><div class="ra-sum-l"><i class="fas fa-briefcase"></i> Dinas Luar</div><div class="ra-sum-s"><?= $totPC ?> pulang cepat</div></div>
</div>

<!-- LEGENDA -->
<div class="ra-legend">
    <span class="ra-leg"><span class="ra-dot" style="background:#10b981"></span>Hadir</span>
    <span class="ra-leg"><span class="ra-dot" style="background:#f59e0b"></span>Terlambat</span>
    <span class="ra-leg"><span class="ra-dot" style="background:#ef4444"></span>Absen</span>
    <span class="ra-leg"><span class="ra-dot" style="background:#8b5cf6"></span>Izin</span>
    <span class="ra-leg"><span class="ra-dot" style="background:#f43f5e"></span>Sakit</span>
    <span class="ra-leg"><span class="ra-dot" style="background:#0f4c81"></span>Dinas Luar</span>
    <span class="ra-leg"><span class="ra-dot" style="background:#7c3aed"></span>Pulang Cepat</span>
</div>

<!-- TABEL RINGKASAN DESKTOP -->
<div class="ra-card desk">
    <div class="ra-card-hd">
        <h3><i class="fas fa-table" style="color:#0f4c81;margin-right:5px"></i>Ringkasan — <?= htmlspecialchars($period_label) ?></h3>
        <span style="font-size:11px;color:var(--text-muted)"><?= count($rekapKaryawan) ?> karyawan · <?= $jumlahHariKerja ?> hari kerja</span>
    </div>
    <table class="ra-tbl">
        <colgroup>
            <col style="width:17%"><col style="width:9%">
            <col style="width:4%"><col style="width:5%"><col style="width:4%">
            <col style="width:4%"><col style="width:4%"><col style="width:4%"><col style="width:4%">
            <col style="width:9%"><col style="width:9%"><col style="width:9%">
            <col style="width:11%"><col style="width:7%">
        </colgroup>
        <thead>
            <tr>
                <th class="al">Karyawan</th><th class="al">Dept.</th>
                <th style="color:#10b981">H</th><th style="color:#f59e0b">Tlbt</th><th style="color:#ef4444">A</th>
                <th style="color:#8b5cf6">I</th><th style="color:#f43f5e">S</th><th style="color:#0f4c81" title="Dinas Luar">DL</th><th style="color:#7c3aed" title="Pulang Cepat">PCpt</th>
                <th>Ʃ Tlbt</th><th>Ʃ PC</th><th>Durasi</th><th>Kehadiran</th><th>Aksi</th>
            </tr>
        </thead>
        <tbody>
        <?php if(empty($rekapKaryawan)): ?>
        <tr><td colspan="14" style="text-align:center;padding:24px;color:#94a3b8">
            <i class="fas fa-inbox" style="display:block;font-size:20px;margin-bottom:6px;opacity:.4"></i>Tidak ada data
        </td></tr>
        <?php else:
            $prevDept=null;
            foreach($rekapKaryawan as $r):
                $pct=$jumlahHariKerja>0?min(100,round(($r['hadir']/$jumlahHariKerja)*100)):0;
                $pc=$pct>=90?'#10b981':($pct>=75?'#f59e0b':'#ef4444');
                if(!$karyawan_id && $r['departemen_nama']!==$prevDept):
                    $prevDept=$r['departemen_nama'];
        ?>
        <tr><td colspan="14" style="padding:0"><div class="ra-dept-lbl"><i class="fas fa-building"></i> <?= htmlspecialchars($r['departemen_nama']?:'Tanpa Departemen') ?></div></td></tr>
        <?php endif; ?>
        <tr>
            <td class="al">
                <div style="display:flex;align-items:center;gap:6px">
                    <div class="av"><?= strtoupper(substr($r['nama'],0,1)) ?></div>
                    <div style="min-width:0">
                        <div style="font-weight:700;font-size:11.5px;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($r['nama']) ?></div>
                        <div style="font-size:9.5px;color:#94a3b8"><?= htmlspecialchars($r['nik']) ?></div>
                    </div>
                </div>
            </td>
            <td class="al" style="font-size:10.5px;color:#64748b"><?= htmlspecialchars($r['departemen_nama']?:'-') ?></td>
            <td><strong style="color:#10b981"><?= $r['hadir'] ?></strong></td>
            <td><strong style="color:<?= $r['terlambat']>0?'#f59e0b':'#cbd5e1' ?>"><?= $r['terlambat']?:'-' ?></strong></td>
            <td><strong style="color:<?= $r['absen']>0?'#ef4444':'#cbd5e1' ?>"><?= $r['absen']?:'-' ?></strong></td>
            <td><?php if($r['izin']>0): ?><span class="sp" style="background:#faf5ff;color:#5b21b6"><?= $r['izin'] ?></span><?php else: ?><span style="color:#cbd5e1">-</span><?php endif; ?></td>
            <td><?php if($r['sakit']>0): ?><span class="sp" style="background:#fff1f2;color:#be123c"><?= $r['sakit'] ?></span><?php else: ?><span style="color:#cbd5e1">-</span><?php endif; ?></td>
            <td>
                <?php
                $hasBuktiDL = !empty($buktiPerKaryawan[$r['id']]);
                if($r['dinas_luar']>0):
                ?>
                <div style="display:flex;align-items:center;justify-content:center;gap:3px;flex-wrap:wrap">
                    <span class="sp" style="background:#eff6ff;color:#1d4ed8"><?= $r['dinas_luar'] ?></span>
                    <?php if($hasBuktiDL): ?>
                    <button class="dl-eye-btn" title="Lihat bukti dinas luar"
                        onclick="showBuktiKaryawan(<?= $r['id'] ?>, '<?= htmlspecialchars(addslashes($r['nama'])) ?>')">
                        <i class="fas fa-eye"></i>
                    </button>
                    <?php endif; ?>
                </div>
                <?php else: ?><span style="color:#cbd5e1">-</span><?php endif; ?>
            </td>
            <td><?php if($r['pulang_cepat']>0): ?><span class="sp" style="background:#faf5ff;color:#7c3aed"><?= $r['pulang_cepat'] ?></span><?php else: ?><span style="color:#cbd5e1">-</span><?php endif; ?></td>
            <td style="font-size:10.5px;color:<?= $r['total_terlambat_detik']>0?'#d97706':'#cbd5e1' ?>"><?= $r['total_terlambat_detik']>0?formatTerlambat($r['total_terlambat_detik']):'-' ?></td>
            <td style="font-size:10.5px;color:<?= $r['total_pulang_cepat_detik']>0?'#7c3aed':'#cbd5e1' ?>"><?= $r['total_pulang_cepat_detik']>0?formatTerlambat($r['total_pulang_cepat_detik']):'-' ?></td>
            <td style="font-size:10.5px"><?= formatDurasi($r['total_durasi']) ?></td>
            <td>
                <div class="ra-prog">
                    <span class="ra-pct" style="color:<?= $pc ?>"><?= $pct ?>%</span>
                    <div class="ra-prog-bar"><div class="ra-prog-fill" style="width:<?= $pct ?>%;background:<?= $pc ?>"></div></div>
                </div>
            </td>
            <td>
                <div style="display:flex;gap:3px;justify-content:center">
                    <a href="?<?= $filterQs ?>&karyawan_id=<?= $r['id'] ?>" class="btn btn-outline btn-sm btn-icon" title="Detail"><i class="fas fa-eye"></i></a>
                    <a href="cetak_rekap.php?<?= $filterQs ?>&karyawan_id=<?= $r['id'] ?>" target="_blank" class="btn btn-sm btn-icon" style="background:#0f4c81;color:#fff;border:none" title="PDF"><i class="fas fa-print"></i></a>
                </div>
            </td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<!-- MOBILE CARDS -->
<div class="mob">
<?php $prevDept=null;
foreach($rekapKaryawan as $r):
    $pct=$jumlahHariKerja>0?min(100,round(($r['hadir']/$jumlahHariKerja)*100)):0;
    $pc=$pct>=90?'#10b981':($pct>=75?'#f59e0b':'#ef4444');
    if(!$karyawan_id && $r['departemen_nama']!==$prevDept): $prevDept=$r['departemen_nama']; ?>
<div class="ra-dept-lbl" style="border-radius:8px;margin-bottom:6px"><i class="fas fa-building"></i> <?= htmlspecialchars($r['departemen_nama']?:'Tanpa Departemen') ?></div>
<?php endif; ?>
<div class="mob-card">
    <div class="mob-head">
        <div class="av"><?= strtoupper(substr($r['nama'],0,1)) ?></div>
        <div style="flex:1;min-width:0"><div class="mob-name"><?= htmlspecialchars($r['nama']) ?></div><div class="mob-sub"><?= htmlspecialchars($r['nik']) ?> · <?= htmlspecialchars($r['departemen_nama']?:'-') ?></div></div>
        <span style="font-size:14px;font-weight:900;color:<?= $pc ?>;flex-shrink:0"><?= $pct ?>%</span>
    </div>
    <div style="background:#e5e7eb;border-radius:3px;height:4px;margin-bottom:9px"><div style="width:<?= $pct ?>%;background:<?= $pc ?>;height:4px;border-radius:3px"></div></div>
    <div class="mob-g4">
        <div class="mob-st"><div class="mob-n" style="color:#10b981"><?= $r['hadir'] ?></div><div class="mob-l">Hadir</div></div>
        <div class="mob-st"><div class="mob-n" style="color:<?= $r['terlambat']>0?'#f59e0b':'#cbd5e1' ?>"><?= $r['terlambat'] ?></div><div class="mob-l">Tlbt</div></div>
        <div class="mob-st"><div class="mob-n" style="color:<?= $r['absen']>0?'#ef4444':'#cbd5e1' ?>"><?= $r['absen'] ?></div><div class="mob-l">Absen</div></div>
        <div class="mob-st"><div class="mob-n" style="color:<?= $r['pulang_cepat']>0?'#7c3aed':'#cbd5e1' ?>"><?= $r['pulang_cepat'] ?></div><div class="mob-l">PC</div></div>
    </div>
    <div class="mob-g3">
        <div class="mob-st"><div class="mob-n" style="color:<?= $r['izin']>0?'#8b5cf6':'#cbd5e1' ?>"><?= $r['izin'] ?></div><div class="mob-l">Izin</div></div>
        <div class="mob-st"><div class="mob-n" style="color:<?= $r['sakit']>0?'#f43f5e':'#cbd5e1' ?>"><?= $r['sakit'] ?></div><div class="mob-l">Sakit</div></div>
        <div class="mob-st"><div class="mob-n" style="color:<?= $r['dinas_luar']>0?'#0f4c81':'#cbd5e1' ?>"><?= $r['dinas_luar'] ?></div><div class="mob-l">Dinas</div></div>
    </div>
    <div class="mob-acts">
        <a href="?<?= $filterQs ?>&karyawan_id=<?= $r['id'] ?>" class="btn btn-outline btn-sm" style="display:flex;align-items:center;justify-content:center;gap:5px"><i class="fas fa-eye"></i> Detail</a>
        <a href="cetak_rekap.php?<?= $filterQs ?>&karyawan_id=<?= $r['id'] ?>" target="_blank" class="btn btn-sm" style="background:#0f4c81;color:#fff;display:flex;align-items:center;justify-content:center;gap:5px"><i class="fas fa-print"></i> PDF</a>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- DETAIL KARYAWAN -->
<?php if($karyawan_id && $selectedK): ?>
<div class="ra-card">
    <div class="ra-card-hd">
        <div style="font-size:13.5px;font-weight:800;color:#0f172a">
            <i class="fas fa-list-check" style="color:#0f4c81;margin-right:5px"></i>
            Detail — <?= htmlspecialchars($selectedK['nama']) ?>
            <?php if($selectedK['departemen_nama']): ?><span style="font-size:11px;font-weight:500;color:#64748b;margin-left:4px"><?= htmlspecialchars($selectedK['departemen_nama']) ?></span><?php endif; ?>
        </div>
        <div style="display:flex;gap:7px;flex-wrap:wrap;align-items:center">
            <span class="ra-badge"><i class="fas fa-calendar"></i> <?= htmlspecialchars($period_label) ?></span>
            <a href="cetak_rekap.php?<?= $filterQs ?>" target="_blank" class="btn btn-primary btn-sm"><i class="fas fa-print"></i> Cetak PDF</a>
        </div>
    </div>

    <?php if(empty($detailAbsen)): ?>
    <div style="text-align:center;padding:28px;color:#94a3b8;font-size:13px;font-weight:700">
        <i class="fas fa-inbox" style="font-size:24px;display:block;margin-bottom:7px;opacity:.4"></i>Tidak ada data absensi
    </div>
    <?php else: ?>

    <!-- Desktop detail -->
    <div class="desk">
        <table class="ra-dtbl">
            <colgroup>
                <col style="width:8%">  <!-- Tgl -->
                <col style="width:5%">  <!-- Hari -->
                <col style="width:10%"> <!-- Shift -->
                <col style="width:9%">  <!-- Lokasi -->
                <col style="width:7%">  <!-- Masuk -->
                <col style="width:7%">  <!-- Keluar -->
                <col style="width:10%"> <!-- Status -->
                <col style="width:9%">  <!-- Tlbt -->
                <col style="width:9%">  <!-- PC -->
                <col style="width:8%">  <!-- Durasi -->
                <col style="width:18%"> <!-- Bukti/Keterangan -->
            </colgroup>
            <thead>
                <tr>
                    <th>Tanggal</th><th>Hari</th><th>Shift</th><th>Lokasi</th>
                    <th>Masuk</th><th>Keluar</th><th>Status</th>
                    <th><i class="fas fa-clock" style="color:#f59e0b"></i> Tlbt</th>
                    <th><i class="fas fa-person-running" style="color:#7c3aed"></i> PC</th>
                    <th>Durasi</th>
                    <th>Keterangan / Bukti</th>
                </tr>
            </thead>
            <tbody>
            <?php $tT=$tP=$tD=0;
            foreach($detailAbsen as $a):
                $tlbt=(int)($a['terlambat_detik']??0);
                $pcd=(int)($a['pulang_cepat_detik']??0);
                $tT+=$tlbt; $tP+=$pcd; $tD+=(int)($a['durasi_kerja']??0);
                $isWE    = in_array(date('N',strtotime($a['tanggal'])),[6,7]);
                $st      = $a['status_kehadiran']??'absen';
                [$stL,$stC,$stB] = statusConfig($st);
                $adaBukti= !empty($a['bukti_file']);
                $adaKet  = !empty($a['keterangan']);
                $isKhusus= in_array($st,['dinas_luar','sakit','izin']);
                $stI = match($st){
                    'hadir'=>'fas fa-check-circle','terlambat'=>'fas fa-clock',
                    'absen'=>'fas fa-times-circle','izin'=>'fas fa-file-circle-check',
                    'sakit'=>'fas fa-hospital','dinas_luar'=>'fas fa-briefcase',
                    default=>'fas fa-circle'
                };

                // Kelas CSS bukti button
                $buktiClass = match($st){
                    'dinas_luar'=>'bukti-btn-dinas',
                    'sakit'=>'bukti-btn-sakit',
                    'izin'=>'bukti-btn-izin',
                    default=>'bukti-btn-dinas'
                };
                $buktiIcon = match($st){
                    'dinas_luar'=>'fas fa-briefcase',
                    'sakit'=>'fas fa-hospital',
                    'izin'=>'fas fa-file-circle-check',
                    default=>'fas fa-paperclip'
                };

                // Buat URL file bukti
                $buktiUrl = '';
                if ($adaBukti) {
                    $bf = $a['bukti_file'];
                    $buktiUrl = (strpos($bf,'http')===0) ? $bf : $baseUrl . ltrim($bf,'/');
                    $isPdf = strtolower(pathinfo($bf, PATHINFO_EXTENSION)) === 'pdf';
                }

                // Encode data untuk modal
                $modalData = json_encode([
                    'tgl'    => date('d/m/Y', strtotime($a['tanggal'])),
                    'st'     => $stL,
                    'stC'    => $stC,
                    'stB'    => $stB,
                    'stI'    => $stI,
                    'ket'    => $a['keterangan'] ?? '',
                    'url'    => $buktiUrl,
                    'isPdf'  => $adaBukti ? $isPdf : false,
                    'nama'   => $selectedK['nama'],
                ]);
            ?>
            <tr class="<?= $isWE?'ra-we-d':'' ?>">
                <td style="font-weight:700;font-size:11px"><?= date('d/m/Y',strtotime($a['tanggal'])) ?></td>
                <td style="font-size:10.5px;color:<?= $isWE?'#2563eb':'#64748b' ?>;font-weight:<?= $isWE?'800':'400' ?>"><?= $hariId[date('D',strtotime($a['tanggal']))]??date('D',strtotime($a['tanggal'])) ?></td>
                <td style="font-size:10.5px"><?= htmlspecialchars($a['shift_nama']??'-') ?></td>
                <td style="font-size:10.5px;color:#64748b"><?= htmlspecialchars($a['lokasi_nama']??'-') ?></td>
                <td style="font-family:'JetBrains Mono',monospace;font-size:11px">
                    <?= $a['waktu_masuk']?date('H:i',strtotime($a['waktu_masuk'])):'<span style="color:#cbd5e1">-</span>' ?>
                    <?php if($a['waktu_masuk']&&$a['jam_masuk']): ?><div style="font-size:9px;color:#94a3b8"><?= substr($a['jam_masuk'],0,5) ?></div><?php endif; ?>
                </td>
                <td style="font-family:'JetBrains Mono',monospace;font-size:11px">
                    <?= $a['waktu_keluar']?date('H:i',strtotime($a['waktu_keluar'])):'<span style="color:#cbd5e1">-</span>' ?>
                    <?php if($a['waktu_keluar']&&$a['jam_keluar']): ?><div style="font-size:9px;color:#94a3b8"><?= substr($a['jam_keluar'],0,5) ?></div><?php endif; ?>
                </td>
                <td><span class="sp" style="background:<?= $stB ?>;color:<?= $stC ?>"><i class="<?= $stI ?>" style="font-size:8px"></i> <?= $stL ?></span></td>
                <td><?php if($tlbt>0): ?><span style="background:#fffbeb;color:#78350f;padding:1px 6px;border-radius:4px;font-size:10px;font-weight:700"><?= formatTerlambat($tlbt) ?></span><?php else: ?><span style="color:#cbd5e1;font-size:10px">-</span><?php endif; ?></td>
                <td><?php if($pcd>0): ?><span style="background:#faf5ff;color:#6d28d9;padding:1px 6px;border-radius:4px;font-size:10px;font-weight:700"><?= formatTerlambat($pcd) ?></span><?php else: ?><span style="color:#cbd5e1;font-size:10px">-</span><?php endif; ?></td>
                <td style="font-size:10.5px"><?= $a['durasi_kerja']?formatDurasi($a['durasi_kerja']):'<span style="color:#cbd5e1">-</span>' ?></td>
                <td>
                    <?php if($isKhusus && ($adaKet || $adaBukti)): ?>
                    <button class="bukti-btn <?= $buktiClass ?>"
                            onclick='showBuktiModal(<?= htmlspecialchars($modalData, ENT_QUOTES) ?>)'
                            title="Lihat keterangan & bukti">
                        <i class="<?= $buktiIcon ?>"></i>
                        <?php if($adaBukti): ?>Lihat Bukti<?php else: ?>Lihat Ket.<?php endif; ?>
                    </button>
                    <?php elseif($adaBukti): ?>
                    <!-- Bukti untuk hadir/terlambat (foto selfie) -->
                    <button class="bukti-btn" style="background:#f0fdf4;color:#166534;border-color:#bbf7d0"
                            onclick='showBuktiModal(<?= htmlspecialchars($modalData, ENT_QUOTES) ?>)'
                            title="Lihat foto">
                        <i class="fas fa-camera"></i> Foto
                    </button>
                    <?php else: ?>
                    <span style="color:#cbd5e1;font-size:10px">-</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="7" style="font-size:11px">TOTAL <?= htmlspecialchars($period_label) ?></td>
                    <td><span style="color:#d97706;font-size:11px"><?= $tT>0?formatTerlambat($tT):'-' ?></span></td>
                    <td><span style="color:#7c3aed;font-size:11px"><?= $tP>0?formatTerlambat($tP):'-' ?></span></td>
                    <td style="font-size:11px"><?= formatDurasi($tD) ?></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <!-- Mobile detail -->
    <div class="mob" style="padding:10px">
    <?php $tT2=$tP2=$tD2=0;
    foreach($detailAbsen as $a):
        $tlbt=(int)($a['terlambat_detik']??0);$pcd=(int)($a['pulang_cepat_detik']??0);
        $tT2+=$tlbt;$tP2+=$pcd;$tD2+=(int)($a['durasi_kerja']??0);
        $st=$a['status_kehadiran']??'absen';[$stL,$stC,$stB]=statusConfig($st);
        $hStr=$hariId[date('D',strtotime($a['tanggal']))]??date('D',strtotime($a['tanggal']));
        $adaBukti=!empty($a['bukti_file']);
        $adaKet=!empty($a['keterangan']);
        $isKhusus=in_array($st,['dinas_luar','sakit','izin']);
        $isWE=in_array(date('N',strtotime($a['tanggal'])),[6,7]);
        $stI=match($st){'hadir'=>'fas fa-check-circle','terlambat'=>'fas fa-clock','absen'=>'fas fa-times-circle','izin'=>'fas fa-file-circle-check','sakit'=>'fas fa-hospital','dinas_luar'=>'fas fa-briefcase',default=>'fas fa-circle'};
        $buktiUrl='';
        if($adaBukti){ $bf=$a['bukti_file']; $buktiUrl=(strpos($bf,'http')===0)?$bf:$baseUrl.ltrim($bf,'/'); $isPdf=strtolower(pathinfo($bf,PATHINFO_EXTENSION))==='pdf'; }
        $modalData=json_encode(['tgl'=>date('d/m/Y',strtotime($a['tanggal'])),'st'=>$stL,'stC'=>$stC,'stB'=>$stB,'stI'=>$stI,'ket'=>$a['keterangan']??'','url'=>$buktiUrl,'isPdf'=>$adaBukti?$isPdf:false,'nama'=>$selectedK['nama']]);
    ?>
    <div class="di <?= htmlspecialchars($st) ?>">
        <div class="di-hd">
            <div>
                <span style="font-weight:800;font-size:13px"><?= date('d/m/Y',strtotime($a['tanggal'])) ?></span>
                <span style="font-size:11px;color:<?= $isWE?'#2563eb':'#64748b' ?>;margin-left:5px"><?= $hStr ?></span>
                <?php if($a['shift_nama']): ?><div style="font-size:10px;color:#94a3b8;margin-top:1px"><i class="fas fa-layer-group" style="font-size:8px"></i> <?= htmlspecialchars($a['shift_nama']) ?></div><?php endif; ?>
            </div>
            <span class="sp" style="background:<?= $stB ?>;color:<?= $stC ?>"><?= $stL ?></span>
        </div>
        <div class="di-row"><span class="di-key"><i class="fas fa-sign-in-alt" style="color:#10b981"></i> Masuk</span><span class="di-val" style="font-family:'JetBrains Mono',monospace"><?= $a['waktu_masuk']?date('H:i:s',strtotime($a['waktu_masuk'])):'-' ?></span></div>
        <div class="di-row"><span class="di-key"><i class="fas fa-sign-out-alt" style="color:#f59e0b"></i> Keluar</span><span class="di-val" style="font-family:'JetBrains Mono',monospace"><?= $a['waktu_keluar']?date('H:i:s',strtotime($a['waktu_keluar'])):'-' ?></span></div>
        <?php if($tlbt>0): ?><div class="di-row"><span class="di-key"><i class="fas fa-clock" style="color:#f59e0b"></i> Terlambat</span><span style="background:#fffbeb;color:#78350f;padding:2px 7px;border-radius:5px;font-size:11px;font-weight:700"><?= formatTerlambat($tlbt) ?></span></div><?php endif; ?>
        <?php if($pcd>0): ?><div class="di-row"><span class="di-key"><i class="fas fa-person-running" style="color:#7c3aed"></i> Plg.Cepat</span><span style="background:#faf5ff;color:#6d28d9;padding:2px 7px;border-radius:5px;font-size:11px;font-weight:700"><?= formatTerlambat($pcd) ?></span></div><?php endif; ?>
        <?php if($a['durasi_kerja']): ?><div class="di-row"><span class="di-key"><i class="fas fa-stopwatch" style="color:#3b82f6"></i> Durasi</span><span class="di-val"><?= formatDurasi($a['durasi_kerja']) ?></span></div><?php endif; ?>
        <?php if(!empty($a['lokasi_nama'])): ?><div class="di-row"><span class="di-key"><i class="fas fa-map-marker-alt"></i> Lokasi</span><span class="di-val" style="font-size:11.5px"><?= htmlspecialchars($a['lokasi_nama']) ?></span></div><?php endif; ?>

        <?php if($isKhusus && ($adaKet || $adaBukti)): ?>
        <div class="di-row" style="border:none;padding-top:8px">
            <button class="bukti-btn <?= match($st){'dinas_luar'=>'bukti-btn-dinas','sakit'=>'bukti-btn-sakit','izin'=>'bukti-btn-izin',default=>'bukti-btn-dinas'} ?>"
                    style="font-size:11.5px;padding:5px 12px"
                    onclick='showBuktiModal(<?= htmlspecialchars($modalData,ENT_QUOTES) ?>)'>
                <i class="<?= match($st){'dinas_luar'=>'fas fa-briefcase','sakit'=>'fas fa-hospital','izin'=>'fas fa-file-circle-check',default=>'fas fa-paperclip'} ?>"></i>
                <?= $adaBukti ? 'Lihat Bukti & Keterangan' : 'Lihat Keterangan' ?>
            </button>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <div style="background:#f0f4f8;border-radius:10px;padding:10px 12px;margin-top:4px">
        <div style="font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;margin-bottom:7px">Total <?= htmlspecialchars($period_label) ?></div>
        <?php if($tT2>0): ?><div class="di-row"><span class="di-key"><i class="fas fa-clock" style="color:#f59e0b"></i> Total Terlambat</span><span style="color:#d97706;font-weight:800"><?= formatTerlambat($tT2) ?></span></div><?php endif; ?>
        <?php if($tP2>0): ?><div class="di-row"><span class="di-key"><i class="fas fa-person-running" style="color:#7c3aed"></i> Total PC</span><span style="color:#7c3aed;font-weight:800"><?= formatTerlambat($tP2) ?></span></div><?php endif; ?>
        <div class="di-row" style="border:none"><span class="di-key"><i class="fas fa-stopwatch" style="color:#3b82f6"></i> Total Durasi</span><span style="font-weight:800"><?= formatDurasi($tD2) ?></span></div>
    </div>
    </div>

    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ══ MODAL BUKTI ══ -->
<div class="bukti-overlay" id="buktiOverlay" onclick="if(event.target===this)closeBuktiModal()">
    <div class="bukti-modal">
        <div class="bukti-modal-head">
            <div class="bukti-modal-title" id="bmTitle">Detail Keterangan</div>
            <button class="bukti-modal-close" onclick="closeBuktiModal()"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="bukti-modal-body" id="bmBody">
            <!-- diisi JS -->
        </div>
    </div>
</div>

<script>
// ── Data bukti dinas/sakit/izin per karyawan (dari PHP) ──
const BUKTI_DATA = <?php
$buktiJson = [];
foreach ($buktiPerKaryawan as $kid => $rows) {
    foreach ($rows as $bk) {
        $bf  = $bk['bukti_file'] ?? '';
        $url = '';
        if ($bf) {
            $url = (strpos($bf,'http')===0) ? $bf : $baseUrl . ltrim($bf,'/');
        }
        $isPdf = $bf ? strtolower(pathinfo($bf, PATHINFO_EXTENSION))==='pdf' : false;
        $buktiJson[$kid][] = [
            'tgl'    => date('d/m/Y', strtotime($bk['tanggal'])),
            'st'     => $bk['status_kehadiran'],
            'ket'    => $bk['keterangan'] ?? '',
            'url'    => $url,
            'isPdf'  => $isPdf,
            'nama'   => $bk['karyawan_nama'],
        ];
    }
}
echo json_encode($buktiJson);
?>;

// Tampilkan semua bukti dinas/sakit/izin satu karyawan dalam periode ini
function showBuktiKaryawan(kId, namaK) {
    const rows = BUKTI_DATA[kId] || [];
    if (!rows.length) return;

    document.getElementById('bmTitle').innerHTML =
        `<i class="fas fa-folder-open" style="color:#0f4c81;margin-right:6px"></i>
         Bukti Laporan — ${namaK}`;

    const stLabel = {'dinas_luar':'Dinas Luar','sakit':'Sakit','izin':'Izin'};
    const stColor = {'dinas_luar':'#0f4c81','sakit':'#f43f5e','izin':'#8b5cf6'};
    const stBg    = {'dinas_luar':'#eff6ff','sakit':'#fff1f2','izin':'#faf5ff'};
    const stIcon  = {'dinas_luar':'fas fa-briefcase','sakit':'fas fa-hospital','izin':'fas fa-file-circle-check'};

    let html = '';
    rows.forEach((r, i) => {
        const label = stLabel[r.st] || r.st;
        const col   = stColor[r.st] || '#64748b';
        const bg    = stBg[r.st]   || '#f8fafc';
        const ico   = stIcon[r.st] || 'fas fa-circle';

        html += `<div style="border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;margin-bottom:12px">
            <div style="background:#f8fafc;padding:10px 14px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:6px">
                <div style="display:flex;align-items:center;gap:8px">
                    <span style="font-size:12px;font-weight:800;color:#0f172a">${r.tgl}</span>
                    <span style="display:inline-flex;align-items:center;gap:4px;background:${bg};color:${col};padding:2px 8px;border-radius:20px;font-size:11px;font-weight:800">
                        <i class="${ico}" style="font-size:9px"></i> ${label}
                    </span>
                </div>
            </div>
            <div style="padding:12px 14px">`;

        // Keterangan
        if (r.ket) {
            const tujuan = r.ket.match(/Tujuan:\s*([^|]+)/i);
            const jenis  = r.ket.match(/Jenis:\s*([^|]+)/i);
            const ketBersih = r.ket.replace(/^(Tujuan:|Jenis:)[^|]*\|\s*/i, '').trim();

            if (tujuan) html += `<div style="display:flex;align-items:center;gap:6px;background:#f0f9ff;border-radius:7px;padding:7px 10px;margin-bottom:8px"><i class="fas fa-map-marker-alt" style="color:#0f4c81;font-size:12px;flex-shrink:0"></i><div><div style="font-size:9.5px;font-weight:700;color:#64748b;text-transform:uppercase">Tujuan</div><div style="font-size:12.5px;font-weight:700;color:#0f172a">${tujuan[1].trim()}</div></div></div>`;
            if (jenis)  html += `<div style="display:flex;align-items:center;gap:6px;background:#faf5ff;border-radius:7px;padding:7px 10px;margin-bottom:8px"><i class="fas fa-tag" style="color:#8b5cf6;font-size:12px;flex-shrink:0"></i><div><div style="font-size:9.5px;font-weight:700;color:#64748b;text-transform:uppercase">Jenis Izin</div><div style="font-size:12.5px;font-weight:700;color:#0f172a">${jenis[1].trim()}</div></div></div>`;
            if (ketBersih || (!tujuan && !jenis)) {
                const txt = ketBersih || r.ket;
                html += `<div style="background:#f8fafc;border-radius:7px;padding:8px 12px;font-size:12.5px;color:#374151;line-height:1.6;border-left:3px solid #e2e8f0;margin-bottom:8px">${txt}</div>`;
            }
        }

        // File bukti
        if (r.url) {
            if (r.isPdf) {
                html += `<a href="${r.url}" target="_blank" style="display:flex;align-items:center;gap:10px;padding:11px 14px;background:#fef2f2;border-radius:8px;border:1px solid #fecaca;text-decoration:none;color:#991b1b;font-weight:700;font-size:13px"><i class="fas fa-file-pdf" style="font-size:20px"></i><div><div style="font-size:12px;font-weight:800">Surat / Dokumen PDF</div><div style="font-size:11px;opacity:.7">Tap untuk membuka</div></div><i class="fas fa-external-link-alt" style="margin-left:auto"></i></a>`;
            } else {
                html += `<img src="${r.url}" style="width:100%;border-radius:8px;border:2px solid #e2e8f0;display:block" alt="Bukti" onerror="this.outerHTML='<div style=\'text-align:center;padding:20px;color:#94a3b8;font-size:12px\'>Gambar tidak dapat dimuat</div>'">
                    <div style="text-align:right;margin-top:6px"><a href="${r.url}" target="_blank" style="font-size:12px;font-weight:700;color:#0f4c81;text-decoration:none"><i class="fas fa-external-link-alt"></i> Buka gambar</a></div>`;
            }
        } else {
            html += `<div style="text-align:center;padding:14px;color:#94a3b8;font-size:12px;font-weight:600"><i class="fas fa-paperclip-slash" style="display:block;font-size:20px;margin-bottom:4px;opacity:.4"></i>Tidak ada file bukti</div>`;
        }

        html += '</div></div>';
    });

    document.getElementById('bmBody').innerHTML = html;
    document.getElementById('buktiOverlay').classList.add('open');
}

function raTab(mode,btn){
    document.getElementById('raMode').value=mode;
    document.querySelectorAll('.ra-tab').forEach(t=>t.classList.remove('active'));
    document.querySelectorAll('.ra-pane').forEach(p=>p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('pane-'+mode).classList.add('active');
}
document.getElementById('raForm').addEventListener('submit',function(e){
    const d=document.querySelector('[name="tgl_dari"]'),s=document.querySelector('[name="tgl_sampai"]');
    if(d&&s&&d.value&&s.value&&d.value>s.value){e.preventDefault();alert('Tanggal "Dari" tidak boleh lebih besar dari "Sampai".');}
});

function showBuktiModal(data) {
    const stLabel = {
        'dinas_luar': 'Dinas Luar',
        'sakit': 'Sakit',
        'izin': 'Izin',
    };

    // Judul modal
    document.getElementById('bmTitle').innerHTML =
        `<i class="fas fa-folder-open" style="color:#0f4c81;margin-right:6px"></i>
         Keterangan &amp; Bukti — ${data.nama}`;

    let html = '';

    // Meta grid
    html += `<div class="bukti-modal-meta">
        <div class="bukti-meta-item">
            <div class="bukti-meta-label">Tanggal</div>
            <div class="bukti-meta-val">${data.tgl}</div>
        </div>
        <div class="bukti-meta-item">
            <div class="bukti-meta-label">Status</div>
            <div class="bukti-meta-val">
                <span style="display:inline-flex;align-items:center;gap:5px;background:${data.stB};color:${data.stC};padding:3px 10px;border-radius:20px;font-size:12px;font-weight:800">
                    <i class="${data.stI}" style="font-size:10px"></i> ${data.st}
                </span>
            </div>
        </div>
    </div>`;

    // Keterangan
    if (data.ket) {
        // Bersihkan prefix seperti "Tujuan: ...", "Jenis: ..." dari keterangan
        const ketBersih = data.ket.replace(/^(Tujuan:|Jenis:)[^|]*\|\s*/i, '');
        const tujuan    = data.ket.match(/Tujuan:\s*([^|]+)/i);
        const jenis     = data.ket.match(/Jenis:\s*([^|]+)/i);

        if (tujuan) {
            html += `<div class="bukti-meta-item" style="margin-bottom:8px;display:flex;align-items:center;gap:8px;background:#f0f9ff;border-radius:8px;padding:8px 12px">
                <i class="fas fa-map-marker-alt" style="color:#0f4c81;font-size:14px;flex-shrink:0"></i>
                <div><div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:1px">Tujuan Dinas</div>
                <div style="font-size:13px;font-weight:700;color:#0f172a">${tujuan[1].trim()}</div></div>
            </div>`;
        }
        if (jenis) {
            html += `<div class="bukti-meta-item" style="margin-bottom:8px;display:flex;align-items:center;gap:8px;background:#faf5ff;border-radius:8px;padding:8px 12px">
                <i class="fas fa-tag" style="color:#8b5cf6;font-size:14px;flex-shrink:0"></i>
                <div><div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:1px">Jenis Izin</div>
                <div style="font-size:13px;font-weight:700;color:#0f172a">${jenis[1].trim()}</div></div>
            </div>`;
        }

        html += `<div class="bukti-ket-box">
            <i class="fas fa-comment-lines" style="color:#64748b;margin-right:4px;flex-shrink:0"></i>
            ${ketBersih.trim() || data.ket}
        </div>`;
    }

    // File bukti
    if (data.url) {
        if (data.isPdf) {
            html += `<a href="${data.url}" target="_blank" class="bukti-pdf-link">
                <i class="fas fa-file-pdf" style="font-size:22px"></i>
                <div>
                    <div style="font-size:12px;font-weight:800">Surat / Dokumen PDF</div>
                    <div style="font-size:11px;opacity:.7">Tap untuk membuka di tab baru</div>
                </div>
                <i class="fas fa-external-link-alt" style="margin-left:auto;font-size:14px"></i>
            </a>`;
        } else {
            html += `<div style="margin-bottom:6px;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px">
                <i class="fas fa-image"></i> File Bukti
            </div>
            <img src="${data.url}" alt="Bukti" class="bukti-img"
                 onerror="this.outerHTML='<div class=\\'bukti-no-file\\'><i class=\\'fas fa-image-slash\\' style=\\'font-size:28px;display:block;margin-bottom:6px;opacity:.4\\'></i>Gambar tidak dapat dimuat</div>'">
            <div style="text-align:right;margin-top:8px">
                <a href="${data.url}" target="_blank" style="font-size:12px;font-weight:700;color:#0f4c81;text-decoration:none">
                    <i class="fas fa-external-link-alt"></i> Buka di tab baru
                </a>
            </div>`;
        }
    } else if (!data.ket) {
        html += `<div class="bukti-no-file">
            <i class="fas fa-folder-open" style="font-size:28px;display:block;margin-bottom:6px;opacity:.4"></i>
            Tidak ada keterangan atau bukti
        </div>`;
    }

    document.getElementById('bmBody').innerHTML = html;
    document.getElementById('buktiOverlay').classList.add('open');
}

function closeBuktiModal(){
    document.getElementById('buktiOverlay').classList.remove('open');
}

// Escape ESC key
document.addEventListener('keydown', e => { if(e.key==='Escape') closeBuktiModal(); });
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>