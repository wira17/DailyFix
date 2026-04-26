<?php
require_once __DIR__ . '/../includes/config.php';
requireLogin();

$pageTitle  = 'Rekap Absensi';
$activePage = 'rekap';
$user       = currentUser();
$db         = getDB();

$bulan  = $_GET['bulan'] ?? date('m');
$tahun  = $_GET['tahun'] ?? date('Y');
$period = $tahun . '-' . str_pad($bulan, 2, '0', STR_PAD_LEFT);

$stmt = $db->prepare("SELECT a.*, s.nama as shift_nama, s.jam_masuk, s.jam_keluar,
        l.nama as lokasi_nama
    FROM absensi a
    LEFT JOIN shift s ON s.id = a.shift_id
    LEFT JOIN lokasi l ON l.id = a.lokasi_id
    WHERE a.karyawan_id = ? AND DATE_FORMAT(a.tanggal,'%Y-%m') = ?
    ORDER BY a.tanggal DESC");
$stmt->execute([$user['id'], $period]);
$absensis = $stmt->fetchAll();

// Summary
$summary = [
    'hadir'                    => 0,
    'terlambat'                => 0,
    'pulang_cepat'             => 0,
    'absen'                    => 0,
    'izin'                     => 0,
    'sakit'                    => 0,
    'dinas_luar'               => 0,
    'total_terlambat_detik'    => 0,
    'total_pulang_cepat_detik' => 0,
    'total_durasi'             => 0,
];
foreach ($absensis as $a) {
    if (in_array($a['status_kehadiran'], ['hadir','terlambat'])) $summary['hadir']++;
    if ($a['status_kehadiran'] === 'terlambat')  $summary['terlambat']++;
    if ($a['status_kehadiran'] === 'absen')      $summary['absen']++;
    if ($a['status_kehadiran'] === 'izin')       $summary['izin']++;
    if ($a['status_kehadiran'] === 'sakit')      $summary['sakit']++;
    if ($a['status_kehadiran'] === 'dinas_luar') $summary['dinas_luar']++;
    $pc = (int)($a['pulang_cepat_detik'] ?? 0);
    if ($pc > 0) $summary['pulang_cepat']++;
    $summary['total_terlambat_detik']    += (int)($a['terlambat_detik'] ?? 0);
    $summary['total_pulang_cepat_detik'] += $pc;
    $summary['total_durasi']             += (int)($a['durasi_kerja'] ?? 0);
}

$months = [];
for ($m = 1; $m <= 12; $m++) $months[$m] = date('F', mktime(0,0,0,$m,1));

$namaBulan = [
    1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',5=>'Mei',6=>'Juni',
    7=>'Juli',8=>'Agustus',9=>'September',10=>'Oktober',11=>'November',12=>'Desember'
];
$namaHariEn = ['Mon'=>'Sen','Tue'=>'Sel','Wed'=>'Rab','Thu'=>'Kam','Fri'=>'Jum','Sat'=>'Sab','Sun'=>'Min'];

include __DIR__ . '/../includes/header.php';
?>

<style>
/* ─── Scoped rekap page ─── */
.rek-page { max-width: 720px; margin: 0 auto; padding-bottom: 20px; }

/* ─── Filter bar ─── */
.rek-filter {
    background: #fff; border-radius: 14px;
    padding: 14px 16px; margin-bottom: 16px;
    box-shadow: 0 2px 12px rgba(15,76,129,0.08);
}
.rek-filter form { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; }
.rek-filter-group { display: flex; flex-direction: column; gap: 4px; flex: 1; min-width: 110px; }
.rek-filter-label { font-size: 11px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: .5px; }
.rek-select {
    padding: 9px 12px; border: 1.5px solid #e2e8f0; border-radius: 9px;
    font-size: 13.5px; font-family: inherit; color: #0f172a;
    background: #f8fafc; outline: none; cursor: pointer;
    transition: border-color .15s;
    -webkit-appearance: none; appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2364748b' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 10px center;
    padding-right: 28px;
}
.rek-select:focus { border-color: #0f4c81; background-color: #fff; }
.rek-filter-btns { display: flex; gap: 8px; }
.rek-btn-filter {
    padding: 9px 16px; background: linear-gradient(135deg,#0f4c81,#1a6bb5);
    color: #fff; border: none; border-radius: 9px;
    font-size: 13px; font-weight: 800; cursor: pointer;
    font-family: inherit; display: flex; align-items: center; gap: 6px;
    white-space: nowrap; transition: transform .15s;
}
.rek-btn-filter:active { transform: scale(0.97); }
.rek-btn-export {
    padding: 9px 14px; background: #fff; color: #0f4c81;
    border: 1.5px solid #0f4c81; border-radius: 9px;
    font-size: 13px; font-weight: 800; cursor: pointer;
    font-family: inherit; display: flex; align-items: center; gap: 6px;
    white-space: nowrap; text-decoration: none; transition: transform .15s;
}
.rek-btn-export:active { transform: scale(0.97); }

/* ─── Period title ─── */
.rek-period-title {
    font-size: 15px; font-weight: 800; color: #0f172a;
    margin-bottom: 12px; display: flex; align-items: center; gap: 8px;
}

/* ─── Stats grid ─── */
.rek-stats { display: grid; grid-template-columns: repeat(3,1fr); gap: 9px; margin-bottom: 14px; }
@media(max-width: 400px) { .rek-stats { grid-template-columns: repeat(2,1fr); } }
.rek-stat {
    background: #fff; border-radius: 12px; padding: 13px 12px;
    box-shadow: 0 2px 10px rgba(15,76,129,0.07);
    display: flex; flex-direction: column; align-items: center; gap: 4px; text-align: center;
}
.rek-stat-icon { width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 16px; margin-bottom: 2px; }
.rek-stat-val   { font-size: 22px; font-weight: 900; color: #0f172a; line-height: 1; }
.rek-stat-label { font-size: 10.5px; color: #64748b; font-weight: 700; }

/* ─── Alert banners ─── */
.rek-alert { border-radius: 10px; padding: 11px 14px; font-size: 13px; font-weight: 700; display: flex; align-items: center; gap: 9px; margin-bottom: 10px; }
.rek-alert-warn   { background: #fffbeb; color: #78350f; border-left: 3px solid #f59e0b; }
.rek-alert-purple { background: #faf5ff; color: #5b21b6; border-left: 3px solid #8b5cf6; }

/* ─── Section header ─── */
.rek-section-head {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 10px;
}
.rek-section-title { font-size: 12px; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: .7px; }
.rek-count-badge { background: #f1f5f9; color: #64748b; font-size: 11px; font-weight: 800; padding: 3px 10px; border-radius: 20px; }

/* ─── Desktop: tabel ─── */
.rek-table-wrap { overflow-x: auto; border-radius: 14px; box-shadow: 0 2px 12px rgba(15,76,129,0.08); }
.rek-table { width: 100%; border-collapse: collapse; background: #fff; min-width: 700px; }
.rek-table thead tr { background: #f8fafc; }
.rek-table th { padding: 11px 12px; font-size: 11.5px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: .5px; text-align: left; border-bottom: 1px solid #f1f5f9; white-space: nowrap; }
.rek-table td { padding: 11px 12px; font-size: 13px; color: #0f172a; border-bottom: 1px solid #f8fafc; vertical-align: middle; }
.rek-table tbody tr:last-child td { border-bottom: none; }
.rek-table tbody tr:hover td { background: #fafbff; }
.rek-table tfoot tr td { background: #f0f4f8; font-weight: 800; font-size: 13px; padding: 11px 12px; }
.rek-mono { font-family: 'JetBrains Mono', 'Fira Mono', monospace; }
.rek-sub  { font-size: 11px; color: #94a3b8; margin-top: 1px; }

/* ─── Mobile: card list ─── */
.rek-card-list { display: none; }
.rek-absen-card {
    background: #fff; border-radius: 14px;
    box-shadow: 0 2px 10px rgba(15,76,129,0.07);
    margin-bottom: 10px; overflow: hidden;
    border-left: 4px solid #e2e8f0;
    transition: transform .15s;
}
.rek-absen-card:active { transform: scale(0.99); }
.rek-absen-card.status-hadir      { border-left-color: #10b981; }
.rek-absen-card.status-terlambat  { border-left-color: #f59e0b; }
.rek-absen-card.status-absen      { border-left-color: #ef4444; }
.rek-absen-card.status-izin       { border-left-color: #8b5cf6; }
.rek-absen-card.status-sakit      { border-left-color: #f43f5e; }
.rek-absen-card.status-dinas_luar { border-left-color: #0f4c81; }

.rek-card-header {
    padding: 12px 14px 10px;
    display: flex; align-items: center; justify-content: space-between;
    border-bottom: 1px solid #f8fafc;
}
.rek-card-date-block { display: flex; align-items: center; gap: 10px; }
.rek-card-date-box {
    background: #f0f4f8; border-radius: 9px; padding: 6px 10px;
    text-align: center; min-width: 48px;
}
.rek-card-day-name { font-size: 10px; color: #64748b; font-weight: 800; text-transform: uppercase; }
.rek-card-day-num  { font-size: 20px; font-weight: 900; color: #0f172a; line-height: 1.1; }
.rek-card-month    { font-size: 10px; color: #64748b; font-weight: 700; }
.rek-card-title    { font-size: 13.5px; font-weight: 800; color: #0f172a; }
.rek-card-shift    { font-size: 12px; color: #64748b; font-weight: 600; margin-top: 1px; }

.rek-card-body { padding: 10px 14px; }
.rek-card-times { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px; margin-bottom: 10px; }
.rek-time-box { text-align: center; }
.rek-time-label { font-size: 10px; color: #94a3b8; font-weight: 800; text-transform: uppercase; letter-spacing: .4px; margin-bottom: 2px; }
.rek-time-val { font-size: 15px; font-weight: 900; color: #0f172a; font-variant-numeric: tabular-nums; }
.rek-time-val.dim { color: #cbd5e1; }
.rek-time-val.ok  { color: #10b981; }
.rek-time-val.warn{ color: #f59e0b; }
.rek-time-sched   { font-size: 10px; color: #94a3b8; font-weight: 600; margin-top: 1px; }

.rek-card-footer { display: flex; flex-wrap: wrap; gap: 6px; padding: 0 14px 12px; align-items: center; }
.rek-pill {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 4px 10px; border-radius: 20px; font-size: 11.5px; font-weight: 800;
}
.rek-pill-late   { background: #fffbeb; color: #78350f; }
.rek-pill-early  { background: #faf5ff; color: #5b21b6; }
.rek-pill-durasi { background: #f0fdf4; color: #065f46; }
.rek-pill-lokasi { background: #f0f9ff; color: #0369a1; }

/* ─── Empty state ─── */
.rek-empty { background: #fff; border-radius: 14px; padding: 40px 20px; text-align: center; box-shadow: 0 2px 10px rgba(15,76,129,0.07); }
.rek-empty-icon { font-size: 40px; margin-bottom: 12px; color: #e2e8f0; }
.rek-empty-title { font-size: 15px; font-weight: 800; color: #94a3b8; margin-bottom: 4px; }
.rek-empty-sub   { font-size: 13px; color: #c8d3de; font-weight: 600; }

/* ─── Footer totals (mobile) ─── */
.rek-totals-card {
    background: #fff; border-radius: 14px; padding: 14px 16px;
    box-shadow: 0 2px 10px rgba(15,76,129,0.07); margin-top: 4px;
}
.rek-totals-title { font-size: 11px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: .6px; margin-bottom: 10px; }
.rek-totals-row { display: flex; justify-content: space-between; align-items: center; padding: 7px 0; border-bottom: 1px solid #f8fafc; font-size: 13px; }
.rek-totals-row:last-child { border-bottom: none; }
.rek-totals-key { color: #64748b; font-weight: 600; }
.rek-totals-val { font-weight: 800; color: #0f172a; }

/* ─── Responsive switch ─── */
@media (max-width: 640px) {
    .rek-table-wrap { display: none; }
    .rek-card-list  { display: block; }
}
</style>

<div class="rek-page">

    <!-- ══ FILTER ══ -->
    <div class="rek-filter">
        <form method="GET" class="">
            <div class="rek-filter-group">
                <label class="rek-filter-label">Bulan</label>
                <select name="bulan" class="rek-select">
                    <?php foreach($months as $m=>$n): ?>
                    <option value="<?= $m ?>" <?= $m==$bulan?'selected':'' ?>><?= $n ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="rek-filter-group">
                <label class="rek-filter-label">Tahun</label>
                <select name="tahun" class="rek-select">
                    <?php for($y=date('Y');$y>=2024;$y--): ?>
                    <option value="<?= $y ?>" <?= $y==$tahun?'selected':'' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="rek-filter-btns">
                <button type="submit" class="rek-btn-filter">
                    <i class="fas fa-filter"></i> Filter
                </button>
                <a href="../api/export_rekap.php?bulan=<?= $bulan ?>&tahun=<?= $tahun ?>" class="rek-btn-export">
                    <i class="fas fa-download"></i> CSV
                </a>
            </div>
        </form>
    </div>

    <!-- ══ STATISTIK ══ -->
    <div class="rek-stats">
        <div class="rek-stat">
            <div class="rek-stat-icon" style="background:#ecfdf5"><i class="fas fa-calendar-check" style="color:#10b981"></i></div>
            <div class="rek-stat-val"><?= $summary['hadir'] ?></div>
            <div class="rek-stat-label">Hadir</div>
        </div>
        <div class="rek-stat">
            <div class="rek-stat-icon" style="background:#fffbeb"><i class="fas fa-clock" style="color:#f59e0b"></i></div>
            <div class="rek-stat-val"><?= $summary['terlambat'] ?></div>
            <div class="rek-stat-label">Terlambat</div>
        </div>
        <div class="rek-stat">
            <div class="rek-stat-icon" style="background:#faf5ff"><i class="fas fa-person-running" style="color:#8b5cf6"></i></div>
            <div class="rek-stat-val"><?= $summary['pulang_cepat'] ?></div>
            <div class="rek-stat-label">Pulang Cepat</div>
        </div>
        <div class="rek-stat">
            <div class="rek-stat-icon" style="background:#fef2f2"><i class="fas fa-calendar-xmark" style="color:#ef4444"></i></div>
            <div class="rek-stat-val"><?= $summary['absen'] ?></div>
            <div class="rek-stat-label">Absen</div>
        </div>
        <div class="rek-stat">
            <div class="rek-stat-icon" style="background:#eff6ff"><i class="fas fa-file-lines" style="color:#3b82f6"></i></div>
            <div class="rek-stat-val"><?= $summary['izin'] + $summary['sakit'] + $summary['dinas_luar'] ?></div>
            <div class="rek-stat-label">Izin/Sakit</div>
        </div>
        <div class="rek-stat">
            <div class="rek-stat-icon" style="background:#f0fdf4"><i class="fas fa-business-time" style="color:#059669"></i></div>
            <div class="rek-stat-val" style="font-size:14px;margin-top:2px"><?= formatDurasi($summary['total_durasi']) ?></div>
            <div class="rek-stat-label">Total Durasi</div>
        </div>
    </div>

    <!-- ══ ALERT BANNER ══ -->
    <?php if ($summary['total_terlambat_detik'] > 0): ?>
    <div class="rek-alert rek-alert-warn">
        <i class="fas fa-clock"></i>
        <span>Total keterlambatan: <strong><?= formatTerlambat($summary['total_terlambat_detik']) ?></strong></span>
    </div>
    <?php endif; ?>
    <?php if ($summary['total_pulang_cepat_detik'] > 0): ?>
    <div class="rek-alert rek-alert-purple">
        <i class="fas fa-person-running"></i>
        <span>Total pulang lebih awal: <strong><?= formatTerlambat($summary['total_pulang_cepat_detik']) ?></strong></span>
    </div>
    <?php endif; ?>

    <!-- ══ SECTION HEADER ══ -->
    <div class="rek-section-head" style="margin-top:6px">
        <div class="rek-section-title">
            <i class="fas fa-list-ul"></i> Detail Absensi — <?= ($namaBulan[(int)$bulan] ?? '') . ' ' . $tahun ?>
        </div>
        <span class="rek-count-badge"><?= count($absensis) ?> hari</span>
    </div>

    <?php if (empty($absensis)): ?>
    <div class="rek-empty">
        <div class="rek-empty-icon"><i class="fas fa-calendar-days"></i></div>
        <div class="rek-empty-title">Tidak ada data</div>
        <div class="rek-empty-sub">Belum ada absensi untuk periode <?= ($namaBulan[(int)$bulan] ?? '') . ' ' . $tahun ?></div>
    </div>

    <?php else: ?>

    <!-- ══ DESKTOP TABLE ══ -->
    <div class="rek-table-wrap">
        <table class="rek-table">
            <thead>
                <tr>
                    <th>Tanggal</th>
                    <th>Shift</th>
                    <th>Masuk</th>
                    <th>Keluar</th>
                    <th>Lokasi</th>
                    <th>Status</th>
                    <th><i class="fas fa-clock" style="color:#f59e0b"></i> Terlambat</th>
                    <th><i class="fas fa-person-running" style="color:#8b5cf6"></i> Pulang Cepat</th>
                    <th>Durasi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($absensis as $a):
                    $terlambat   = (int)($a['terlambat_detik'] ?? 0);
                    $pulangCepat = (int)($a['pulang_cepat_detik'] ?? 0);
                    $hariEn      = date('D', strtotime($a['tanggal']));
                    $hariId      = $namaHariEn[$hariEn] ?? $hariEn;
                ?>
                <tr>
                    <td>
                        <span style="font-weight:700"><?= date('d/m/Y', strtotime($a['tanggal'])) ?></span>
                        <div class="rek-sub"><?= $hariId ?></div>
                    </td>
                    <td style="font-size:12.5px"><?= htmlspecialchars($a['shift_nama']??'-') ?></td>
                    <td>
                        <?php if ($a['waktu_masuk']): ?>
                        <span class="rek-mono" style="font-size:13px"><?= date('H:i:s', strtotime($a['waktu_masuk'])) ?></span>
                        <?php if ($a['jam_masuk']): ?>
                        <div class="rek-sub">Jadwal: <?= substr($a['jam_masuk'],0,5) ?></div>
                        <?php endif; ?>
                        <?php else: ?><span style="color:#c8d3de">—</span><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($a['waktu_keluar']): ?>
                        <span class="rek-mono" style="font-size:13px"><?= date('H:i:s', strtotime($a['waktu_keluar'])) ?></span>
                        <?php if ($a['jam_keluar']): ?>
                        <div class="rek-sub">Jadwal: <?= substr($a['jam_keluar'],0,5) ?></div>
                        <?php endif; ?>
                        <?php else: ?><span style="color:#c8d3de">—</span><?php endif; ?>
                    </td>
                    <td style="font-size:12px;color:#64748b;max-width:110px"><?= htmlspecialchars($a['lokasi_nama']??'-') ?></td>
                    <td><?= badgeStatus($a['status_kehadiran']) ?></td>
                    <td>
                        <?php if ($terlambat > 0): ?>
                        <span style="background:#fef3c7;color:#92400e;padding:3px 9px;border-radius:6px;font-size:12px;font-weight:700;white-space:nowrap">
                            <i class="fas fa-clock"></i> <?= formatTerlambat($terlambat) ?>
                        </span>
                        <?php else: ?><span style="color:#c8d3de;font-size:12px">—</span><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($pulangCepat > 0): ?>
                        <span style="background:#f3e8ff;color:#6d28d9;padding:3px 9px;border-radius:6px;font-size:12px;font-weight:700;white-space:nowrap">
                            <i class="fas fa-person-running"></i> <?= formatTerlambat($pulangCepat) ?>
                        </span>
                        <?php else: ?><span style="color:#c8d3de;font-size:12px">—</span><?php endif; ?>
                    </td>
                    <td style="font-size:13px;white-space:nowrap"><?= formatDurasi($a['durasi_kerja']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="6" style="font-size:12.5px">TOTAL <?= ($namaBulan[(int)$bulan] ?? '') . ' ' . $tahun ?></td>
                    <td>
                        <?php if ($summary['total_terlambat_detik'] > 0): ?>
                        <span style="color:#d97706;font-size:12.5px"><?= formatTerlambat($summary['total_terlambat_detik']) ?></span>
                        <?php else: ?><span style="color:#c8d3de">—</span><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($summary['total_pulang_cepat_detik'] > 0): ?>
                        <span style="color:#7c3aed;font-size:12.5px"><?= formatTerlambat($summary['total_pulang_cepat_detik']) ?></span>
                        <?php else: ?><span style="color:#c8d3de">—</span><?php endif; ?>
                    </td>
                    <td style="white-space:nowrap"><?= formatDurasi($summary['total_durasi']) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <!-- ══ MOBILE CARD LIST ══ -->
    <div class="rek-card-list">
        <?php foreach ($absensis as $a):
            $terlambat   = (int)($a['terlambat_detik'] ?? 0);
            $pulangCepat = (int)($a['pulang_cepat_detik'] ?? 0);
            $hariEn      = date('D', strtotime($a['tanggal']));
            $hariId      = $namaHariEn[$hariEn] ?? $hariEn;
            $statusCss   = htmlspecialchars($a['status_kehadiran'] ?? 'absen');
        ?>
        <div class="rek-absen-card status-<?= $statusCss ?>">
            <!-- Header card -->
            <div class="rek-card-header">
                <div class="rek-card-date-block">
                    <div class="rek-card-date-box">
                        <div class="rek-card-day-name"><?= $hariId ?></div>
                        <div class="rek-card-day-num"><?= date('d', strtotime($a['tanggal'])) ?></div>
                        <div class="rek-card-month"><?= date('M', strtotime($a['tanggal'])) ?></div>
                    </div>
                    <div>
                        <div class="rek-card-title">
                            <?php
                            $judulCard = [
                                'hadir'      => 'Hadir',
                                'terlambat'  => 'Hadir — Terlambat',
                                'absen'      => 'Tidak Hadir',
                                'izin'       => 'Izin',
                                'sakit'      => 'Sakit',
                                'dinas_luar' => 'Dinas Luar',
                                'cuti'       => 'Cuti',
                            ];
                            echo $judulCard[$a['status_kehadiran']] ?? ucfirst($a['status_kehadiran']);
                            ?>
                        </div>
                        <div class="rek-card-shift">
                            <?php if ($a['shift_nama']): ?>
                            <i class="fas fa-layer-group" style="font-size:10px"></i> <?= htmlspecialchars($a['shift_nama']) ?>
                            <?php if ($a['jam_masuk']): ?>
                            · <?= substr($a['jam_masuk'],0,5) ?>–<?= substr($a['jam_keluar'],0,5) ?>
                            <?php endif; ?>
                            <?php else: ?>
                            <span style="color:#c8d3de">Shift tidak ditemukan</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?= badgeStatus($a['status_kehadiran']) ?>
            </div>

            <!-- Jam masuk / keluar / durasi -->
            <div class="rek-card-body">
                <div class="rek-card-times">
                    <div class="rek-time-box">
                        <div class="rek-time-label"><i class="fas fa-sign-in-alt" style="color:#10b981"></i> Masuk</div>
                        <div class="rek-time-val <?= $a['waktu_masuk'] ? 'ok' : 'dim' ?>">
                            <?= $a['waktu_masuk'] ? date('H:i', strtotime($a['waktu_masuk'])) : '--:--' ?>
                        </div>
                        <?php if ($a['waktu_masuk'] && $a['jam_masuk']): ?>
                        <div class="rek-time-sched"><?= substr($a['jam_masuk'],0,5) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="rek-time-box">
                        <div class="rek-time-label"><i class="fas fa-sign-out-alt" style="color:#f59e0b"></i> Keluar</div>
                        <div class="rek-time-val <?= $a['waktu_keluar'] ? 'warn' : 'dim' ?>">
                            <?= $a['waktu_keluar'] ? date('H:i', strtotime($a['waktu_keluar'])) : '--:--' ?>
                        </div>
                        <?php if ($a['waktu_keluar'] && $a['jam_keluar']): ?>
                        <div class="rek-time-sched"><?= substr($a['jam_keluar'],0,5) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="rek-time-box">
                        <div class="rek-time-label"><i class="fas fa-stopwatch" style="color:#3b82f6"></i> Durasi</div>
                        <div class="rek-time-val <?= $a['durasi_kerja'] ? '' : 'dim' ?>" style="font-size:<?= $a['durasi_kerja'] ? '13px' : '15px' ?>">
                            <?= $a['durasi_kerja'] ? formatDurasi($a['durasi_kerja']) : '--' ?>
                        </div>
                    </div>
                </div>

                <!-- Pills bawah -->
                <div class="rek-card-footer" style="padding:0;margin-top:2px">
                    <?php if ($terlambat > 0): ?>
                    <span class="rek-pill rek-pill-late">
                        <i class="fas fa-clock" style="font-size:10px"></i> Terlambat <?= formatTerlambat($terlambat) ?>
                    </span>
                    <?php endif; ?>
                    <?php if ($pulangCepat > 0): ?>
                    <span class="rek-pill rek-pill-early">
                        <i class="fas fa-person-running" style="font-size:10px"></i> Pulang cepat <?= formatTerlambat($pulangCepat) ?>
                    </span>
                    <?php endif; ?>
                    <?php if ($a['lokasi_nama']): ?>
                    <span class="rek-pill rek-pill-lokasi">
                        <i class="fas fa-location-dot" style="font-size:10px"></i> <?= htmlspecialchars($a['lokasi_nama']) ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Totals card mobile -->
        <div class="rek-totals-card">
            <div class="rek-totals-title"><i class="fas fa-sigma"></i> Total <?= ($namaBulan[(int)$bulan] ?? '') . ' ' . $tahun ?></div>
            <div class="rek-totals-row">
                <span class="rek-totals-key"><i class="fas fa-calendar-check" style="color:#10b981"></i> Hari Hadir</span>
                <span class="rek-totals-val"><?= $summary['hadir'] ?> hari</span>
            </div>
            <div class="rek-totals-row">
                <span class="rek-totals-key"><i class="fas fa-stopwatch" style="color:#3b82f6"></i> Total Durasi Kerja</span>
                <span class="rek-totals-val"><?= formatDurasi($summary['total_durasi']) ?></span>
            </div>
            <?php if ($summary['total_terlambat_detik'] > 0): ?>
            <div class="rek-totals-row">
                <span class="rek-totals-key"><i class="fas fa-clock" style="color:#f59e0b"></i> Total Keterlambatan</span>
                <span class="rek-totals-val" style="color:#d97706"><?= formatTerlambat($summary['total_terlambat_detik']) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($summary['total_pulang_cepat_detik'] > 0): ?>
            <div class="rek-totals-row">
                <span class="rek-totals-key"><i class="fas fa-person-running" style="color:#8b5cf6"></i> Total Pulang Cepat</span>
                <span class="rek-totals-val" style="color:#7c3aed"><?= formatTerlambat($summary['total_pulang_cepat_detik']) ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div><!-- /.rek-card-list -->

    <?php endif; ?>

</div><!-- /.rek-page -->

<?php include __DIR__ . '/../includes/footer.php'; ?>