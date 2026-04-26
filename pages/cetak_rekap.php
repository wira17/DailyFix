<?php
require_once __DIR__ . '/../includes/config.php';
requireAdmin();

$user = currentUser();
$db   = getDB();

// ── Mode filter ──
$mode_filter   = $_GET['mode_filter']   ?? 'bulanan';
$bulan         = (int)($_GET['bulan']   ?? date('m'));
$tahun         = (int)($_GET['tahun']   ?? date('Y'));
$tgl_dari      = $_GET['tgl_dari']      ?? date('Y-m-01');
$tgl_sampai    = $_GET['tgl_sampai']    ?? date('Y-m-d');
$karyawan_id   = (int)($_GET['karyawan_id']   ?? 0);
$departemen_id = (int)($_GET['departemen_id'] ?? 0);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl_dari))   $tgl_dari   = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl_sampai)) $tgl_sampai = date('Y-m-d');

$monthsId = ['','Januari','Februari','Maret','April','Mei','Juni',
             'Juli','Agustus','September','Oktober','November','Desember'];
$hariId   = ['Mon'=>'Senin','Tue'=>'Selasa','Wed'=>'Rabu','Thu'=>'Kamis',
             'Fri'=>'Jumat','Sat'=>'Sabtu','Sun'=>'Minggu'];
$statusLabel = [
    'hadir'      => 'Hadir',
    'terlambat'  => 'Terlambat',
    'absen'      => 'Alpha',
    'izin'       => 'Izin',
    'sakit'      => 'Sakit',
    'dinas_luar' => 'Dinas Luar',
    'libur'      => 'Libur',
    'cuti'       => 'Cuti',
];

// ── Periode ──
if ($mode_filter === 'periode') {
    $period_label = 'Periode ' . date('d/m/Y', strtotime($tgl_dari)) . ' s/d ' . date('d/m/Y', strtotime($tgl_sampai));
    $date_cond    = "a.tanggal BETWEEN ? AND ?";
    $date_params  = [$tgl_dari, $tgl_sampai];
    $judulPDF     = "Rekap_Periode_{$tgl_dari}_{$tgl_sampai}.pdf";
} else {
    $period       = $tahun . '-' . str_pad($bulan, 2, '0', STR_PAD_LEFT);
    $period_label = $monthsId[$bulan] . ' ' . $tahun;
    $date_cond    = "DATE_FORMAT(a.tanggal,'%Y-%m')=?";
    $date_params  = [$period];
    $judulPDF     = "Rekap_{$monthsId[$bulan]}_{$tahun}.pdf";
}

// Base URL untuk link bukti
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']==='on' ? 'https' : 'http')
         . '://' . $_SERVER['HTTP_HOST']
         . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/') . '/';

// ── Info perusahaan ──
$stmtP = $db->prepare("SELECT * FROM perusahaan WHERE id=? LIMIT 1");
$stmtP->execute([$user['perusahaan_id']]);
$perusahaan     = $stmtP->fetch();
$namaPerusahaan = $perusahaan['nama']    ?? 'PT. DailyFix Indonesia';
$alamat         = $perusahaan['alamat']  ?? '';
$telepon        = $perusahaan['telepon'] ?? '';
$emailP         = $perusahaan['email']   ?? '';

// ── Departemen ──
$namaDepartemen = '';
if ($departemen_id) {
    $stmtDep = $db->prepare("SELECT nama FROM departemen WHERE id=? AND perusahaan_id=?");
    $stmtDep->execute([$departemen_id, $user['perusahaan_id']]);
    $dep = $stmtDep->fetch();
    $namaDepartemen = $dep['nama'] ?? '';
}

// ── Helpers ──
function fmtTelat($d) {
    if (!$d) return '-';
    $j=floor($d/3600); $m=floor(($d%3600)/60); $s=$d%60;
    $o=[];
    if($j) $o[]=$j.'j'; if($m) $o[]=$m.'m'; if($s&&!$j) $o[]=$s.'d';
    return implode(' ',$o) ?: '-';
}
function fmtDur($mnt) {
    if (!$mnt) return '-';
    $j=floor($mnt/60); $m=$mnt%60;
    return ($j?$j.'j ':'').$m.'m';
}
function hitungHariKerjaCetak($dari, $sampai) {
    $count=0; $d=strtotime($dari); $s=strtotime($sampai);
    while($d<=$s){ if((int)date('N',$d)<=5) $count++; $d=strtotime('+1 day',$d); }
    return $count;
}
function potong($str, $max=28) {
    return mb_strlen($str)>$max ? mb_substr($str,0,$max).'…' : $str;
}

// ── Hari kerja ──
if ($mode_filter === 'periode') {
    $jumlahHariKerja = hitungHariKerjaCetak($tgl_dari, $tgl_sampai);
} else {
    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $bulan, $tahun);
    $jumlahHariKerja = hitungHariKerjaCetak(
        $tahun.'-'.str_pad($bulan,2,'0',STR_PAD_LEFT).'-01',
        $tahun.'-'.str_pad($bulan,2,'0',STR_PAD_LEFT).'-'.$daysInMonth
    );
}

// ══════════════════════════════════
// MODE DETAIL 1 KARYAWAN
// ══════════════════════════════════
if ($karyawan_id) {
    $stmtK = $db->prepare("SELECT k.*, j.nama as jabatan_nama, dep.nama as departemen_nama
        FROM karyawan k
        LEFT JOIN jabatan j   ON j.id=k.jabatan_id
        LEFT JOIN departemen dep ON dep.id=k.departemen_id
        WHERE k.id=? AND k.perusahaan_id=?");
    $stmtK->execute([$karyawan_id, $user['perusahaan_id']]);
    $karyawan = $stmtK->fetch();
    if (!$karyawan) die('Karyawan tidak ditemukan.');

    $sqlD = "SELECT a.*, s.nama as shift_nama, s.jam_masuk, s.jam_keluar, l.nama as lokasi_nama
        FROM absensi a
        LEFT JOIN shift s  ON s.id=a.shift_id
        LEFT JOIN lokasi l ON l.id=a.lokasi_id
        WHERE a.karyawan_id=? AND {$date_cond}
        ORDER BY a.tanggal ASC";
    $stmtD = $db->prepare($sqlD);
    $stmtD->execute(array_merge([$karyawan_id], $date_params));
    $rows = $stmtD->fetchAll();

    $stat = ['hadir'=>0,'terlambat'=>0,'pulang_cepat'=>0,'absen'=>0,'izin'=>0,'sakit'=>0,'dinas_luar'=>0];
    $totalTelat=$totalPulang=$totalMenit=0;
    foreach ($rows as $r) {
        $s = $r['status_kehadiran'];
        if (in_array($s,['hadir','terlambat'])) $stat['hadir']++;
        if ($s==='terlambat')  $stat['terlambat']++;
        if ($s==='absen')      $stat['absen']++;
        if ($s==='izin')       $stat['izin']++;
        if ($s==='sakit')      $stat['sakit']++;
        if ($s==='dinas_luar') $stat['dinas_luar']++;
        $pc=(int)($r['pulang_cepat_detik']??0);
        if($pc>0) $stat['pulang_cepat']++;
        $totalTelat  += (int)($r['terlambat_detik']??0);
        $totalPulang += $pc;
        $totalMenit  += (int)($r['durasi_kerja']??0);
    }
    $mode     = 'detail';
    $judulPDF = "Rekap_{$karyawan['nama']}_{$period_label}.pdf";

// ══════════════════════════════════
// MODE SEMUA / PER DEPARTEMEN
// ══════════════════════════════════
} else {
    $karyawan = null;
    $sql = "SELECT k.id, k.nik, k.nama,
        dep.nama as departemen_nama,
        SUM(CASE WHEN a.status_kehadiran IN ('hadir','terlambat') THEN 1 ELSE 0 END) as hadir,
        SUM(CASE WHEN a.status_kehadiran = 'terlambat'  THEN 1 ELSE 0 END) as terlambat,
        SUM(CASE WHEN IFNULL(a.pulang_cepat_detik,0)>0  THEN 1 ELSE 0 END) as pulang_cepat,
        SUM(CASE WHEN a.status_kehadiran = 'absen'      THEN 1 ELSE 0 END) as absen,
        SUM(CASE WHEN a.status_kehadiran = 'izin'       THEN 1 ELSE 0 END) as izin,
        SUM(CASE WHEN a.status_kehadiran = 'sakit'      THEN 1 ELSE 0 END) as sakit,
        SUM(CASE WHEN a.status_kehadiran = 'dinas_luar' THEN 1 ELSE 0 END) as dinas_luar,
        SUM(COALESCE(a.terlambat_detik,0))    as total_terlambat_detik,
        SUM(COALESCE(a.pulang_cepat_detik,0)) as total_pulang_cepat_detik,
        SUM(COALESCE(a.durasi_kerja,0))        as total_durasi
        FROM karyawan k
        LEFT JOIN departemen dep ON dep.id=k.departemen_id
        LEFT JOIN absensi a ON a.karyawan_id=k.id AND {$date_cond}
        WHERE k.perusahaan_id=? AND k.role='karyawan'";
    $params = array_merge($date_params, [$user['perusahaan_id']]);
    if ($departemen_id) { $sql .= " AND k.departemen_id=?"; $params[] = $departemen_id; }
    $sql .= " GROUP BY k.id ORDER BY dep.nama, k.nama";
    $stmtAll = $db->prepare($sql);
    $stmtAll->execute($params);
    $rows = $stmtAll->fetchAll();
    $mode = 'all';
    if ($namaDepartemen) $judulPDF = "Rekap_{$namaDepartemen}_{$period_label}.pdf";
    else                 $judulPDF = "Rekap_Karyawan_{$period_label}.pdf";
}

ob_start();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:Helvetica,Arial,sans-serif; font-size:8.5pt; color:#1a1a2e; background:#fff; line-height:1.4; padding:1.5cm 1.8cm; }

/* ── KOP ── */
.kop-table { width:100%; border-collapse:collapse; margin-bottom:0; }
.kop-logo-cell { width:52px; vertical-align:middle; padding-right:10px; }
.logo-box { width:44px; height:44px; background:#0f4c81; border-radius:6px; text-align:center; line-height:44px; font-size:20pt; font-weight:900; color:#fff; }
.kop-text-cell { vertical-align:middle; }
.kop-company { font-size:13pt; font-weight:900; color:#0f4c81; }
.kop-sub { font-size:7.5pt; color:#555; margin-top:2px; }
.kop-right-cell { vertical-align:top; text-align:right; font-size:7.5pt; color:#555; }
.kop-divider { border:none; border-top:3px solid #0f4c81; margin:8px 0 0 0; }
.kop-divider-thin { border:none; border-top:1px solid #b0c4de; margin:2px 0 10px 0; }

/* ── JUDUL ── */
.judul-wrap { text-align:center; margin:12px 0 14px; }
.judul-wrap .label-laporan { font-size:7.5pt; font-weight:700; color:#0f4c81; letter-spacing:2px; text-transform:uppercase; border:1.5px solid #0f4c81; display:inline-block; padding:2px 12px; border-radius:2px; margin-bottom:5px; }
.judul-wrap h2 { font-size:13pt; font-weight:900; color:#1a1a2e; text-transform:uppercase; }
.judul-wrap .periode { font-size:8.5pt; color:#444; margin-top:3px; }
.dept-badge { display:inline-block; background:#eef3fa; border:1.5px solid #0f4c81; color:#0f4c81; border-radius:3px; padding:2px 10px; font-size:7.5pt; font-weight:700; margin-top:5px; }

/* ── INFO TABLE ── */
.info-table { width:100%; border-collapse:collapse; margin-bottom:14px; }
.info-table td { padding:4px 8px; font-size:8pt; border:1px solid #dce5f0; }
.info-table td.lbl { background:#eef3fa; color:#0f4c81; font-weight:700; width:22%; }
.info-table td.val { color:#222; width:28%; }

/* ── STAT BOXES ── */
.stat-table { width:100%; border-collapse:separate; border-spacing:4px; margin-bottom:14px; }
.stat-cell { text-align:center; border-radius:5px; padding:7px 4px; border:1.5px solid #e2e8f0; background:#f8fafc; }
.stat-num { font-size:14pt; font-weight:900; display:block; line-height:1.1; }
.stat-lbl { font-size:6pt; font-weight:700; text-transform:uppercase; letter-spacing:.4px; display:block; margin-top:3px; color:#64748b; }

/* ── MAIN TABLE ── */
.main-table { width:100%; border-collapse:collapse; font-size:8pt; margin-bottom:16px; }
.main-table thead tr.th-main td { background:#0f4c81; color:#fff; font-weight:700; padding:6px 4px; text-align:center; border:1px solid #0a3260; font-size:7pt; text-transform:uppercase; }
.main-table thead tr.th-main td.left { text-align:left; padding-left:6px; }
.main-table thead tr.th-sub td { background:#d6e4f7; color:#0f4c81; font-size:7pt; font-weight:700; text-align:center; padding:4px; border:1px solid #b8d0eb; }
.main-table tbody td { padding:5px 4px; border:1px solid #dce5f0; vertical-align:middle; font-size:7.5pt; }
.main-table tbody tr:nth-child(even) td { background:#f7faff; }
.main-table tbody tr.total-row td { background:#eef3fa; font-weight:700; border-top:2px solid #0f4c81; }
.main-table tbody tr.dept-header td { background:#d6e4f7; color:#0f4c81; font-weight:700; font-size:7.5pt; border:1px solid #b8d0eb; padding:5px 8px; }

/* ── STATUS BADGE ── */
.badge { display:inline-block; border-radius:2px; padding:1.5px 5px; font-size:6.5pt; font-weight:700; color:#fff; white-space:nowrap; }
.b-hadir     { background:#16a34a; }
.b-terlambat { background:#d97706; }
.b-absen     { background:#dc2626; }
.b-izin      { background:#2563eb; }
.b-sakit     { background:#7c3aed; }
.b-dinas_luar{ background:#0f4c81; }
.b-libur     { background:#6b7280; }
.b-cuti      { background:#6366f1; }

/* ── KETERANGAN KHUSUS (dinas/sakit/izin) ── */
.ket-row td { background:#f0f6ff!important; border-top:none!important; padding:3px 6px 6px 6px!important; }
.ket-box { border-left:3px solid #b8d0eb; padding:4px 8px; font-size:7pt; color:#374151; line-height:1.5; border-radius:0 3px 3px 0; background:#fff; }
.ket-tujuan { font-weight:700; color:#0f4c81; }
.ket-sakit  { font-weight:700; color:#7c3aed; }
.ket-izin   { font-weight:700; color:#2563eb; }
.bukti-link { display:inline-block; font-size:6.5pt; font-weight:700; color:#0f4c81; border:1px solid #b8d0eb; border-radius:2px; padding:1px 5px; margin-top:2px; text-decoration:none; }

/* ── MISC ── */
.center { text-align:center; }
.right  { text-align:right; }
.mono   { font-family:Courier,monospace; }
.row-no { color:#999; font-size:7pt; text-align:center; }
.pct-bar-wrap { display:inline-block; width:34px; height:5px; background:#e5e7eb; border-radius:3px; vertical-align:middle; margin-left:2px; }
.pct-bar { height:5px; border-radius:3px; }

/* ── TTD ── */
.ttd-table { width:100%; border-collapse:collapse; }
.ttd-table td { vertical-align:top; padding:0; font-size:8pt; }
.ttd-space { height:52px; }
.ttd-line { border-top:1px solid #1a1a2e; display:inline-block; min-width:160px; padding-top:3px; font-weight:700; font-size:8.5pt; }
.ttd-jabatan { font-size:7.5pt; color:#555; }

/* ── FOOTER ── */
.page-footer { margin-top:14px; border-top:2px solid #0f4c81; padding-top:5px; }
.footer-table { width:100%; border-collapse:collapse; }
.footer-table td { font-size:7pt; color:#888; }
.footer-table td.fr { text-align:right; }
</style>
</head>
<body>

<!-- ── KOP ── -->
<table class="kop-table">
<tr>
    <td class="kop-logo-cell"><div class="logo-box">D</div></td>
    <td class="kop-text-cell">
        <div class="kop-company"><?= htmlspecialchars($namaPerusahaan) ?></div>
        <div class="kop-sub"><?= htmlspecialchars($alamat) ?></div>
        <div class="kop-sub">
            <?= $telepon ? 'Telp: '.htmlspecialchars($telepon) : '' ?>
            <?= ($telepon && $emailP) ? ' | ' : '' ?>
            <?= $emailP  ? 'Email: '.htmlspecialchars($emailP) : '' ?>
        </div>
    </td>
    <td class="kop-right-cell">
        <span style="font-size:8pt;font-weight:700;color:#0f4c81">LAPORAN ABSENSI</span><br>
        Dicetak: <?= date('d/m/Y H:i') ?><br>
        Oleh: <?= htmlspecialchars($user['nama']) ?>
    </td>
</tr>
</table>
<hr class="kop-divider"><hr class="kop-divider-thin">

<!-- ── JUDUL ── -->
<div class="judul-wrap">
    <div class="label-laporan">Laporan Kehadiran Karyawan</div><br>
    <h2><?= $mode==='detail' ? 'REKAP ABSENSI INDIVIDU' : 'REKAP ABSENSI SELURUH KARYAWAN' ?></h2>
    <div class="periode">
        Periode: <strong><?= htmlspecialchars($period_label) ?></strong>
        &mdash; Hari Kerja (Sen–Jum): <strong><?= $jumlahHariKerja ?> hari</strong>
    </div>
    <?php if ($namaDepartemen && $mode==='all'): ?>
    <div style="margin-top:6px"><span class="dept-badge">&#x1F3E2; Departemen: <?= htmlspecialchars($namaDepartemen) ?></span></div>
    <?php endif; ?>
</div>

<?php if ($mode === 'detail'): ?>
<!-- ═══════════════════════════════════
     DETAIL 1 KARYAWAN
═══════════════════════════════════ -->
<table class="info-table">
<tr>
    <td class="lbl">Nama</td><td class="val"><?= htmlspecialchars($karyawan['nama']) ?></td>
    <td class="lbl">Departemen</td><td class="val"><?= htmlspecialchars($karyawan['departemen_nama']??'-') ?></td>
</tr>
<tr>
    <td class="lbl">NIK</td><td class="val mono"><?= htmlspecialchars($karyawan['nik']) ?></td>
    <td class="lbl">Jabatan</td><td class="val"><?= htmlspecialchars($karyawan['jabatan_nama']??'-') ?></td>
</tr>
</table>

<!-- STAT BOXES -->
<table class="stat-table"><tr>
<?php
$statsDetail = [
    [$stat['hadir'],        '#16a34a','#dcfce7','Hari Hadir'],
    [$stat['terlambat'],    '#d97706','#fef3c7','Terlambat'],
    [$stat['pulang_cepat'], '#7c3aed','#f3e8ff','Plg. Cepat'],
    [$stat['absen'],        '#dc2626','#fee2e2','Alpha'],
    [$stat['izin'],         '#2563eb','#dbeafe','Izin'],
    [$stat['sakit'],        '#9333ea','#ede9fe','Sakit'],
    [$stat['dinas_luar'],   '#0f4c81','#eff6ff','Dinas Luar'],
    [fmtTelat($totalTelat),  '#d97706','#fffbeb','Total Telat'],
    [fmtDur($totalMenit),    '#0d9488','#d1fae5','Total Jam'],
];
foreach ($statsDetail as $s): ?>
<td class="stat-cell" style="border-color:<?= $s[1] ?>;background:<?= $s[2] ?>">
    <span class="stat-num" style="color:<?= $s[1] ?>"><?= $s[0]!==null&&$s[0]!==''?$s[0]:'0' ?></span>
    <span class="stat-lbl"><?= $s[3] ?></span>
</td>
<?php endforeach; ?>
</tr></table>

<!-- TABEL DETAIL PER HARI -->
<table class="main-table">
<thead>
    <tr class="th-main">
        <td style="width:18px">No</td>
        <td class="left" style="width:50px">Tanggal</td>
        <td style="width:36px">Hari</td>
        <td class="left" style="width:60px">Shift</td>
        <td style="width:46px">Masuk</td>
        <td style="width:46px">Keluar</td>
        <td style="width:60px">Status</td>
        <td style="width:44px">Terlambat</td>
        <td style="width:44px">Plg.Cepat</td>
        <td style="width:34px">Durasi</td>
        <td style="width:24px">Jarak</td>
    </tr>
</thead>
<tbody>
<?php if(empty($rows)): ?>
<tr><td colspan="11" class="center" style="padding:14px;color:#888">Tidak ada data</td></tr>
<?php else:
foreach ($rows as $i => $r):
    $hari   = $hariId[date('D',strtotime($r['tanggal']))] ?? date('D',strtotime($r['tanggal']));
    $st     = $r['status_kehadiran'] ?? 'absen';
    $telat  = (int)($r['terlambat_detik']    ?? 0);
    $pulang = (int)($r['pulang_cepat_detik'] ?? 0);
    $isWE   = in_array(date('N',strtotime($r['tanggal'])),[6,7]);
    $rowBg  = $isWE ? 'background:#f0f4ff;' : '';
    $isKhusus = in_array($st,['dinas_luar','sakit','izin']);
    $ket    = $r['keterangan'] ?? '';
    $adaBukti = !empty($r['bukti_file']);
    $buktiUrl = '';
    if ($adaBukti) {
        $bf = $r['bukti_file'];
        $buktiUrl = (strpos($bf,'http')===0) ? $bf : $baseUrl . ltrim($bf,'/');
    }
?>
<tr>
    <td class="row-no" style="<?= $rowBg ?>"><?= $i+1 ?></td>
    <td class="mono center" style="<?= $rowBg ?>;font-size:7.5pt"><?= date('d/m/Y',strtotime($r['tanggal'])) ?></td>
    <td class="center" style="<?= $rowBg ?>;font-size:7.5pt;color:<?= $isWE?'#2563eb':'inherit' ?>"><?= $hari ?></td>
    <td style="<?= $rowBg ?>;font-size:7pt"><?= htmlspecialchars(potong($r['shift_nama']??'-',14)) ?></td>
    <td class="mono center" style="<?= $rowBg ?>;font-size:7.5pt">
        <?= $r['waktu_masuk'] ? date('H:i',strtotime($r['waktu_masuk'])) : '-' ?>
        <?php if($r['waktu_masuk']&&$r['jam_masuk']): ?>
        <div style="font-size:6pt;color:#94a3b8"><?= substr($r['jam_masuk'],0,5) ?></div>
        <?php endif; ?>
    </td>
    <td class="mono center" style="<?= $rowBg ?>;font-size:7.5pt">
        <?= $r['waktu_keluar'] ? date('H:i',strtotime($r['waktu_keluar'])) : '-' ?>
        <?php if($r['waktu_keluar']&&$r['jam_keluar']): ?>
        <div style="font-size:6pt;color:#94a3b8"><?= substr($r['jam_keluar'],0,5) ?></div>
        <?php endif; ?>
    </td>
    <td class="center" style="<?= $rowBg ?>">
        <span class="badge b-<?= $st ?>"><?= $statusLabel[$st] ?? ucfirst($st) ?></span>
    </td>
    <td class="center" style="<?= $rowBg ?>;color:<?= $telat>0?'#d97706':'#ccc' ?>;font-size:7.5pt">
        <?= fmtTelat($telat) ?>
    </td>
    <td class="center" style="<?= $rowBg ?>;color:<?= $pulang>0?'#7c3aed':'#ccc' ?>;font-size:7.5pt">
        <?= fmtTelat($pulang) ?>
    </td>
    <td class="center" style="<?= $rowBg ?>;font-size:7.5pt"><?= fmtDur($r['durasi_kerja']) ?></td>
    <td class="center" style="<?= $rowBg ?>;font-size:7pt;color:#888">
        <?= $r['jarak_masuk'] ? $r['jarak_masuk'].'m' : '-' ?>
    </td>
</tr>
<?php
// Row keterangan khusus (dinas/sakit/izin)
if ($isKhusus && ($ket || $adaBukti)):
    $tujuan = '';$jenis='';$ketBersih=$ket;
    if (preg_match('/Tujuan:\s*([^|]+)/i',$ket,$m)) $tujuan = trim($m[1]);
    if (preg_match('/Jenis:\s*([^|]+)/i',$ket,$m))  $jenis  = trim($m[1]);
    $ketBersih = preg_replace('/^(Tujuan:|Jenis:)[^|]*\|\s*/i','',$ket);
    $ketBersih = trim($ketBersih);
?>

<?php endif; ?>
<?php endforeach; endif; ?>
</tbody>
<tfoot>
    <tr class="total-row">
        <td colspan="7" class="right" style="font-size:7.5pt">TOTAL PERIODE INI :</td>
        <td class="center" style="color:#d97706"><?= fmtTelat($totalTelat) ?></td>
        <td class="center" style="color:#7c3aed"><?= fmtTelat($totalPulang) ?></td>
        <td class="center" style="color:#0d9488"><?= fmtDur($totalMenit) ?></td>
        <td></td>
    </tr>
</tfoot>
</table>

<?php else: ?>
<!-- ═══════════════════════════════════
     SEMUA / PER DEPARTEMEN
═══════════════════════════════════ -->
<?php
// Hitung total keseluruhan untuk summary box
$tH=$tT=$tPC=$tA=$tI=$tS=$tDL=0;
foreach ($rows as $r) {
    $tH  += (int)$r['hadir'];
    $tT  += (int)$r['terlambat'];
    $tPC += (int)$r['pulang_cepat'];
    $tA  += (int)$r['absen'];
    $tI  += (int)$r['izin'];
    $tS  += (int)$r['sakit'];
    $tDL += (int)$r['dinas_luar'];
}
?>
<!-- SUMMARY BOXES -->
<table class="stat-table"><tr>
<?php foreach([
    [$tH,  '#16a34a','#dcfce7','Total Hadir'],
    [$tT,  '#d97706','#fef3c7','Terlambat'],
    [$tA,  '#dc2626','#fee2e2','Alpha'],
    [$tI,  '#2563eb','#dbeafe','Izin'],
    [$tS,  '#9333ea','#ede9fe','Sakit'],
    [$tDL, '#0f4c81','#eff6ff','Dinas Luar'],
    [$tPC, '#7c3aed','#f3e8ff','Plg. Cepat'],
] as $s): ?>
<td class="stat-cell" style="border-color:<?= $s[1] ?>;background:<?= $s[2] ?>">
    <span class="stat-num" style="color:<?= $s[1] ?>"><?= $s[0] ?></span>
    <span class="stat-lbl"><?= $s[3] ?></span>
</td>
<?php endforeach; ?>
</tr></table>

<table class="main-table">
<thead>
    <tr class="th-main">
        <td style="width:18px">No</td>
        <td class="left" style="width:46px">NIK</td>
        <td class="left">Nama Karyawan</td>
        <?php if (!$departemen_id): ?><td class="left" style="width:58px">Departemen</td><?php endif; ?>
        <td style="width:26px">Hadir</td>
        <td style="width:26px">Telat</td>
        <td style="width:26px">Plg.</td>
        <td style="width:24px">Alpha</td>
        <td style="width:22px">Izin</td>
        <td style="width:24px">Sakit</td>
        <td style="width:22px" title="Dinas Luar">DL</td>
        <td style="width:50px">&#931; Telat</td>
        <td style="width:50px">Total Jam</td>
        <td style="width:40px">Kehadiran</td>
    </tr>
    <tr class="th-sub">
        <td colspan="<?= $departemen_id ? 3 : 4 ?>" class="left" style="color:#555;font-style:italic;text-align:left;padding-left:6px">
            Hari kerja: <?= $jumlahHariKerja ?> hari
        </td>
        <td colspan="10" style="color:#555;font-style:italic">
            Rekapitulasi <?= htmlspecialchars($period_label) ?><?= $namaDepartemen ? ' — Dept. '.htmlspecialchars($namaDepartemen) : '' ?>
        </td>
    </tr>
</thead>
<tbody>
<?php if(empty($rows)): ?>
<tr><td colspan="<?= $departemen_id?13:14 ?>" class="center" style="padding:14px;color:#888">Tidak ada data</td></tr>
<?php else:
$gH=$gT=$gPC=$gA=$gI=$gS=$gDL=$gTelat=$gPulang=$gDur=0;
$prevDept=null; $no=0;
foreach ($rows as $r):
    if (!$departemen_id && $r['departemen_nama']!==$prevDept):
        $prevDept = $r['departemen_nama'];
?>
<tr class="dept-header">
    <td colspan="14">&#x1F3E2; <?= htmlspecialchars($prevDept ?: 'Tanpa Departemen') ?></td>
</tr>
<?php endif;
    $no++;
    $gH+=$r['hadir']; $gT+=$r['terlambat']; $gPC+=$r['pulang_cepat'];
    $gA+=$r['absen']; $gI+=$r['izin'];       $gS+=$r['sakit'];
    $gDL+=(int)$r['dinas_luar'];
    $gTelat+=$r['total_terlambat_detik'];
    $gPulang+=$r['total_pulang_cepat_detik'];
    $gDur+=$r['total_durasi'];
    $pct   = $jumlahHariKerja>0 ? min(100,round(($r['hadir']/$jumlahHariKerja)*100)) : 0;
    $pctC  = $pct>=90?'#16a34a':($pct>=75?'#d97706':'#dc2626');
?>
<tr>
    <td class="row-no"><?= $no ?></td>
    <td class="mono" style="font-size:7.5pt;color:#666"><?= htmlspecialchars($r['nik']) ?></td>
    <td style="font-weight:700"><?= htmlspecialchars(potong($r['nama'],26)) ?></td>
    <?php if (!$departemen_id): ?>
    <td style="font-size:7pt;color:#555"><?= htmlspecialchars(potong($r['departemen_nama']??'-',16)) ?></td>
    <?php endif; ?>
    <td class="center" style="color:#16a34a;font-weight:700"><?= $r['hadir'] ?></td>
    <td class="center" style="color:<?= $r['terlambat']>0?'#d97706':'#aaa' ?>"><?= $r['terlambat']>0?$r['terlambat']:'-' ?></td>
    <td class="center" style="color:<?= $r['pulang_cepat']>0?'#7c3aed':'#aaa' ?>"><?= $r['pulang_cepat']>0?$r['pulang_cepat']:'-' ?></td>
    <td class="center" style="color:<?= $r['absen']>0?'#dc2626':'#aaa' ?>"><?= $r['absen']>0?$r['absen']:'-' ?></td>
    <td class="center" style="color:<?= $r['izin']>0?'#2563eb':'#aaa' ?>"><?= $r['izin']>0?$r['izin']:'-' ?></td>
    <td class="center" style="color:<?= $r['sakit']>0?'#9333ea':'#aaa' ?>"><?= $r['sakit']>0?$r['sakit']:'-' ?></td>
    <td class="center" style="color:<?= $r['dinas_luar']>0?'#0f4c81':'#aaa' ?>;font-weight:<?= $r['dinas_luar']>0?'700':'400' ?>">
        <?= $r['dinas_luar']>0?$r['dinas_luar']:'-' ?>
    </td>
    <td class="center" style="font-size:7.5pt;color:<?= $r['total_terlambat_detik']>0?'#d97706':'#aaa' ?>"><?= fmtTelat($r['total_terlambat_detik']) ?></td>
    <td class="center" style="font-size:7.5pt"><?= fmtDur($r['total_durasi']) ?></td>
    <td class="center">
        <span style="font-weight:700;color:<?= $pctC ?>;font-size:8pt"><?= $pct ?>%</span>
        <div class="pct-bar-wrap"><div class="pct-bar" style="width:<?= $pct ?>%;background:<?= $pctC ?>"></div></div>
    </td>
</tr>
<?php endforeach;
$totalPct = ($jumlahHariKerja>0 && count($rows)>0)
    ? min(100, round(($gH/($jumlahHariKerja*count($rows)))*100)) : 0;
?>
<tr class="total-row">
    <td colspan="<?= $departemen_id ? 3 : 4 ?>">TOTAL (<?= count($rows) ?> karyawan)</td>
    <td class="center" style="color:#16a34a"><?= $gH ?></td>
    <td class="center" style="color:#d97706"><?= $gT ?></td>
    <td class="center" style="color:#7c3aed"><?= $gPC ?></td>
    <td class="center" style="color:#dc2626"><?= $gA ?></td>
    <td class="center" style="color:#2563eb"><?= $gI ?></td>
    <td class="center" style="color:#9333ea"><?= $gS ?></td>
    <td class="center" style="color:#0f4c81;font-weight:700"><?= $gDL ?></td>
    <td class="center" style="color:#d97706;font-size:7.5pt"><?= fmtTelat($gTelat) ?></td>
    <td class="center" style="font-size:7.5pt"><?= fmtDur($gDur) ?></td>
    <td class="center" style="color:<?= $totalPct>=90?'#16a34a':($totalPct>=75?'#d97706':'#dc2626') ?>;font-weight:700">
        <?= $totalPct ?>%
    </td>
</tr>
<?php endif; ?>
</tbody>
</table>

<!-- Keterangan warna -->
<table style="margin-bottom:14px;border-collapse:collapse"><tr>
    <td style="font-size:7pt;color:#555;padding-right:6px;font-weight:700">Ket:</td>
    <?php foreach([
        ['#16a34a','Hadir'],['#d97706','Terlambat'],['#7c3aed','Plg. Cepat'],
        ['#dc2626','Alpha'],['#2563eb','Izin'],['#9333ea','Sakit'],['#0f4c81','Dinas Luar'],
    ] as $k): ?>
    <td style="padding-right:8px"><span class="badge" style="background:<?= $k[0] ?>"><?= $k[1] ?></span></td>
    <?php endforeach; ?>
    <td style="font-size:7pt;color:#888;padding-left:6px">DL = Dinas Luar &nbsp;|&nbsp; Plg. = Pulang Cepat &nbsp;|&nbsp; Alpha = Tidak Hadir Tanpa Keterangan</td>
</tr></table>

<?php endif; ?>

<!-- ── TTD ── -->
<table class="ttd-table">
<tr>
    <td style="width:55%">
        <div style="font-size:7.5pt;color:#555;line-height:1.8">
            Catatan:<br>
            1. Laporan ini digenerate otomatis dari Sistem DailyFix<br>
            2. Kehadiran dihitung berdasarkan data GPS yang terverifikasi<br>
            3. Status Dinas Luar / Sakit / Izin berdasarkan laporan karyawan<br>
            4. Periode: <strong><?= htmlspecialchars($period_label) ?></strong>
            <?php if ($namaDepartemen): ?><br>5. Departemen: <strong><?= htmlspecialchars($namaDepartemen) ?></strong><?php endif; ?>
        </div>
    </td>
    <td style="text-align:right;vertical-align:bottom">
        <div style="font-size:7.5pt;color:#333;margin-bottom:4px">
            <?= htmlspecialchars($namaPerusahaan) ?>,
            <?= date('d ') . ($mode_filter==='periode' ? date('F Y',strtotime($tgl_sampai)) : $monthsId[$bulan].' '.$tahun) ?>
        </div>
        <div style="font-size:7.5pt;color:#555;margin-bottom:4px">Mengetahui,</div>
        <div class="ttd-space"></div>
        <div class="ttd-line">
            <?= htmlspecialchars($user['nama']) ?>
            <div class="ttd-jabatan">Administrator / HRD</div>
        </div>
    </td>
</tr>
</table>

<div class="page-footer">
    <table class="footer-table"><tr>
        <td><strong>DailyFix</strong> — Sistem Absensi Digital v1.0.0 | Digenerate: <?= date('d/m/Y H:i:s') ?></td>
        <td class="fr"><strong>RAHASIA</strong> — Dokumen internal perusahaan</td>
    </tr></table>
</div>

</body></html>
<?php
$html = ob_get_clean();

$dompdfPaths = [
    __DIR__.'/../dompdf/autoload.inc.php',
    __DIR__.'/../dompdf/vendor/dompdf/dompdf/autoload.inc.php',
    __DIR__.'/../vendor/dompdf/dompdf/autoload.inc.php',
    __DIR__.'/../vendor/autoload.php',
];
$loaded = false;
foreach ($dompdfPaths as $p) {
    if (file_exists($p)) { require_once $p; $loaded = true; break; }
}

if (!$loaded) {
    // Fallback: tampilkan HTML + print dialog
    echo $html;
    echo '<script>window.addEventListener("load",()=>setTimeout(()=>window.print(),600));</script>';
    exit;
}

use Dompdf\Dompdf;
use Dompdf\Options;
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isPhpEnabled', false);
$options->set('defaultFont', 'helvetica');
$options->set('fontDir',   sys_get_temp_dir());
$options->set('fontCache', sys_get_temp_dir());
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream($judulPDF, ['Attachment' => 0]);
exit;