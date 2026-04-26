<?php
require_once __DIR__ . '/includes/config.php';
requireLogin();

$pageTitle  = 'Dashboard';
$activePage = 'dashboard';
$user       = currentUser();
$db         = getDB();

$today = date('Y-m-d');

if ($user['role'] === 'admin' || $user['role'] === 'manager') {
    $totalKaryawan = $db->query("SELECT COUNT(*) FROM karyawan WHERE perusahaan_id = {$user['perusahaan_id']} AND role = 'karyawan' AND status = 'aktif'")->fetchColumn();
    $hadirHariIni  = $db->query("SELECT COUNT(*) FROM absensi a JOIN karyawan k ON k.id = a.karyawan_id WHERE k.perusahaan_id = {$user['perusahaan_id']} AND a.tanggal = '$today' AND a.status_kehadiran IN ('hadir','terlambat')")->fetchColumn();
    $terlambat     = $db->query("SELECT COUNT(*) FROM absensi a JOIN karyawan k ON k.id = a.karyawan_id WHERE k.perusahaan_id = {$user['perusahaan_id']} AND a.tanggal = '$today' AND a.status_kehadiran = 'terlambat'")->fetchColumn();
    $absen         = $totalKaryawan - $hadirHariIni;

    $stmt = $db->prepare("SELECT DATE_FORMAT(a.tanggal,'%d/%m') as tgl, 
        SUM(CASE WHEN a.status_kehadiran IN ('hadir','terlambat') THEN 1 ELSE 0 END) as hadir,
        SUM(CASE WHEN a.status_kehadiran = 'terlambat' THEN 1 ELSE 0 END) as terlambat
        FROM absensi a JOIN karyawan k ON k.id = a.karyawan_id
        WHERE k.perusahaan_id = ? AND a.tanggal >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
        GROUP BY a.tanggal ORDER BY a.tanggal ASC");
    $stmt->execute([$user['perusahaan_id']]);
    $chartData = $stmt->fetchAll();

    $stmtAbsen = $db->prepare("SELECT a.*, k.nama, k.nik, k.foto FROM absensi a 
        JOIN karyawan k ON k.id = a.karyawan_id
        WHERE k.perusahaan_id = ? AND a.tanggal = ? ORDER BY a.waktu_masuk DESC LIMIT 10");
    $stmtAbsen->execute([$user['perusahaan_id'], $today]);
    $absensiHariIni = $stmtAbsen->fetchAll();

} else {
    // ── Karyawan: data ringkasan bulan ini ──
    $bulan = date('Y-m');

    $stmtHadir = $db->prepare("SELECT COUNT(*) FROM absensi WHERE karyawan_id = ? AND DATE_FORMAT(tanggal,'%Y-%m') = ? AND status_kehadiran IN ('hadir','terlambat')");
    $stmtHadir->execute([$user['id'], $bulan]);
    $totalHadir = (int)$stmtHadir->fetchColumn();

    $stmtTerlambat = $db->prepare("SELECT COUNT(*) FROM absensi WHERE karyawan_id = ? AND DATE_FORMAT(tanggal,'%Y-%m') = ? AND status_kehadiran = 'terlambat'");
    $stmtTerlambat->execute([$user['id'], $bulan]);
    $totalTerlambat = (int)$stmtTerlambat->fetchColumn();

    $stmtTotalTerlambatDetik = $db->prepare("SELECT COALESCE(SUM(terlambat_detik),0) FROM absensi WHERE karyawan_id = ? AND DATE_FORMAT(tanggal,'%Y-%m') = ?");
    $stmtTotalTerlambatDetik->execute([$user['id'], $bulan]);
    $totalTerlambatDetik = (int)$stmtTotalTerlambatDetik->fetchColumn();

    // Absensi hari ini
    $stmtToday = $db->prepare("
        SELECT a.*, s.nama as shift_nama, s.jam_masuk, s.jam_keluar,
               l.nama as lokasi_nama
        FROM absensi a
        LEFT JOIN shift s ON s.id = a.shift_id
        LEFT JOIN lokasi l ON l.id = a.lokasi_id
        WHERE a.karyawan_id = ? AND a.tanggal = ?
    ");
    $stmtToday->execute([$user['id'], $today]);
    $absenToday = $stmtToday->fetch();

    // Jadwal aktif
    $stmtJadwal = $db->prepare("
        SELECT jk.*, j.nama as jadwal_nama,
               s.nama as shift_nama, s.jam_masuk, s.jam_keluar, s.toleransi_terlambat_detik
        FROM jadwal_karyawan jk
        JOIN jadwal j ON j.id = jk.jadwal_id
        JOIN shift s ON s.id = j.shift_id
        WHERE jk.karyawan_id = ?
          AND jk.berlaku_dari <= CURDATE()
          AND (jk.berlaku_sampai IS NULL OR jk.berlaku_sampai >= CURDATE())
        LIMIT 1
    ");
    $stmtJadwal->execute([$user['id']]);
    $jadwalAktif = $stmtJadwal->fetch();

    // Riwayat 7 hari terakhir
    $stmtRiwayat = $db->prepare("
        SELECT a.*, s.nama as shift_nama, s.jam_masuk, s.jam_keluar
        FROM absensi a
        LEFT JOIN shift s ON s.id = a.shift_id
        WHERE a.karyawan_id = ?
        ORDER BY a.tanggal DESC LIMIT 7
    ");
    $stmtRiwayat->execute([$user['id']]);
    $absensiSaya = $stmtRiwayat->fetchAll();

    // Sisa hari kerja bulan ini (hari kerja = Senin-Jumat)
    $sisaHariKerja = 0;
    $lastDay = date('t');
    $todayNum = date('j');
    for ($d = $todayNum + 1; $d <= $lastDay; $d++) {
        $dow = date('N', mktime(0,0,0,date('m'),$d,date('Y')));
        if ($dow < 6) $sisaHariKerja++;
    }

    $namaHari  = ['','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu'][(int)date('N')];
    $namaBulan = ['','Januari','Februari','Maret','April','Mei','Juni','Juli',
                  'Agustus','September','Oktober','November','Desember'][(int)date('n')];
}

include __DIR__ . '/includes/header.php';
?>

<?php if ($user['role'] === 'admin' || $user['role'] === 'manager'): ?>
<!-- ════════════════════════════════════════
     ADMIN / MANAGER DASHBOARD
════════════════════════════════════════ -->
<div class="page-header">
    <h2>Selamat Datang, <?= htmlspecialchars(explode(' ', $user['nama'])[0]) ?>! 👋</h2>
    <p><?= tglIndonesia() ?> — <?= ucfirst($user['role']) ?></p>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon blue"><i class="fas fa-users"></i></div>
        <div>
            <div class="stat-value"><?= $totalKaryawan ?></div>
            <div class="stat-label">Total Karyawan Aktif</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="fas fa-user-check"></i></div>
        <div>
            <div class="stat-value"><?= $hadirHariIni ?></div>
            <div class="stat-label">Hadir Hari Ini</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon amber"><i class="fas fa-clock"></i></div>
        <div>
            <div class="stat-value"><?= $terlambat ?></div>
            <div class="stat-label">Terlambat Hari Ini</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon red"><i class="fas fa-user-xmark"></i></div>
        <div>
            <div class="stat-value"><?= $absen < 0 ? 0 : $absen ?></div>
            <div class="stat-label">Belum Absen</div>
        </div>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-chart-line" style="color:var(--primary)"></i> Kehadiran 7 Hari Terakhir</h3>
        </div>
        <div class="card-body">
            <canvas id="chartKehadiran" height="200"></canvas>
        </div>
    </div>
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-list-check" style="color:var(--accent)"></i> Absensi Hari Ini</h3>
            <a href="pages/rekap_admin.php" class="btn btn-outline btn-sm">Lihat Semua</a>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Karyawan</th>
                        <th>Masuk</th>
                        <th>Status</th>
                        <th class="hide-mobile">Terlambat</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($absensiHariIni)): ?>
                    <tr><td colspan="4" class="text-center text-muted" style="padding:24px">Belum ada absensi hari ini</td></tr>
                    <?php else: foreach ($absensiHariIni as $ab): ?>
                    <tr>
                        <td>
                            <div style="font-weight:600"><?= htmlspecialchars($ab['nama']) ?></div>
                            <div style="font-size:11.5px;color:var(--text-muted)"><?= $ab['nik'] ?></div>
                        </td>
                        <td style="font-family:'JetBrains Mono',monospace;font-size:13px">
                            <?= $ab['waktu_masuk'] ? date('H:i', strtotime($ab['waktu_masuk'])) : '-' ?>
                        </td>
                        <td><?= badgeStatus($ab['status_kehadiran']) ?></td>
                        <td class="hide-mobile" style="font-size:12.5px;color:var(--text-muted)">
                            <?= formatTerlambat($ab['terlambat_detik']) ?>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script>
const chartData = <?= json_encode($chartData) ?>;
new Chart(document.getElementById('chartKehadiran'), {
    type: 'bar',
    data: {
        labels:   chartData.map(d => d.tgl),
        datasets: [
            { label:'Hadir',     data:chartData.map(d=>parseInt(d.hadir)),     backgroundColor:'#dcfce7', borderColor:'#16a34a', borderWidth:2, borderRadius:6 },
            { label:'Terlambat', data:chartData.map(d=>parseInt(d.terlambat)), backgroundColor:'#fef3c7', borderColor:'#d97706', borderWidth:2, borderRadius:6 }
        ]
    },
    options: { responsive:true, plugins:{ legend:{ position:'bottom' } }, scales:{ y:{ beginAtZero:true, ticks:{ stepSize:1 } } } }
});
</script>

<?php else: ?>
<!-- ════════════════════════════════════════
     KARYAWAN DASHBOARD
════════════════════════════════════════ -->

<style>
/* ─── Scoped dashboard karyawan ─── */
.dsh-page { max-width: 640px; margin: 0 auto; padding-bottom: 16px; }

/* ─── Header greeting ─── */
.dsh-greeting {
    background: linear-gradient(150deg, #0f4c81 0%, #1a6bb5 100%);
    border-radius: 16px; padding: 18px 20px;
    margin-bottom: 14px;
    box-shadow: 0 6px 24px rgba(15,76,129,0.2);
    position: relative; overflow: hidden;
}
.dsh-greeting::before {
    content: ''; position: absolute;
    width: 160px; height: 160px; border-radius: 50%;
    background: rgba(255,255,255,0.06); right: -30px; top: -50px;
}
.dsh-greeting::after {
    content: ''; position: absolute;
    width: 90px; height: 90px; border-radius: 50%;
    background: rgba(255,255,255,0.04); right: 70px; bottom: -30px;
}
.dsh-greeting-top { display: flex; justify-content: space-between; align-items: flex-start; position: relative; z-index: 1; }
.dsh-greeting-left {}
.dsh-hello   { font-size: 13px; color: rgba(255,255,255,0.72); font-weight: 600; }
.dsh-name    { font-size: 20px; font-weight: 900; color: #fff; letter-spacing: -0.3px; margin-top: 2px; }
.dsh-role    { font-size: 12px; color: rgba(255,255,255,0.6); font-weight: 600; margin-top: 2px; }
.dsh-date-badge {
    background: rgba(255,255,255,0.14); border: 1px solid rgba(255,255,255,0.2);
    border-radius: 10px; padding: 7px 12px; text-align: center;
    position: relative; z-index: 1; flex-shrink: 0;
}
.dsh-date-day  { font-size: 22px; font-weight: 900; color: #fff; line-height: 1; }
.dsh-date-mon  { font-size: 11px; color: rgba(255,255,255,0.7); font-weight: 700; margin-top: 2px; }
.dsh-date-full { font-size: 12px; color: rgba(255,255,255,0.65); font-weight: 600; margin-top: 10px; position: relative; z-index: 1; }

/* ─── Status absen hari ini ─── */
.dsh-today-card {
    background: #fff; border-radius: 14px; padding: 16px;
    box-shadow: 0 2px 14px rgba(15,76,129,0.08);
    margin-bottom: 14px;
}
.dsh-today-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; }
.dsh-today-title  { font-size: 13px; font-weight: 800; color: #0f172a; }
.dsh-shift-pill {
    display: inline-flex; align-items: center; gap: 5px;
    background: #eff6ff; color: #1d4ed8; border-radius: 20px;
    padding: 4px 12px; font-size: 12px; font-weight: 700;
}
.dsh-times-row { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; }
.dsh-time-box  { background: #f8fafc; border-radius: 10px; padding: 12px; text-align: center; }
.dsh-time-label { font-size: 10px; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 4px; }
.dsh-time-val   { font-size: 18px; font-weight: 900; color: #cbd5e1; font-variant-numeric: tabular-nums; line-height: 1.1; }
.dsh-time-val.ok   { color: #10b981; }
.dsh-time-val.warn { color: #f59e0b; }
.dsh-time-val.active { color: #0f4c81; font-size: 20px; }
.dsh-time-sub  { font-size: 11px; color: #94a3b8; font-weight: 600; margin-top: 3px; }

.dsh-alert { padding: 9px 13px; border-radius: 8px; font-size: 12.5px; font-weight: 700; display: flex; align-items: center; gap: 8px; margin-top: 10px; }
.dsh-alert-warn { background: #fffbeb; color: #78350f; border-left: 3px solid #f59e0b; }
.dsh-alert-ok   { background: #ecfdf5; color: #065f46; border-left: 3px solid #10b981; }
.dsh-alert-info { background: #eff6ff; color: #1e40af; border-left: 3px solid #3b82f6; }

.dsh-go-absen {
    display: flex; align-items: center; justify-content: center; gap: 8px;
    width: 100%; margin-top: 12px; padding: 13px;
    background: linear-gradient(135deg, #10b981, #059669);
    color: #fff; border: none; border-radius: 10px;
    font-size: 14px; font-weight: 800; cursor: pointer;
    text-decoration: none; font-family: inherit;
    transition: transform .15s, opacity .15s;
    -webkit-tap-highlight-color: transparent;
}
.dsh-go-absen:active { transform: scale(0.98); opacity: .9; }
.dsh-go-absen.keluar { background: linear-gradient(135deg, #f59e0b, #d97706); }
.dsh-go-absen.done   { background: #f8fafc; color: #10b981; border: 1.5px solid #d1fae5; cursor: default; pointer-events: none; }

/* ─── Stats row ─── */
.dsh-stats { display: grid; grid-template-columns: repeat(3,1fr); gap: 10px; margin-bottom: 14px; }
.dsh-stat {
    background: #fff; border-radius: 13px; padding: 14px 12px;
    box-shadow: 0 2px 12px rgba(15,76,129,0.07);
    display: flex; flex-direction: column; align-items: center; gap: 5px; text-align: center;
}
.dsh-stat-icon { width: 38px; height: 38px; border-radius: 11px; display: flex; align-items: center; justify-content: center; font-size: 17px; margin-bottom: 2px; }
.dsh-stat-val  { font-size: 24px; font-weight: 900; color: #0f172a; line-height: 1; }
.dsh-stat-val.sm { font-size: 16px; }
.dsh-stat-label{ font-size: 10.5px; color: #64748b; font-weight: 700; }

/* ─── Riwayat ─── */
.dsh-section-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
.dsh-section-title { font-size: 12px; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: .7px; }
.dsh-riwayat-list { display: flex; flex-direction: column; gap: 8px; }
.dsh-riwayat-item {
    background: #fff; border-radius: 12px;
    padding: 12px 14px;
    box-shadow: 0 2px 10px rgba(15,76,129,0.06);
    display: flex; align-items: center; gap: 12px;
    border-left: 4px solid #e2e8f0;
}
.dsh-riwayat-item.hadir      { border-left-color: #10b981; }
.dsh-riwayat-item.terlambat  { border-left-color: #f59e0b; }
.dsh-riwayat-item.absen      { border-left-color: #ef4444; }
.dsh-riwayat-item.izin       { border-left-color: #8b5cf6; }
.dsh-riwayat-item.sakit      { border-left-color: #f43f5e; }
.dsh-riwayat-item.dinas_luar { border-left-color: #0f4c81; }

.dsh-riwayat-date {
    background: #f0f4f8; border-radius: 8px; padding: 5px 9px;
    text-align: center; min-width: 44px; flex-shrink: 0;
}
.dsh-riwayat-dayname { font-size: 9.5px; color: #64748b; font-weight: 800; text-transform: uppercase; }
.dsh-riwayat-daynum  { font-size: 18px; font-weight: 900; color: #0f172a; line-height: 1.1; }
.dsh-riwayat-main  { flex: 1; min-width: 0; }
.dsh-riwayat-title { font-size: 13px; font-weight: 800; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.dsh-riwayat-sub   { font-size: 11.5px; color: #64748b; font-weight: 600; margin-top: 2px; }
.dsh-riwayat-times { display: flex; flex-direction: column; align-items: flex-end; gap: 2px; flex-shrink: 0; }
.dsh-riwayat-time  { font-size: 13px; font-weight: 800; color: #0f172a; font-variant-numeric: tabular-nums; }
.dsh-riwayat-time.dim { color: #c8d3de; }
.dsh-riwayat-dur   { font-size: 11px; color: #94a3b8; font-weight: 700; }
.dsh-empty { text-align: center; padding: 32px 20px; color: #94a3b8; font-size: 13px; font-weight: 700; }
.dsh-empty i { font-size: 32px; display: block; margin-bottom: 10px; opacity: .4; }
.dsh-lihat-semua {
    display: flex; align-items: center; gap: 5px;
    font-size: 12px; font-weight: 800; color: #0f4c81;
    text-decoration: none; white-space: nowrap;
    padding: 5px 12px; background: #eff6ff; border-radius: 20px;
}
</style>

<div class="dsh-page">

    <!-- ══ GREETING ══ -->
    <div class="dsh-greeting">
        <div class="dsh-greeting-top">
            <div class="dsh-greeting-left">
                <?php
                $jam   = (int)date('H');
                $salam = $jam < 11 ? 'Selamat Pagi' : ($jam < 15 ? 'Selamat Siang' : ($jam < 18 ? 'Selamat Sore' : 'Selamat Malam'));
                ?>
                <div class="dsh-hello"><?= $salam ?> 👋</div>
                <div class="dsh-name"><?= htmlspecialchars($user['nama']) ?></div>
                <div class="dsh-role"><?= ucfirst($user['role']) ?></div>
            </div>
            <div class="dsh-date-badge">
                <div class="dsh-date-day"><?= date('d') ?></div>
                <div class="dsh-date-mon"><?= $namaBulan[(int)date('n')] ?></div>
            </div>
        </div>
        <div class="dsh-date-full">
            <?= $namaHari ?>, <?= date('d') ?> <?= $namaBulan[(int)date('n')] ?> <?= date('Y') ?>
            <?php if ($jadwalAktif): ?>
            &nbsp;·&nbsp;
            <i class="fas fa-layer-group" style="font-size:11px"></i>
            <?= htmlspecialchars($jadwalAktif['shift_nama']) ?>
            <?= substr($jadwalAktif['jam_masuk'],0,5) ?>–<?= substr($jadwalAktif['jam_keluar'],0,5) ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- ══ STATUS ABSEN HARI INI ══ -->
    <div class="dsh-today-card">
        <div class="dsh-today-header">
            <span class="dsh-today-title"><i class="fas fa-calendar-day" style="color:#0f4c81;margin-right:6px"></i>Status Absen Hari Ini</span>
            <?php if ($absenToday): ?>
            <?= badgeStatus($absenToday['status_kehadiran']) ?>
            <?php else: ?>
            <span style="font-size:12px;color:#94a3b8;font-weight:700">Belum absen</span>
            <?php endif; ?>
        </div>

        <div class="dsh-times-row">
            <div class="dsh-time-box">
                <div class="dsh-time-label"><i class="fas fa-sign-in-alt" style="color:#10b981"></i> Masuk</div>
                <div class="dsh-time-val <?= !empty($absenToday['waktu_masuk']) ? 'ok' : '' ?>">
                    <?= !empty($absenToday['waktu_masuk']) ? date('H:i', strtotime($absenToday['waktu_masuk'])) : '--:--' ?>
                </div>
                <?php if (!empty($jadwalAktif['jam_masuk'])): ?>
                <div class="dsh-time-sub"><?= substr($jadwalAktif['jam_masuk'],0,5) ?></div>
                <?php endif; ?>
            </div>
            <div class="dsh-time-box">
                <div class="dsh-time-label"><i class="fas fa-sign-out-alt" style="color:#f59e0b"></i> Keluar</div>
                <div class="dsh-time-val <?= !empty($absenToday['waktu_keluar']) ? 'warn' : '' ?>">
                    <?= !empty($absenToday['waktu_keluar']) ? date('H:i', strtotime($absenToday['waktu_keluar'])) : '--:--' ?>
                </div>
                <?php if (!empty($jadwalAktif['jam_keluar'])): ?>
                <div class="dsh-time-sub"><?= substr($jadwalAktif['jam_keluar'],0,5) ?></div>
                <?php endif; ?>
            </div>
            <div class="dsh-time-box">
                <div class="dsh-time-label"><i class="fas fa-stopwatch" style="color:#3b82f6"></i> Durasi</div>
                <div class="dsh-time-val <?= !empty($absenToday['durasi_kerja']) ? '' : '' ?>"
                     style="<?= !empty($absenToday['durasi_kerja']) ? 'color:#0f172a;font-size:15px' : '' ?>">
                    <?= !empty($absenToday['durasi_kerja']) ? formatDurasi($absenToday['durasi_kerja']) : '--' ?>
                </div>
                <?php if (!empty($absenToday['lokasi_nama'])): ?>
                <div class="dsh-time-sub"><i class="fas fa-map-pin" style="font-size:9px"></i> <?= htmlspecialchars($absenToday['lokasi_nama']) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($absenToday['terlambat_detik']) && $absenToday['terlambat_detik'] > 0): ?>
        <div class="dsh-alert dsh-alert-warn">
            <i class="fas fa-clock"></i>
            Terlambat: <strong><?= formatTerlambat($absenToday['terlambat_detik']) ?></strong>
        </div>
        <?php endif; ?>
        <?php if (!empty($absenToday['durasi_kerja'])): ?>
        <div class="dsh-alert dsh-alert-ok">
            <i class="fas fa-circle-check"></i>
            Durasi kerja: <strong><?= formatDurasi($absenToday['durasi_kerja']) ?></strong>
        </div>
        <?php endif; ?>
        <?php if (empty($absenToday['waktu_masuk']) && $jadwalAktif): ?>
        <div class="dsh-alert dsh-alert-info">
            <i class="fas fa-circle-info"></i>
            Anda belum absen masuk hari ini
        </div>
        <?php endif; ?>

        <!-- Tombol ke halaman absen -->
        <?php if (empty($absenToday) || empty($absenToday['waktu_masuk'])): ?>
        <a href="pages/absen.php" class="dsh-go-absen">
            <i class="fas fa-fingerprint"></i> Buka Halaman Absen
        </a>
        <?php elseif (!empty($absenToday['waktu_masuk']) && empty($absenToday['waktu_keluar'])): ?>
        <a href="pages/absen.php" class="dsh-go-absen keluar">
            <i class="fas fa-door-open"></i> Absen Keluar Sekarang
        </a>
        <?php else: ?>
        <a href="pages/absen.php" class="dsh-go-absen done">
            <i class="fas fa-check-circle"></i> Absensi Hari Ini Lengkap
        </a>
        <?php endif; ?>
    </div>

    <!-- ══ STATISTIK BULAN INI ══ -->
    <div class="dsh-stats">
        <div class="dsh-stat">
            <div class="dsh-stat-icon" style="background:#ecfdf5"><i class="fas fa-calendar-check" style="color:#10b981"></i></div>
            <div class="dsh-stat-val"><?= $totalHadir ?></div>
            <div class="dsh-stat-label">Hadir Bulan Ini</div>
        </div>
        <div class="dsh-stat">
            <div class="dsh-stat-icon" style="background:#fffbeb"><i class="fas fa-clock" style="color:#f59e0b"></i></div>
            <div class="dsh-stat-val"><?= $totalTerlambat ?></div>
            <div class="dsh-stat-label">Terlambat Bulan Ini</div>
        </div>
        <div class="dsh-stat">
            <div class="dsh-stat-icon" style="background:#eff6ff"><i class="fas fa-calendar-days" style="color:#3b82f6"></i></div>
            <div class="dsh-stat-val"><?= $sisaHariKerja ?></div>
            <div class="dsh-stat-label">Sisa Hari Kerja</div>
        </div>
    </div>

    <?php if ($totalTerlambatDetik > 0): ?>
    <div class="dsh-alert dsh-alert-warn" style="margin-bottom:14px">
        <i class="fas fa-triangle-exclamation"></i>
        Total keterlambatan bulan ini: <strong><?= formatTerlambat($totalTerlambatDetik) ?></strong>
    </div>
    <?php endif; ?>

    <!-- ══ RIWAYAT 7 HARI TERAKHIR ══ -->
    <div class="dsh-section-head">
        <span class="dsh-section-title"><i class="fas fa-clock-rotate-left"></i> Riwayat Absensi Terakhir</span>
        <a href="pages/rekap.php" class="dsh-lihat-semua">
            Lihat Semua <i class="fas fa-chevron-right" style="font-size:10px"></i>
        </a>
    </div>

    <?php if (empty($absensiSaya)): ?>
    <div class="dsh-empty">
        <i class="fas fa-inbox"></i>
        Belum ada data absensi
    </div>
    <?php else: ?>
    <div class="dsh-riwayat-list">
        <?php
        $namaHariEn = ['Mon'=>'Sen','Tue'=>'Sel','Wed'=>'Rab','Thu'=>'Kam','Fri'=>'Jum','Sat'=>'Sab','Sun'=>'Min'];
        $judulStatus = ['hadir'=>'Hadir','terlambat'=>'Hadir — Terlambat','absen'=>'Tidak Hadir','izin'=>'Izin','sakit'=>'Sakit','dinas_luar'=>'Dinas Luar','cuti'=>'Cuti'];
        foreach ($absensiSaya as $ab):
            $st  = $ab['status_kehadiran'] ?? 'absen';
            $hen = date('D', strtotime($ab['tanggal']));
            $hid = $namaHariEn[$hen] ?? $hen;
        ?>
        <div class="dsh-riwayat-item <?= htmlspecialchars($st) ?>">
            <div class="dsh-riwayat-date">
                <div class="dsh-riwayat-dayname"><?= $hid ?></div>
                <div class="dsh-riwayat-daynum"><?= date('d', strtotime($ab['tanggal'])) ?></div>
            </div>
            <div class="dsh-riwayat-main">
                <div class="dsh-riwayat-title"><?= $judulStatus[$st] ?? ucfirst($st) ?></div>
                <div class="dsh-riwayat-sub">
                    <?php if ($ab['shift_nama']): ?>
                    <i class="fas fa-layer-group" style="font-size:9px"></i>
                    <?= htmlspecialchars($ab['shift_nama']) ?>
                    <?php if ($ab['jam_masuk']): ?> · <?= substr($ab['jam_masuk'],0,5) ?>–<?= substr($ab['jam_keluar'],0,5) ?><?php endif; ?>
                    <?php else: ?>
                    <span style="color:#e2e8f0">—</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="dsh-riwayat-times">
                <div class="dsh-riwayat-time <?= $ab['waktu_masuk'] ? '' : 'dim' ?>">
                    <?= $ab['waktu_masuk'] ? date('H:i', strtotime($ab['waktu_masuk'])) : '--:--' ?>
                </div>
                <div class="dsh-riwayat-time <?= $ab['waktu_keluar'] ? '' : 'dim' ?>" style="font-size:11px">
                    <?= $ab['waktu_keluar'] ? date('H:i', strtotime($ab['waktu_keluar'])) : '--:--' ?>
                </div>
                <?php if ($ab['durasi_kerja']): ?>
                <div class="dsh-riwayat-dur"><?= formatDurasi($ab['durasi_kerja']) ?></div>
                <?php endif; ?>
            </div>
            <?= badgeStatus($st) ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div><!-- /.dsh-page -->

<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>