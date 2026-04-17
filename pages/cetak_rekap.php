<?php
require_once __DIR__ . '/../includes/config.php';
requireAdmin();

$user   = currentUser();
$db     = getDB();

$bulan       = (int)($_GET['bulan'] ?? date('m'));
$tahun       = (int)($_GET['tahun'] ?? date('Y'));
$karyawan_id = (int)($_GET['karyawan_id'] ?? 0);
$period      = $tahun . '-' . str_pad($bulan, 2, '0', STR_PAD_LEFT);

$monthsId = ['','Januari','Februari','Maret','April','Mei','Juni',
             'Juli','Agustus','September','Oktober','November','Desember'];
$hariId   = ['Mon'=>'Senin','Tue'=>'Selasa','Wed'=>'Rabu','Thu'=>'Kamis',
             'Fri'=>'Jumat','Sat'=>'Sabtu','Sun'=>'Minggu'];
$statusLabel = ['hadir'=>'Hadir','terlambat'=>'Terlambat','absen'=>'Alpha','izin'=>'Izin','sakit'=>'Sakit','libur'=>'Libur'];

// Info perusahaan
$stmtP = $db->prepare("SELECT * FROM perusahaan WHERE id=? LIMIT 1");
$stmtP->execute([$user['perusahaan_id']]);
$perusahaan = $stmtP->fetch();
$namaPerusahaan = $perusahaan['nama']    ?? 'PT. DailyFix Indonesia';
$alamat         = $perusahaan['alamat']  ?? '';
$telepon        = $perusahaan['telepon'] ?? '';
$emailP         = $perusahaan['email']   ?? '';

// Helper
function fmtTelat($d) {
    if (!$d) return '-';
    $j=floor($d/3600); $m=floor(($d%3600)/60); $s=$d%60;
    $o=[];
    if($j) $o[]=$j.'j'; if($m) $o[]=$m.'m'; if($s) $o[]=$s.'d';
    return implode(' ',$o) ?: '-';
}
function fmtDur($mnt) {
    if (!$mnt) return '-';
    $j=floor($mnt/60); $m=$mnt%60;
    return ($j?$j.'j ':'').$m.'m';
}

$jumlahHariKerja = 0;
$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $bulan, $tahun);
for ($d=1; $d<=$daysInMonth; $d++) {
    if (date('N', mktime(0,0,0,$bulan,$d,$tahun)) <= 5) $jumlahHariKerja++;
}

// ── Mode detail 1 karyawan ──
if ($karyawan_id) {
    $stmtK = $db->prepare("SELECT k.*, j.nama as jabatan_nama, dep.nama as departemen_nama
        FROM karyawan k
        LEFT JOIN jabatan j   ON j.id=k.jabatan_id
        LEFT JOIN departemen dep ON dep.id=k.departemen_id
        WHERE k.id=? AND k.perusahaan_id=?");
    $stmtK->execute([$karyawan_id, $user['perusahaan_id']]);
    $karyawan = $stmtK->fetch();
    if (!$karyawan) die('Karyawan tidak ditemukan.');

    $stmtD = $db->prepare("SELECT a.*, s.nama as shift_nama, s.jam_masuk, s.jam_keluar,
        l.nama as lokasi_nama
        FROM absensi a
        LEFT JOIN shift s ON s.id=a.shift_id
        LEFT JOIN lokasi l ON l.id=a.lokasi_id
        WHERE a.karyawan_id=? AND DATE_FORMAT(a.tanggal,'%Y-%m')=?
        ORDER BY a.tanggal ASC");
    $stmtD->execute([$karyawan_id, $period]);
    $rows = $stmtD->fetchAll();

    $stat = ['hadir'=>0,'terlambat'=>0,'pulang_cepat'=>0,'absen'=>0,'izin'=>0,'sakit'=>0];
    $totalTelat=$totalPulang=$totalMenit=0;
    foreach ($rows as $r) {
        $s = $r['status_kehadiran'];
        if (in_array($s,['hadir','terlambat'])) $stat['hadir']++;
        if ($s==='terlambat') $stat['terlambat']++;
        if ($s==='absen')     $stat['absen']++;
        if ($s==='izin')      $stat['izin']++;
        if ($s==='sakit')     $stat['sakit']++;
        $pc = (int)($r['pulang_cepat_detik'] ?? 0);
        if ($pc > 0) $stat['pulang_cepat']++;
        $totalTelat  += (int)($r['terlambat_detik'] ?? 0);
        $totalPulang += $pc;
        $totalMenit  += (int)($r['durasi_kerja'] ?? 0);
    }
    $mode     = 'detail';
    $judulPDF = "Rekap_{$karyawan['nama']}_{$monthsId[$bulan]}_{$tahun}.pdf";

// ── Mode semua karyawan ──
} else {
    $karyawan = null;
    $stmtAll = $db->prepare("SELECT k.id, k.nik, k.nama,
        SUM(CASE WHEN a.status_kehadiran IN ('hadir','terlambat') THEN 1 ELSE 0 END) as hadir,
        SUM(CASE WHEN a.status_kehadiran = 'terlambat' THEN 1 ELSE 0 END) as terlambat,
        SUM(CASE WHEN IFNULL(a.pulang_cepat_detik,0) > 0 THEN 1 ELSE 0 END) as pulang_cepat,
        SUM(CASE WHEN a.status_kehadiran = 'absen'     THEN 1 ELSE 0 END) as absen,
        SUM(CASE WHEN a.status_kehadiran = 'izin'      THEN 1 ELSE 0 END) as izin,
        SUM(CASE WHEN a.status_kehadiran = 'sakit'     THEN 1 ELSE 0 END) as sakit,
        SUM(COALESCE(a.terlambat_detik,0))     as total_terlambat_detik,
        SUM(COALESCE(a.pulang_cepat_detik,0))  as total_pulang_cepat_detik,
        SUM(COALESCE(a.durasi_kerja,0))         as total_durasi
        FROM karyawan k
        LEFT JOIN absensi a ON a.karyawan_id=k.id AND DATE_FORMAT(a.tanggal,'%Y-%m')=?
        WHERE k.perusahaan_id=? AND k.role='karyawan'
        GROUP BY k.id ORDER BY k.nama");
    $stmtAll->execute([$period, $user['perusahaan_id']]);
    $rows     = $stmtAll->fetchAll();
    $mode     = 'all';
    $judulPDF = "Rekap_Karyawan_{$monthsId[$bulan]}_{$tahun}.pdf";
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
.kop-table { width:100%; border-collapse:collapse; margin-bottom:0; }
.kop-logo-cell { width:52px; vertical-align:middle; padding-right:10px; }
.logo-box { width:44px; height:44px; background:#0f4c81; border-radius:6px; text-align:center; line-height:44px; font-size:20pt; font-weight:900; color:#fff; }
.kop-text-cell { vertical-align:middle; }
.kop-company { font-size:13pt; font-weight:900; color:#0f4c81; }
.kop-sub { font-size:7.5pt; color:#555; margin-top:2px; }
.kop-right-cell { vertical-align:top; text-align:right; font-size:7.5pt; color:#555; }
.kop-divider { border:none; border-top:3px solid #0f4c81; margin:8px 0 0 0; }
.kop-divider-thin { border:none; border-top:1px solid #b0c4de; margin:2px 0 10px 0; }
.judul-wrap { text-align:center; margin:12px 0 14px; }
.judul-wrap .label-laporan { font-size:7.5pt; font-weight:700; color:#0f4c81; letter-spacing:2px; text-transform:uppercase; border:1.5px solid #0f4c81; display:inline-block; padding:2px 12px; border-radius:2px; margin-bottom:5px; }
.judul-wrap h2 { font-size:13pt; font-weight:900; color:#1a1a2e; text-transform:uppercase; }
.judul-wrap .periode { font-size:8.5pt; color:#444; margin-top:3px; }
.info-table { width:100%; border-collapse:collapse; margin-bottom:14px; }
.info-table td { padding:4px 8px; font-size:8pt; border:1px solid #dce5f0; }
.info-table td.lbl { background:#eef3fa; color:#0f4c81; font-weight:700; width:22%; }
.info-table td.val { color:#222; width:28%; }
.stat-table { width:100%; border-collapse:separate; border-spacing:5px; margin-bottom:14px; }
.stat-cell { text-align:center; border-radius:5px; padding:8px 4px; border:1.5px solid #e2e8f0; background:#f8fafc; }
.stat-num { font-size:16pt; font-weight:900; display:block; line-height:1.1; }
.stat-lbl { font-size:6.5pt; font-weight:700; text-transform:uppercase; letter-spacing:.5px; display:block; margin-top:3px; color:#64748b; }
.main-table { width:100%; border-collapse:collapse; font-size:8pt; margin-bottom:16px; }
.main-table thead tr.th-main td { background:#0f4c81; color:#fff; font-weight:700; padding:6px 5px; text-align:center; border:1px solid #0a3260; font-size:7pt; text-transform:uppercase; }
.main-table thead tr.th-main td.left { text-align:left; }
.main-table thead tr.th-sub td { background:#d6e4f7; color:#0f4c81; font-size:7pt; font-weight:700; text-align:center; padding:4px; border:1px solid #b8d0eb; }
.main-table tbody td { padding:5px; border:1px solid #dce5f0; vertical-align:middle; font-size:7.5pt; }
.main-table tbody tr:nth-child(even) td { background:#f7faff; }
.main-table tbody tr.total-row td { background:#eef3fa; font-weight:700; border-top:2px solid #0f4c81; }
.center { text-align:center; }
.right  { text-align:right; }
.mono   { font-family:Courier,monospace; }
.badge  { display:inline-block; border-radius:2px; padding:1.5px 5px; font-size:7pt; font-weight:700; color:#fff; }
.b-hadir     { background:#16a34a; }
.b-terlambat { background:#d97706; }
.b-absen     { background:#dc2626; }
.b-izin      { background:#2563eb; }
.b-sakit     { background:#7c3aed; }
.b-libur     { background:#6b7280; }
.row-no { color:#999; font-size:7pt; text-align:center; }
.ttd-table { width:100%; border-collapse:collapse; }
.ttd-table td { vertical-align:top; padding:0; font-size:8pt; }
.ttd-space { height:52px; }
.ttd-line { border-top:1px solid #1a1a2e; display:inline-block; min-width:160px; padding-top:3px; font-weight:700; font-size:8.5pt; }
.ttd-jabatan { font-size:7.5pt; color:#555; }
.page-footer { margin-top:14px; border-top:2px solid #0f4c81; padding-top:5px; }
.footer-table { width:100%; border-collapse:collapse; }
.footer-table td { font-size:7pt; color:#888; }
.footer-table td.fr { text-align:right; }
</style>
</head>
<body>

<!-- KOP -->
<table class="kop-table">
<tr>
    <td class="kop-logo-cell"><div class="logo-box">D</div></td>
    <td class="kop-text-cell">
        <div class="kop-company"><?= htmlspecialchars($namaPerusahaan) ?></div>
        <div class="kop-sub"><?= htmlspecialchars($alamat) ?></div>
        <div class="kop-sub"><?= $telepon?'Telp: '.htmlspecialchars($telepon):'' ?><?= ($telepon&&$emailP)?' | ':'' ?><?= $emailP?'Email: '.htmlspecialchars($emailP):'' ?></div>
    </td>
    <td class="kop-right-cell">
        <span style="font-size:8pt;font-weight:700;color:#0f4c81">LAPORAN ABSENSI</span><br>
        Dicetak: <?= date('d/m/Y H:i') ?><br>
        Oleh: <?= htmlspecialchars($user['nama']) ?>
    </td>
</tr>
</table>
<hr class="kop-divider"><hr class="kop-divider-thin">

<!-- JUDUL -->
<div class="judul-wrap">
    <div class="label-laporan">Laporan Kehadiran Karyawan</div><br>
    <h2><?= $mode==='detail' ? 'REKAP ABSENSI INDIVIDU' : 'REKAP ABSENSI SELURUH KARYAWAN' ?></h2>
    <div class="periode">Periode: <strong><?= $monthsId[$bulan].' '.$tahun ?></strong> &mdash; Hari Kerja (Senin–Jumat): <strong><?= $jumlahHariKerja ?> hari</strong></div>
</div>

<?php if ($mode === 'detail'): ?>
<!-- ═══ DETAIL 1 KARYAWAN ═══ -->
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

<!-- Statistik -->
<table class="stat-table"><tr>
<?php
$stats = [
    [$stat['hadir'],        '#16a34a','#dcfce7','#16a34a','Hari Hadir'],
    [$stat['terlambat'],    '#d97706','#fef3c7','#d97706','Terlambat'],
    [$stat['pulang_cepat'], '#7c3aed','#f3e8ff','#7c3aed','Pulang Cepat'],
    [$stat['absen'],        '#dc2626','#fee2e2','#dc2626','Alpha'],
    [$stat['izin'],         '#2563eb','#dbeafe','#2563eb','Izin'],
    [$stat['sakit'],        '#7c3aed','#ede9fe','#7c3aed','Sakit'],
    [fmtTelat($totalTelat), '#d97706','#fffbeb','#d97706','Total Telat'],
    [fmtTelat($totalPulang),'#7c3aed','#f3e8ff','#7c3aed','Total Plg Cepat'],
    [fmtDur($totalMenit),   '#0d9488','#d1fae5','#0d9488','Total Jam'],
];
foreach ($stats as $s): ?>
<td class="stat-cell" style="border-color:<?= $s[1] ?>;background:<?= $s[2] ?>">
    <span class="stat-num" style="color:<?= $s[3] ?>"><?= $s[0]!==null&&$s[0]!==''?$s[0]:'0' ?></span>
    <span class="stat-lbl"><?= $s[4] ?></span>
</td>
<?php endforeach; ?>
</tr></table>

<!-- Tabel Detail -->
<table class="main-table">
<thead>
    <tr class="th-main">
        <td style="width:20px">No</td>
        <td class="left" style="width:54px">Tanggal</td>
        <td style="width:38px">Hari</td>
        <td class="left">Shift</td>
        <td style="width:50px">Masuk</td>
        <td style="width:50px">Keluar</td>
        <td style="width:56px">Status</td>
        <td style="width:48px">Terlambat</td>
        <td style="width:52px">Plg. Cepat</td>
        <td style="width:34px">Durasi</td>
        <td style="width:26px">Jarak</td>
    </tr>
</thead>
<tbody>
<?php if(empty($rows)): ?>
<tr><td colspan="11" class="center" style="padding:14px;color:#888">Tidak ada data</td></tr>
<?php else: foreach($rows as $i=>$r):
    $hari    = $hariId[date('D',strtotime($r['tanggal']))] ?? date('D',strtotime($r['tanggal']));
    $st      = $r['status_kehadiran'];
    $telat   = (int)($r['terlambat_detik']   ?? 0);
    $pulang  = (int)($r['pulang_cepat_detik'] ?? 0);
    $isWE    = in_array(date('N',strtotime($r['tanggal'])),[6,7]);
    $rowBg   = $isWE ? 'background:#f0f4ff;' : '';
?>
<tr>
    <td class="row-no" style="<?= $rowBg ?>"><?= $i+1 ?></td>
    <td class="mono center" style="<?= $rowBg ?>;font-size:7.5pt"><?= date('d/m/Y',strtotime($r['tanggal'])) ?></td>
    <td class="center" style="<?= $rowBg ?>;font-size:7.5pt;color:<?= $isWE?'#2563eb':'inherit' ?>"><?= $hari ?></td>
    <td style="<?= $rowBg ?>;font-size:7.5pt"><?= htmlspecialchars($r['shift_nama']??'-') ?></td>
    <td class="mono center" style="<?= $rowBg ?>"><?= $r['waktu_masuk']?date('H:i:s',strtotime($r['waktu_masuk'])):'-' ?></td>
    <td class="mono center" style="<?= $rowBg ?>"><?= $r['waktu_keluar']?date('H:i:s',strtotime($r['waktu_keluar'])):'-' ?></td>
    <td class="center" style="<?= $rowBg ?>"><span class="badge b-<?= $st ?>"><?= $statusLabel[$st]??ucfirst($st) ?></span></td>
    <td class="center" style="<?= $rowBg ?>;color:<?= $telat>0?'#d97706':'#ccc' ?>;font-size:7.5pt"><?= fmtTelat($telat) ?></td>
    <td class="center" style="<?= $rowBg ?>;color:<?= $pulang>0?'#7c3aed':'#ccc' ?>;font-size:7.5pt"><?= fmtTelat($pulang) ?></td>
    <td class="center" style="<?= $rowBg ?>;font-size:7.5pt"><?= fmtDur($r['durasi_kerja']) ?></td>
    <td class="center" style="<?= $rowBg ?>;font-size:7pt;color:#888"><?= $r['jarak_masuk']?$r['jarak_masuk'].'m':'-' ?></td>
</tr>
<?php endforeach; endif; ?>
</tbody>
<tfoot>
    <tr class="total-row">
        <td colspan="7" class="right" style="font-size:7.5pt">TOTAL <?= strtoupper($monthsId[$bulan]) ?>:</td>
        <td class="center" style="color:#d97706"><?= fmtTelat($totalTelat) ?></td>
        <td class="center" style="color:#7c3aed"><?= fmtTelat($totalPulang) ?></td>
        <td class="center" style="color:#0d9488"><?= fmtDur($totalMenit) ?></td>
        <td></td>
    </tr>
</tfoot>
</table>

<?php else: ?>
<!-- ═══ SEMUA KARYAWAN ═══ -->
<table class="main-table">
<thead>
    <tr class="th-main">
        <td style="width:20px">No</td>
        <td class="left" style="width:50px">NIK</td>
        <td class="left">Nama Karyawan</td>
        <td style="width:30px">Hadir</td>
        <td style="width:34px">Telat</td>
        <td style="width:34px">Plg. Cpt</td>
        <td style="width:28px">Alpha</td>
        <td style="width:24px">Izin</td>
        <td style="width:28px">Sakit</td>
        <td style="width:60px">Total Terlambat</td>
        <td style="width:60px">Total Plg. Cepat</td>
        <td style="width:40px">Total Jam</td>
        <td style="width:42px">Kehadiran</td>
    </tr>
    <tr class="th-sub">
        <td colspan="3" class="left" style="color:#555;font-style:italic">Hari kerja: <?= $jumlahHariKerja ?> hari</td>
        <td colspan="10" style="color:#555;font-style:italic">Rekapitulasi <?= $monthsId[$bulan].' '.$tahun ?></td>
    </tr>
</thead>
<tbody>
<?php if(empty($rows)): ?>
<tr><td colspan="13" class="center" style="padding:14px;color:#888">Tidak ada data</td></tr>
<?php else:
$tH=$tT=$tPC=$tA=$tI=$tS=$tTelat=$tPulang=$tDur=0;
foreach($rows as $i=>$r):
    $tH+=$r['hadir']; $tT+=$r['terlambat']; $tPC+=$r['pulang_cepat'];
    $tA+=$r['absen']; $tI+=$r['izin']; $tS+=$r['sakit'];
    $tTelat+=$r['total_terlambat_detik']; $tPulang+=$r['total_pulang_cepat_detik']; $tDur+=$r['total_durasi'];
    $pct = $jumlahHariKerja>0?min(100,round(($r['hadir']/$jumlahHariKerja)*100)):0;
    $pctC = $pct>=90?'#16a34a':($pct>=75?'#d97706':'#dc2626');
?>
<tr>
    <td class="row-no"><?= $i+1 ?></td>
    <td class="mono" style="font-size:7.5pt;color:#666"><?= htmlspecialchars($r['nik']) ?></td>
    <td style="font-weight:700"><?= htmlspecialchars($r['nama']) ?></td>
    <td class="center" style="color:#16a34a;font-weight:700"><?= $r['hadir'] ?></td>
    <td class="center" style="color:<?= $r['terlambat']>0?'#d97706':'#aaa' ?>;font-weight:<?= $r['terlambat']>0?'700':'400' ?>"><?= $r['terlambat']>0?$r['terlambat']:'-' ?></td>
    <td class="center" style="color:<?= $r['pulang_cepat']>0?'#7c3aed':'#aaa' ?>"><?= $r['pulang_cepat']>0?$r['pulang_cepat']:'-' ?></td>
    <td class="center" style="color:<?= $r['absen']>0?'#dc2626':'#aaa' ?>"><?= $r['absen']>0?$r['absen']:'-' ?></td>
    <td class="center" style="color:<?= $r['izin']>0?'#2563eb':'#aaa' ?>"><?= $r['izin']>0?$r['izin']:'-' ?></td>
    <td class="center" style="color:<?= $r['sakit']>0?'#7c3aed':'#aaa' ?>"><?= $r['sakit']>0?$r['sakit']:'-' ?></td>
    <td class="center" style="font-size:7.5pt;color:<?= $r['total_terlambat_detik']>0?'#d97706':'#aaa' ?>"><?= fmtTelat($r['total_terlambat_detik']) ?></td>
    <td class="center" style="font-size:7.5pt;color:<?= $r['total_pulang_cepat_detik']>0?'#7c3aed':'#aaa' ?>"><?= fmtTelat($r['total_pulang_cepat_detik']) ?></td>
    <td class="center" style="font-size:7.5pt"><?= fmtDur($r['total_durasi']) ?></td>
    <td class="center"><span style="font-weight:700;color:<?= $pctC ?>"><?= $pct ?>%</span></td>
</tr>
<?php endforeach;
$totalPct = $jumlahHariKerja>0&&count($rows)>0?min(100,round(($tH/($jumlahHariKerja*count($rows)))*100)):0;
?>
<tr class="total-row">
    <td colspan="3">TOTAL / RATA-RATA</td>
    <td class="center" style="color:#16a34a"><?= $tH ?></td>
    <td class="center" style="color:#d97706"><?= $tT ?></td>
    <td class="center" style="color:#7c3aed"><?= $tPC ?></td>
    <td class="center" style="color:#dc2626"><?= $tA ?></td>
    <td class="center" style="color:#2563eb"><?= $tI ?></td>
    <td class="center" style="color:#7c3aed"><?= $tS ?></td>
    <td class="center" style="color:#d97706;font-size:7.5pt"><?= fmtTelat($tTelat) ?></td>
    <td class="center" style="color:#7c3aed;font-size:7.5pt"><?= fmtTelat($tPulang) ?></td>
    <td class="center" style="font-size:7.5pt"><?= fmtDur($tDur) ?></td>
    <td class="center" style="color:<?= $totalPct>=90?'#16a34a':($totalPct>=75?'#d97706':'#dc2626') ?>"><?= $totalPct ?>%</td>
</tr>
<?php endif; ?>
</tbody>
</table>

<!-- Keterangan -->
<table style="margin-bottom:14px;border-collapse:collapse"><tr>
    <td style="font-size:7pt;color:#555;padding-right:6px;font-weight:700">Keterangan:</td>
    <?php foreach([['#16a34a','Hadir'],['#d97706','Terlambat'],['#7c3aed','Plg. Cepat'],['#dc2626','Alpha'],['#2563eb','Izin'],['#7c3aed','Sakit']] as $k): ?>
    <td style="padding-right:8px"><span class="badge" style="background:<?= $k[0] ?>"><?= $k[1] ?></span></td>
    <?php endforeach; ?>
</tr></table>
<?php endif; ?>

<!-- TTD -->
<table class="ttd-table">
<tr>
    <td style="width:55%">
        <div style="font-size:7.5pt;color:#555;line-height:1.7">
            Catatan:<br>
            1. Laporan ini digenerate otomatis dari Sistem DailyFix<br>
            2. Kehadiran dihitung berdasarkan data GPS yang terverifikasi<br>
            3. Pulang cepat dihitung dari jam keluar shift setelah toleransi
        </div>
    </td>
    <td style="text-align:right;vertical-align:bottom">
        <div style="font-size:7.5pt;color:#333;margin-bottom:4px"><?= htmlspecialchars($namaPerusahaan) ?>, <?= date('d ').$monthsId[$bulan].' '.$tahun ?></div>
        <div style="font-size:7.5pt;color:#555;margin-bottom:4px">Mengetahui,</div>
        <div class="ttd-space"></div>
        <div class="ttd-line"><?= htmlspecialchars($user['nama']) ?><div class="ttd-jabatan">Administrator / HRD</div></div>
    </td>
</tr>
</table>

<!-- FOOTER -->
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
foreach ($dompdfPaths as $p) { if(file_exists($p)){ require_once $p; $loaded=true; break; } }

if (!$loaded) {
    echo $html;
    echo '<script>window.addEventListener("load",()=>setTimeout(()=>window.print(),500));</script>';
    exit;
}

use Dompdf\Dompdf; use Dompdf\Options;
$options = new Options();
$options->set('isHtml5ParserEnabled',true); $options->set('isPhpEnabled',false);
$options->set('defaultFont','helvetica');
$options->set('fontDir',sys_get_temp_dir()); $options->set('fontCache',sys_get_temp_dir());
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html,'UTF-8');
$dompdf->setPaper('A4','portrait');
$dompdf->render();
$dompdf->stream($judulPDF,['Attachment'=>0]);
exit;