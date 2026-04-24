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

// ── Mode filter: 'bulanan' atau 'periode' ──
$mode_filter   = $_GET['mode_filter']   ?? 'bulanan';
$bulan         = (int)($_GET['bulan']   ?? date('m'));
$tahun         = (int)($_GET['tahun']   ?? date('Y'));
$tgl_dari      = $_GET['tgl_dari']      ?? date('Y-m-01');
$tgl_sampai    = $_GET['tgl_sampai']    ?? date('Y-m-d');
$karyawan_id   = (int)($_GET['karyawan_id']   ?? 0);
$departemen_id = (int)($_GET['departemen_id'] ?? 0);

// Sanitasi tanggal
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl_dari))   $tgl_dari   = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl_sampai)) $tgl_sampai = date('Y-m-d');

// Periode aktif
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

// ── Ambil semua departemen ──
$stmtDep = $db->prepare("SELECT id, nama FROM departemen WHERE perusahaan_id=? ORDER BY nama");
$stmtDep->execute([$user['perusahaan_id']]);
$allDepartemen = $stmtDep->fetchAll();

// ── Query rekap ──
$sql = "SELECT k.id, k.nik, k.nama,
    d.nama as departemen_nama,
    SUM(CASE WHEN a.status_kehadiran IN ('hadir','terlambat') THEN 1 ELSE 0 END) as hadir,
    SUM(CASE WHEN a.status_kehadiran = 'terlambat' THEN 1 ELSE 0 END) as terlambat,
    SUM(CASE WHEN a.status_kehadiran = 'absen'     THEN 1 ELSE 0 END) as absen,
    SUM(CASE WHEN a.status_kehadiran = 'izin'      THEN 1 ELSE 0 END) as izin,
    SUM(CASE WHEN a.status_kehadiran = 'sakit'     THEN 1 ELSE 0 END) as sakit,
    SUM(CASE WHEN IFNULL(a.pulang_cepat_detik,0) > 0 THEN 1 ELSE 0 END) as pulang_cepat,
    SUM(COALESCE(a.terlambat_detik,0))      as total_terlambat_detik,
    SUM(COALESCE(a.pulang_cepat_detik,0))   as total_pulang_cepat_detik,
    SUM(COALESCE(a.durasi_kerja,0))          as total_durasi
    FROM karyawan k
    LEFT JOIN departemen d ON d.id = k.departemen_id
    LEFT JOIN absensi a ON a.karyawan_id=k.id AND {$date_cond}
    WHERE k.perusahaan_id=? AND k.role='karyawan'";

$params = array_merge($date_params, [$user['perusahaan_id']]);

if ($departemen_id) { $sql .= " AND k.departemen_id=?"; $params[] = $departemen_id; }
if ($karyawan_id)   { $sql .= " AND k.id=?";            $params[] = $karyawan_id; }
$sql .= " GROUP BY k.id ORDER BY d.nama, k.nama";

$stmt = $db->prepare($sql); $stmt->execute($params);
$rekapKaryawan = $stmt->fetchAll();

// ── Detail per karyawan ──
$detailAbsen = [];
$selectedK   = null;
if ($karyawan_id) {
    $sqlD = "SELECT a.*, s.nama as shift_nama, s.jam_masuk, s.jam_keluar,
        l.nama as lokasi_nama
        FROM absensi a
        LEFT JOIN shift s ON s.id=a.shift_id
        LEFT JOIN lokasi l ON l.id=a.lokasi_id
        WHERE a.karyawan_id=? AND {$date_cond} ORDER BY a.tanggal";
    $stmtD = $db->prepare($sqlD);
    $stmtD->execute(array_merge([$karyawan_id], $date_params));
    $detailAbsen = $stmtD->fetchAll();

    $stmtK = $db->prepare("SELECT k.*, dep.nama as departemen_nama FROM karyawan k LEFT JOIN departemen dep ON dep.id=k.departemen_id WHERE k.id=? AND k.perusahaan_id=?");
    $stmtK->execute([$karyawan_id, $user['perusahaan_id']]);
    $selectedK = $stmtK->fetch();
}

$allKaryawan = $db->prepare("SELECT id,nama,nik FROM karyawan WHERE perusahaan_id=? AND role='karyawan' ORDER BY nama");
$allKaryawan->execute([$user['perusahaan_id']]); $allKaryawan = $allKaryawan->fetchAll();

// ── Hitung hari kerja untuk periode ──
function hitungHariKerja($dari, $sampai) {
    $count = 0;
    $d = strtotime($dari);
    $s = strtotime($sampai);
    while ($d <= $s) {
        $dow = (int)date('N', $d);
        if ($dow <= 5) $count++;
        $d = strtotime('+1 day', $d);
    }
    return $count;
}
if ($mode_filter === 'periode') {
    $jumlahHariKerja = hitungHariKerja($tgl_dari, $tgl_sampai);
} else {
    $jumlahHariKerja = hitungHariKerja($tahun.'-'.str_pad($bulan,2,'0',STR_PAD_LEFT).'-01',
        $tahun.'-'.str_pad($bulan,2,'0',STR_PAD_LEFT).'-'.cal_days_in_month(CAL_GREGORIAN,$bulan,$tahun));
}

// Build URL untuk export/cetak dengan parameter lengkap
$filterQs = http_build_query([
    'mode_filter'   => $mode_filter,
    'bulan'         => $bulan,
    'tahun'         => $tahun,
    'tgl_dari'      => $tgl_dari,
    'tgl_sampai'    => $tgl_sampai,
    'karyawan_id'   => $karyawan_id,
    'departemen_id' => $departemen_id,
]);

include __DIR__ . '/../includes/header.php';
?>

<style>
/* ── Layout ── */
.rekap-header-actions { display:flex; gap:8px; flex-wrap:wrap; }
.rekap-summary { display:grid; grid-template-columns:repeat(6,1fr); gap:10px; margin-bottom:20px; }
@media(max-width:760px) { .rekap-summary { grid-template-columns:repeat(3,1fr); } }
@media(max-width:480px) { .rekap-summary { grid-template-columns:repeat(2,1fr); } }
.rekap-sum-item { background:#fff; border-radius:12px; padding:14px 10px; text-align:center; border:1px solid var(--border); box-shadow:var(--shadow); }
.rekap-sum-num  { font-size:1.6rem; font-weight:800; line-height:1; }
.rekap-sum-lbl  { font-size:11px; color:var(--text-muted); font-weight:600; text-transform:uppercase; letter-spacing:.4px; margin-top:4px; }

/* ── Filter card ── */
.filter-card { background:#fff; border-radius:14px; border:1px solid var(--border); box-shadow:var(--shadow); margin-bottom:16px; overflow:hidden; }
.filter-tabs { display:flex; border-bottom:1px solid var(--border); }
.filter-tab  { flex:1; padding:11px 16px; font-size:13px; font-weight:600; text-align:center; cursor:pointer; color:var(--text-muted); transition:all .2s; border:none; background:transparent; }
.filter-tab.active { color:var(--primary); border-bottom:3px solid var(--primary); background:#f0f7ff; }
.filter-tab:hover:not(.active) { background:var(--surface2); }
.filter-body { padding:14px 16px; }
.filter-row  { display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; }
.filter-group { display:flex; flex-direction:column; gap:4px; }
.filter-label { font-size:12px; font-weight:600; color:var(--text-muted); }
.filter-pane  { display:none; }
.filter-pane.active { display:block; }

/* ── Departemen group header ── */
.dept-group-header { background:linear-gradient(90deg,#e8f0fe,#f0f7ff); border-left:4px solid var(--primary); padding:8px 14px; font-size:12px; font-weight:700; color:var(--primary); letter-spacing:.3px; text-transform:uppercase; }

/* ── Mobile cards ── */
.karyawan-card  { background:#fff; border-radius:12px; border:1px solid var(--border); padding:14px 16px; margin-bottom:10px; box-shadow:var(--shadow); display:none; }
@media(max-width:700px) { .karyawan-card { display:block; } .table-rekap-wrap { display:none; } }
.karyawan-card-header { display:flex; align-items:center; gap:10px; margin-bottom:12px; }
.karyawan-card-stats  { display:grid; grid-template-columns:repeat(3,1fr); gap:8px; margin-bottom:12px; }
.kc-stat { text-align:center; background:var(--surface2); border-radius:8px; padding:8px 4px; }
.kc-stat-num { font-size:1.2rem; font-weight:800; }
.kc-stat-lbl { font-size:10px; color:var(--text-muted); font-weight:600; }
.karyawan-card-actions { display:flex; gap:8px; }
.karyawan-card-actions .btn { flex:1; justify-content:center; }

/* ── Detail items ── */
.detail-item { background:#fff; border-radius:10px; border:1px solid var(--border); padding:12px 14px; margin-bottom:8px; }
.detail-item-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; }
.detail-stat-row { display:flex; justify-content:space-between; align-items:center; padding:5px 0; border-bottom:1px solid var(--border); font-size:12.5px; }
.detail-stat-row:last-child { border:none; }

/* ── Periode badge ── */
.periode-badge { display:inline-flex; align-items:center; gap:6px; background:#eef3fa; color:#0f4c81; border-radius:8px; padding:5px 12px; font-size:12px; font-weight:700; border:1px solid #b8d0eb; }

/* ── Kehadiran bar ── */
.hadir-bar-wrap { width:60px; background:#e5e7eb; border-radius:4px; height:6px; display:inline-block; vertical-align:middle; margin-left:4px; }
.hadir-bar { height:6px; border-radius:4px; }
</style>

<div class="page-header" style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <div>
        <h2>Rekap Semua Karyawan</h2>
        <p>Laporan kehadiran — <?= htmlspecialchars($period_label) ?>
            <?php if ($departemen_id): ?>
            <span class="periode-badge" style="margin-left:8px"><i class="fas fa-building"></i>
                <?php foreach($allDepartemen as $dep): if($dep['id']==$departemen_id) echo htmlspecialchars($dep['nama']); endforeach; ?>
            </span>
            <?php endif; ?>
        </p>
    </div>
    <div class="rekap-header-actions">
        <a href="cetak_rekap.php?<?= $filterQs ?>" target="_blank" class="btn btn-primary btn-sm">
            <i class="fas fa-print"></i> <span class="hide-xs">Cetak </span>PDF
        </a>
        <a href="../api/export_rekap.php?<?= $filterQs ?>&all=1" class="btn btn-outline btn-sm">
            <i class="fas fa-download"></i> <span class="hide-xs">Export </span>CSV
        </a>
    </div>
</div>

<!-- ══ FILTER CARD ══ -->
<div class="filter-card">
    <div class="filter-tabs">
        <button type="button" class="filter-tab <?= $mode_filter==='bulanan'?'active':'' ?>" onclick="switchMode('bulanan')">
            <i class="fas fa-calendar-alt"></i> Bulanan
        </button>
        <button type="button" class="filter-tab <?= $mode_filter==='periode'?'active':'' ?>" onclick="switchMode('periode')">
            <i class="fas fa-calendar-range"></i> Rentang Tanggal
        </button>
    </div>

    <div class="filter-body">
        <form method="GET" id="filterForm">
            <input type="hidden" name="mode_filter" id="mode_filter_input" value="<?= htmlspecialchars($mode_filter) ?>">

            <!-- Mode Bulanan -->
            <div class="filter-pane <?= $mode_filter==='bulanan'?'active':'' ?>" id="pane-bulanan">
                <div class="filter-row">
                    <div class="filter-group">
                        <label class="filter-label">Bulan</label>
                        <select name="bulan" class="form-select">
                            <?php for($m=1;$m<=12;$m++): ?>
                            <option value="<?= $m ?>" <?= $m==$bulan?'selected':'' ?>><?= $monthsId[$m] ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">Tahun</label>
                        <select name="tahun" class="form-select">
                            <?php for($y=date('Y');$y>=2024;$y--): ?>
                            <option value="<?= $y ?>" <?= $y==$tahun?'selected':'' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <?php echo filterDeptKaryawan($allDepartemen, $allKaryawan, $departemen_id, $karyawan_id); ?>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Tampilkan</button>
                    <?php if($karyawan_id||$departemen_id): ?>
                    <a href="?mode_filter=bulanan&bulan=<?= $bulan ?>&tahun=<?= $tahun ?>" class="btn btn-outline" title="Reset filter"><i class="fas fa-times"></i></a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Mode Periode -->
            <div class="filter-pane <?= $mode_filter==='periode'?'active':'' ?>" id="pane-periode">
                <div class="filter-row">
                    <div class="filter-group">
                        <label class="filter-label">Dari Tanggal</label>
                        <input type="date" name="tgl_dari" class="form-select" value="<?= htmlspecialchars($tgl_dari) ?>" max="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">Sampai Tanggal</label>
                        <input type="date" name="tgl_sampai" class="form-select" value="<?= htmlspecialchars($tgl_sampai) ?>" max="<?= date('Y-m-d') ?>">
                    </div>
                    <?php echo filterDeptKaryawan($allDepartemen, $allKaryawan, $departemen_id, $karyawan_id); ?>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Tampilkan</button>
                    <?php if($karyawan_id||$departemen_id): ?>
                    <a href="?mode_filter=periode&tgl_dari=<?= $tgl_dari ?>&tgl_sampai=<?= $tgl_sampai ?>" class="btn btn-outline" title="Reset filter"><i class="fas fa-times"></i></a>
                    <?php endif; ?>
                </div>
                <?php
                // Hitung selisih hari
                $selisih = (int)ceil((strtotime($tgl_sampai) - strtotime($tgl_dari)) / 86400) + 1;
                if ($selisih > 0 && $mode_filter === 'periode'): ?>
                <div style="margin-top:8px;font-size:12px;color:var(--text-muted)">
                    <i class="fas fa-info-circle"></i>
                    Rentang: <strong><?= $selisih ?> hari</strong> | Hari kerja: <strong><?= $jumlahHariKerja ?> hari</strong>
                </div>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php
// Helper: render dropdown dept & karyawan
function filterDeptKaryawan($allDepartemen, $allKaryawan, $departemen_id, $karyawan_id) {
    ob_start(); ?>
    <div class="filter-group" style="min-width:140px">
        <label class="filter-label">Departemen</label>
        <select name="departemen_id" class="form-select" id="sel-dept" onchange="filterKaryawanByDept()">
            <option value="">Semua Departemen</option>
            <?php foreach($allDepartemen as $d): ?>
            <option value="<?= $d['id'] ?>" <?= $d['id']==$departemen_id?'selected':'' ?>><?= htmlspecialchars($d['nama']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group" style="flex:1;min-width:160px">
        <label class="filter-label">Karyawan</label>
        <select name="karyawan_id" class="form-select" id="sel-karyawan">
            <option value="">Semua Karyawan</option>
            <?php foreach($allKaryawan as $k): ?>
            <option value="<?= $k['id'] ?>" <?= $k['id']==$karyawan_id?'selected':'' ?>><?= htmlspecialchars($k['nama']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php return ob_get_clean();
}
?>

<!-- ══ SUMMARY STATS ══ -->
<?php
$totH=$totT=$totA=$totI=$totS=$totPC=0;
foreach($rekapKaryawan as $r){
    $totH+=$r['hadir']; $totT+=$r['terlambat']; $totA+=$r['absen'];
    $totI+=$r['izin'];  $totS+=$r['sakit'];      $totPC+=$r['pulang_cepat'];
}
?>
<div class="rekap-summary">
    <div class="rekap-sum-item"><div class="rekap-sum-num" style="color:var(--success)"><?= $totH ?></div><div class="rekap-sum-lbl"><i class="fas fa-user-check"></i> Hadir</div></div>
    <div class="rekap-sum-item"><div class="rekap-sum-num" style="color:var(--warning)"><?= $totT ?></div><div class="rekap-sum-lbl"><i class="fas fa-clock"></i> Terlambat</div></div>
    <div class="rekap-sum-item"><div class="rekap-sum-num" style="color:#7c3aed"><?= $totPC ?></div><div class="rekap-sum-lbl"><i class="fas fa-person-running"></i> Pulang Cepat</div></div>
    <div class="rekap-sum-item"><div class="rekap-sum-num" style="color:var(--danger)"><?= $totA ?></div><div class="rekap-sum-lbl"><i class="fas fa-user-xmark"></i> Absen</div></div>
    <div class="rekap-sum-item"><div class="rekap-sum-num" style="color:var(--info)"><?= $totI ?></div><div class="rekap-sum-lbl"><i class="fas fa-file-lines"></i> Izin</div></div>
    <div class="rekap-sum-item"><div class="rekap-sum-num" style="color:#7c3aed"><?= $totS ?></div><div class="rekap-sum-lbl"><i class="fas fa-kit-medical"></i> Sakit</div></div>
</div>

<!-- ══ DESKTOP TABLE ══ -->
<div class="card table-rekap-wrap" style="margin-bottom:20px">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
        <h3>Ringkasan — <?= htmlspecialchars($period_label) ?></h3>
        <span style="font-size:12px;color:var(--text-muted)"><?= count($rekapKaryawan) ?> karyawan | <?= $jumlahHariKerja ?> hari kerja</span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Karyawan</th>
                    <th>Departemen</th>
                    <th class="text-center" style="color:var(--success)">Hadir</th>
                    <th class="text-center" style="color:var(--warning)">Terlambat</th>
                    <th class="text-center" style="color:#7c3aed">Plg. Cepat</th>
                    <th class="text-center" style="color:var(--danger)">Absen</th>
                    <th class="text-center">Izin</th>
                    <th class="text-center">Sakit</th>
                    <th>Total Terlambat</th>
                    <th>Total Plg. Cepat</th>
                    <th>Total Kerja</th>
                    <th>Kehadiran</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rekapKaryawan)): ?>
                <tr><td colspan="13" class="text-center text-muted" style="padding:24px">Tidak ada data</td></tr>
                <?php else:
                    $prevDept = null;
                    foreach ($rekapKaryawan as $r):
                    $pct = $jumlahHariKerja > 0 ? min(100, round(($r['hadir'] / $jumlahHariKerja) * 100)) : 0;
                    $pctColor = $pct >= 90 ? 'var(--success)' : ($pct >= 75 ? 'var(--warning)' : 'var(--danger)');

                    // Group header per departemen (hanya jika tidak filter 1 karyawan)
                    if (!$karyawan_id && $r['departemen_nama'] !== $prevDept):
                        $prevDept = $r['departemen_nama'];
                ?>
                <tr>
                    <td colspan="13" style="padding:0">
                        <div class="dept-group-header">
                            <i class="fas fa-building"></i> <?= htmlspecialchars($r['departemen_nama'] ?: 'Tanpa Departemen') ?>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td>
                        <div style="display:flex;align-items:center;gap:10px">
                            <div style="width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,#0f4c81,#00c9a7);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:13px;flex-shrink:0">
                                <?= strtoupper(substr($r['nama'],0,1)) ?>
                            </div>
                            <div>
                                <div style="font-weight:600;font-size:13.5px"><?= htmlspecialchars($r['nama']) ?></div>
                                <div style="font-size:11.5px;color:var(--text-muted)"><?= $r['nik'] ?></div>
                            </div>
                        </div>
                    </td>
                    <td style="font-size:12px;color:var(--text-muted)"><?= htmlspecialchars($r['departemen_nama'] ?: '-') ?></td>
                    <td class="text-center"><span style="font-weight:700;color:var(--success)"><?= $r['hadir'] ?></span></td>
                    <td class="text-center"><span style="font-weight:700;color:<?= $r['terlambat']>0?'var(--warning)':'var(--text-muted)' ?>"><?= $r['terlambat'] ?: '-' ?></span></td>
                    <td class="text-center"><span style="font-weight:700;color:<?= $r['pulang_cepat']>0?'#7c3aed':'var(--text-muted)' ?>"><?= $r['pulang_cepat'] ?: '-' ?></span></td>
                    <td class="text-center"><span style="font-weight:700;color:<?= $r['absen']>0?'var(--danger)':'var(--text-muted)' ?>"><?= $r['absen'] ?: '-' ?></span></td>
                    <td class="text-center"><span style="color:var(--info)"><?= $r['izin'] ?: '-' ?></span></td>
                    <td class="text-center"><span style="color:#7c3aed"><?= $r['sakit'] ?: '-' ?></span></td>
                    <td style="font-size:12.5px;color:<?= $r['total_terlambat_detik']>0?'var(--warning)':'var(--text-muted)' ?>">
                        <?= $r['total_terlambat_detik']>0 ? formatTerlambat($r['total_terlambat_detik']) : '-' ?>
                    </td>
                    <td style="font-size:12.5px;color:<?= $r['total_pulang_cepat_detik']>0?'#7c3aed':'var(--text-muted)' ?>">
                        <?= $r['total_pulang_cepat_detik']>0 ? formatTerlambat($r['total_pulang_cepat_detik']) : '-' ?>
                    </td>
                    <td style="font-size:12.5px"><?= formatDurasi($r['total_durasi']) ?></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:4px">
                            <span style="font-size:12px;font-weight:700;color:<?= $pctColor ?>;min-width:30px"><?= $pct ?>%</span>
                            <div class="hadir-bar-wrap"><div class="hadir-bar" style="width:<?= $pct ?>%;background:<?= $pctColor ?>"></div></div>
                        </div>
                    </td>
                    <td>
                        <div style="display:flex;gap:6px">
                            <a href="?<?= $filterQs ?>&karyawan_id=<?= $r['id'] ?>" class="btn btn-outline btn-sm btn-icon" title="Detail"><i class="fas fa-eye"></i></a>
                            <a href="cetak_rekap.php?<?= $filterQs ?>&karyawan_id=<?= $r['id'] ?>" target="_blank" class="btn btn-sm btn-icon" style="background:#0f4c81;color:#fff;border:none" title="Cetak PDF"><i class="fas fa-print"></i></a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ══ MOBILE CARDS ══ -->
<?php foreach($rekapKaryawan as $r):
    $pct = $jumlahHariKerja > 0 ? min(100, round(($r['hadir'] / $jumlahHariKerja) * 100)) : 0;
    $pctColor = $pct >= 90 ? 'var(--success)' : ($pct >= 75 ? 'var(--warning)' : 'var(--danger)');
?>
<div class="karyawan-card">
    <div class="karyawan-card-header">
        <div style="width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#0f4c81,#00c9a7);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:15px;flex-shrink:0">
            <?= strtoupper(substr($r['nama'],0,1)) ?>
        </div>
        <div style="flex:1">
            <div style="font-weight:700;font-size:14px"><?= htmlspecialchars($r['nama']) ?></div>
            <div style="font-size:12px;color:var(--text-muted)"><?= $r['nik'] ?> · <?= htmlspecialchars($r['departemen_nama'] ?: '-') ?></div>
        </div>
        <span style="font-size:13px;font-weight:800;color:<?= $pctColor ?>"><?= $pct ?>%</span>
    </div>
    <div class="karyawan-card-stats">
        <div class="kc-stat"><div class="kc-stat-num" style="color:var(--success)"><?= $r['hadir'] ?></div><div class="kc-stat-lbl">Hadir</div></div>
        <div class="kc-stat"><div class="kc-stat-num" style="color:var(--warning)"><?= $r['terlambat'] ?></div><div class="kc-stat-lbl">Terlambat</div></div>
        <div class="kc-stat"><div class="kc-stat-num" style="color:#7c3aed"><?= $r['pulang_cepat'] ?></div><div class="kc-stat-lbl">Plg. Cepat</div></div>
        <div class="kc-stat"><div class="kc-stat-num" style="color:var(--danger)"><?= $r['absen'] ?></div><div class="kc-stat-lbl">Absen</div></div>
        <div class="kc-stat"><div class="kc-stat-num" style="color:var(--info)"><?= $r['izin'] ?></div><div class="kc-stat-lbl">Izin</div></div>
        <div class="kc-stat"><div class="kc-stat-num" style="color:var(--primary)"><?= formatDurasi($r['total_durasi']) ?></div><div class="kc-stat-lbl">Total Jam</div></div>
    </div>
    <?php if ($r['total_terlambat_detik']>0 || $r['total_pulang_cepat_detik']>0): ?>
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px">
        <?php if ($r['total_terlambat_detik']>0): ?>
        <span style="background:#fef3c7;color:#92400e;padding:3px 9px;border-radius:6px;font-size:11.5px;font-weight:600"><i class="fas fa-clock"></i> <?= formatTerlambat($r['total_terlambat_detik']) ?></span>
        <?php endif; ?>
        <?php if ($r['total_pulang_cepat_detik']>0): ?>
        <span style="background:#f3e8ff;color:#6d28d9;padding:3px 9px;border-radius:6px;font-size:11.5px;font-weight:600"><i class="fas fa-person-running"></i> <?= formatTerlambat($r['total_pulang_cepat_detik']) ?></span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="karyawan-card-actions">
        <a href="?<?= $filterQs ?>&karyawan_id=<?= $r['id'] ?>" class="btn btn-outline btn-sm" style="display:flex;align-items:center;justify-content:center;gap:6px"><i class="fas fa-eye"></i> Detail</a>
        <a href="cetak_rekap.php?<?= $filterQs ?>&karyawan_id=<?= $r['id'] ?>" target="_blank" class="btn btn-sm" style="background:#0f4c81;color:#fff;display:flex;align-items:center;justify-content:center;gap:6px"><i class="fas fa-print"></i> PDF</a>
    </div>
</div>
<?php endforeach; ?>

<!-- ══ DETAIL PER KARYAWAN ══ -->
<?php if ($karyawan_id && $selectedK): ?>
<div class="card" style="margin-top:8px">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
        <h3><i class="fas fa-list-check" style="color:var(--primary)"></i> Detail — <?= htmlspecialchars($selectedK['nama']) ?>
            <?php if ($selectedK['departemen_nama']): ?>
            <span style="font-size:12px;font-weight:400;color:var(--text-muted);margin-left:6px"><?= htmlspecialchars($selectedK['departemen_nama']) ?></span>
            <?php endif; ?>
        </h3>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <span class="periode-badge"><i class="fas fa-calendar"></i> <?= htmlspecialchars($period_label) ?></span>
            <a href="cetak_rekap.php?<?= $filterQs ?>" target="_blank" class="btn btn-primary btn-sm"><i class="fas fa-print"></i> Cetak PDF</a>
        </div>
    </div>

    <?php if(empty($detailAbsen)): ?>
    <div style="text-align:center;padding:30px;color:var(--text-muted)">Tidak ada data absensi</div>
    <?php else: ?>

    <!-- Desktop detail table -->
    <div class="table-wrap table-rekap-wrap">
        <table>
            <thead>
                <tr>
                    <th>Tanggal</th><th>Hari</th><th>Shift</th><th>Lokasi</th>
                    <th>Masuk</th><th>Keluar</th><th>Status</th>
                    <th><i class="fas fa-clock" style="color:#f59e0b"></i> Terlambat</th>
                    <th><i class="fas fa-person-running" style="color:#7c3aed"></i> Pulang Cepat</th>
                    <th>Durasi</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $totalTelat = $totalPulang = $totalDurasi = 0;
            foreach($detailAbsen as $a):
                $terlambat   = (int)($a['terlambat_detik'] ?? 0);
                $pulangCepat = (int)($a['pulang_cepat_detik'] ?? 0);
                $totalTelat  += $terlambat;
                $totalPulang += $pulangCepat;
                $totalDurasi += (int)($a['durasi_kerja'] ?? 0);
                $isWE = in_array(date('N', strtotime($a['tanggal'])), [6,7]);
            ?>
            <tr <?= $isWE ? 'style="background:#f8f9ff"' : '' ?>>
                <td style="font-weight:600"><?= date('d/m/Y',strtotime($a['tanggal'])) ?></td>
                <td style="font-size:12px;color:<?= $isWE ? '#2563eb' : 'var(--text-muted)' ?>"><?= $hariId[date('D',strtotime($a['tanggal']))] ?? date('D',strtotime($a['tanggal'])) ?></td>
                <td style="font-size:12.5px"><?= htmlspecialchars($a['shift_nama']??'-') ?></td>
                <td style="font-size:12px;color:var(--text-muted)"><?= htmlspecialchars($a['lokasi_nama']??'-') ?></td>
                <td style="font-family:'JetBrains Mono',monospace;font-size:13px"><?= $a['waktu_masuk']?date('H:i:s',strtotime($a['waktu_masuk'])):'-' ?></td>
                <td style="font-family:'JetBrains Mono',monospace;font-size:13px"><?= $a['waktu_keluar']?date('H:i:s',strtotime($a['waktu_keluar'])):'-' ?></td>
                <td><?= badgeStatus($a['status_kehadiran']) ?></td>
                <td>
                    <?php if ($terlambat > 0): ?>
                    <span style="background:#fef3c7;color:#92400e;padding:2px 7px;border-radius:5px;font-size:12px;font-weight:600"><?= formatTerlambat($terlambat) ?></span>
                    <?php else: ?><span style="color:var(--text-muted);font-size:12px">-</span><?php endif; ?>
                </td>
                <td>
                    <?php if ($pulangCepat > 0): ?>
                    <span style="background:#f3e8ff;color:#6d28d9;padding:2px 7px;border-radius:5px;font-size:12px;font-weight:600"><?= formatTerlambat($pulangCepat) ?></span>
                    <?php else: ?><span style="color:var(--text-muted);font-size:12px">-</span><?php endif; ?>
                </td>
                <td style="font-size:12.5px"><?= formatDurasi($a['durasi_kerja']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background:var(--surface2);font-weight:700">
                    <td colspan="7" style="padding:10px 12px;font-size:13px">TOTAL PERIODE INI</td>
                    <td><span style="color:#d97706;font-size:12.5px"><?= $totalTelat>0?formatTerlambat($totalTelat):'-' ?></span></td>
                    <td><span style="color:#7c3aed;font-size:12.5px"><?= $totalPulang>0?formatTerlambat($totalPulang):'-' ?></span></td>
                    <td style="font-size:12.5px"><?= formatDurasi($totalDurasi) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <!-- Mobile detail cards -->
    <div style="padding:12px;display:none" class="mobile-detail-list">
    <?php foreach($detailAbsen as $a):
        $terlambat   = (int)($a['terlambat_detik'] ?? 0);
        $pulangCepat = (int)($a['pulang_cepat_detik'] ?? 0);
        $hariStr = $hariId[date('D',strtotime($a['tanggal']))] ?? date('D',strtotime($a['tanggal']));
    ?>
    <div class="detail-item">
        <div class="detail-item-header">
            <div>
                <span style="font-weight:700;font-size:14px"><?= date('d/m/Y',strtotime($a['tanggal'])) ?></span>
                <span style="font-size:12px;color:var(--text-muted);margin-left:6px"><?= $hariStr ?></span>
            </div>
            <?= badgeStatus($a['status_kehadiran']) ?>
        </div>
        <div>
            <div class="detail-stat-row"><span style="color:var(--text-muted)"><i class="fas fa-sign-in-alt" style="color:var(--success)"></i> Masuk</span><span style="font-family:'JetBrains Mono',monospace;font-weight:600"><?= $a['waktu_masuk']?date('H:i:s',strtotime($a['waktu_masuk'])):'-' ?></span></div>
            <div class="detail-stat-row"><span style="color:var(--text-muted)"><i class="fas fa-sign-out-alt" style="color:var(--danger)"></i> Keluar</span><span style="font-family:'JetBrains Mono',monospace;font-weight:600"><?= $a['waktu_keluar']?date('H:i:s',strtotime($a['waktu_keluar'])):'-' ?></span></div>
            <?php if ($terlambat > 0): ?>
            <div class="detail-stat-row"><span style="color:var(--text-muted)"><i class="fas fa-clock" style="color:#f59e0b"></i> Terlambat</span><span style="background:#fef3c7;color:#92400e;padding:2px 7px;border-radius:5px;font-size:11.5px;font-weight:600"><?= formatTerlambat($terlambat) ?></span></div>
            <?php endif; ?>
            <?php if ($pulangCepat > 0): ?>
            <div class="detail-stat-row"><span style="color:var(--text-muted)"><i class="fas fa-person-running" style="color:#7c3aed"></i> Pulang Cepat</span><span style="background:#f3e8ff;color:#6d28d9;padding:2px 7px;border-radius:5px;font-size:11.5px;font-weight:600"><?= formatTerlambat($pulangCepat) ?></span></div>
            <?php endif; ?>
            <?php if ($a['durasi_kerja']): ?>
            <div class="detail-stat-row"><span style="color:var(--text-muted)"><i class="fas fa-stopwatch" style="color:var(--primary)"></i> Durasi</span><span style="font-weight:600"><?= formatDurasi($a['durasi_kerja']) ?></span></div>
            <?php endif; ?>
            <?php if (!empty($a['lokasi_nama'])): ?>
            <div class="detail-stat-row"><span style="color:var(--text-muted)"><i class="fas fa-map-marker-alt"></i> Lokasi</span><span style="font-size:12px"><?= htmlspecialchars($a['lokasi_nama']) ?></span></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
    <script>if(window.innerWidth<=700)document.querySelector('.mobile-detail-list').style.display='block';</script>
    <?php endif; ?>
</div>
<?php endif; ?>

<script>
// ── Tab switch mode filter ──
function switchMode(mode) {
    document.getElementById('mode_filter_input').value = mode;
    document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.filter-pane').forEach(p => p.classList.remove('active'));
    event.currentTarget.classList.add('active');
    document.getElementById('pane-' + mode).classList.add('active');
}

// ── Filter karyawan by departemen (opsional — bisa diintegrasikan ke AJAX) ──
function filterKaryawanByDept() {
    // Jika ingin filter dropdown karyawan secara client-side,
    // tambahkan data-dept ke setiap option di sel-karyawan lalu filter di sini.
    // Contoh sederhana: reset pilihan karyawan saat ganti dept
    document.querySelectorAll('#sel-karyawan').forEach(sel => {
        sel.value = '';
    });
}

// ── Validasi tanggal: dari <= sampai ──
document.getElementById('filterForm').addEventListener('submit', function(e) {
    var dari    = document.querySelector('[name="tgl_dari"]');
    var sampai  = document.querySelector('[name="tgl_sampai"]');
    if (dari && sampai && dari.value && sampai.value) {
        if (dari.value > sampai.value) {
            e.preventDefault();
            alert('Tanggal "Dari" tidak boleh lebih besar dari "Sampai".');
        }
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>