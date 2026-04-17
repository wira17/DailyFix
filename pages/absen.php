<?php
require_once __DIR__ . '/../includes/config.php';
requireLogin();

$pageTitle  = 'Absen Saya';
$activePage = 'absen';
$user       = currentUser();
$db         = getDB();
$today      = date('Y-m-d');

// ── Ambil jadwal aktif karyawan (tanpa join lokasi) ──
$stmtJadwal = $db->prepare("
    SELECT jk.*, j.nama as jadwal_nama, j.hari_kerja,
        s.id as shift_id, s.nama as shift_nama, s.jam_masuk, s.jam_keluar, s.toleransi_terlambat_detik
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

// ── Ambil semua lokasi yang diizinkan untuk karyawan ini ──
$stmtLokasi = $db->prepare("
    SELECT l.id, l.nama, l.latitude, l.longitude, l.radius_meter
    FROM karyawan_lokasi kl
    JOIN lokasi l ON l.id = kl.lokasi_id
    WHERE kl.karyawan_id = ? AND l.status = 'aktif'
    ORDER BY l.nama
");
$stmtLokasi->execute([$user['id']]);
$lokasiKaryawan = $stmtLokasi->fetchAll();

// Lokasi pertama sebagai default tampilan peta (fallback jika kosong)
$lokasiUtama = $lokasiKaryawan[0] ?? null;

$stmtToday = $db->prepare("SELECT * FROM absensi WHERE karyawan_id = ? AND tanggal = ?");
$stmtToday->execute([$user['id'], $today]);
$absenToday = $stmtToday->fetch();

include __DIR__ . '/../includes/header.php';
?>

<style>
.leaflet-pane         { z-index: 4 !important; }
.leaflet-control      { z-index: 8 !important; }
.leaflet-top, .leaflet-bottom { z-index: 8 !important; }
.modal-overlay        { z-index: 1000 !important; }

#map {
    width: 100%; height: 300px;
    border-radius: var(--radius-sm);
    border: 1px solid var(--border);
    position: relative; z-index: 1;
}
.absen-widget {
    background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 60%, #1e7ab8 100%);
    border-radius: var(--radius); padding: 24px; color: #fff; box-shadow: var(--shadow-lg);
}
.absen-time { font-family:'JetBrains Mono',monospace; font-size:2.6rem; font-weight:700; letter-spacing:2px; line-height:1; margin-bottom:4px; }
.absen-date { font-size:13px; opacity:.7; margin-bottom:16px; }
.loc-status { display:flex; align-items:center; gap:8px; margin-top:14px; padding:10px 12px; background:rgba(255,255,255,.1); border-radius:8px; font-size:13px; }
.loc-dot { width:10px; height:10px; border-radius:50%; background:#f59e0b; flex-shrink:0; box-shadow:0 0 0 3px rgba(245,158,11,.3); animation:pulse 1.5s infinite; }
.loc-dot.ok { background:#10b981; box-shadow:0 0 0 3px rgba(16,185,129,.3); }
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.5} }
.btn-absen { width:100%; margin-top:14px; padding:14px; border:none; border-radius:10px; font-size:15px; font-weight:700; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:8px; transition:all .2s; }
.btn-absen:disabled { opacity:.4; cursor:not-allowed; }
.btn-absen:not(:disabled):hover { transform:translateY(-1px); }
.btn-absen-masuk { background:#10b981; color:#fff; }
.btn-absen-masuk:not(:disabled):hover { background:#059669; }
.btn-absen-keluar { background:#f59e0b; color:#fff; }
.btn-absen-keluar:not(:disabled):hover { background:#d97706; }

.camera-wrap { position:relative; background:#000; border-radius:10px; overflow:hidden; aspect-ratio:4/3; width:100%; }
.camera-wrap video { width:100%; height:100%; object-fit:cover; display:block; transform:scaleX(-1); }
.face-guide {
    position:absolute; top:50%; left:50%; transform:translate(-50%,-52%);
    width:140px; height:175px;
    border:2px dashed rgba(255,255,255,.7);
    border-radius:50% 50% 50% 50% / 60% 60% 40% 40%;
    pointer-events:none;
}
.face-guide-label { position:absolute; bottom:-22px; left:50%; transform:translateX(-50%); font-size:11px; color:rgba(255,255,255,.8); white-space:nowrap; }
.selfie-step { display:none; }
.selfie-step.active { display:block; }
.modal-absen { max-width:480px; width:96vw; }

/* Badge lokasi aktif di widget */
.lokasi-badge-list { display:flex; flex-wrap:wrap; gap:5px; margin-top:8px; }
.lokasi-badge {
    display:inline-flex; align-items:center; gap:4px;
    background:rgba(255,255,255,.15); color:#fff;
    padding:3px 10px; border-radius:20px; font-size:11.5px; font-weight:600;
}
.lokasi-badge.matched { background:rgba(16,185,129,.35); }
</style>

<div class="page-header">
    <h2>Absen Saya</h2>
    <p>Lakukan absensi masuk dan keluar menggunakan GPS &amp; foto wajah</p>
</div>

<div class="grid-2" style="margin-bottom:20px">
    <div>
        <div class="absen-widget" style="margin-bottom:16px">
            <div style="font-size:11px;opacity:.6;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px">Waktu Sekarang</div>
            <div class="absen-time" id="bigClock">--:--:--</div>
            <div class="absen-date"><?= tglIndonesia() ?></div>

            <?php if ($jadwalInfo): ?>
            <div style="padding:10px;background:rgba(255,255,255,.1);border-radius:8px;font-size:13px">
                <div style="display:flex;justify-content:space-between;margin-bottom:4px">
                    <span style="opacity:.7">Shift</span>
                    <span style="font-weight:600"><?= htmlspecialchars($jadwalInfo['shift_nama']) ?></span>
                </div>
                <div style="display:flex;justify-content:space-between;margin-bottom:4px">
                    <span style="opacity:.7">Jam Masuk</span>
                    <span style="font-weight:600;font-family:'JetBrains Mono',monospace"><?= substr($jadwalInfo['jam_masuk'],0,5) ?></span>
                </div>
                <div style="display:flex;justify-content:space-between;margin-bottom:4px">
                    <span style="opacity:.7">Jam Keluar</span>
                    <span style="font-weight:600;font-family:'JetBrains Mono',monospace"><?= substr($jadwalInfo['jam_keluar'],0,5) ?></span>
                </div>
                <div style="display:flex;justify-content:space-between">
                    <span style="opacity:.7">Toleransi</span>
                    <span style="font-weight:600"><?= formatTerlambat($jadwalInfo['toleransi_terlambat_detik']) ?></span>
                </div>
            </div>

            <?php if (!empty($lokasiKaryawan)): ?>
            <div style="margin-top:10px;padding:8px 10px;background:rgba(255,255,255,.08);border-radius:8px">
                <div style="font-size:11px;opacity:.6;text-transform:uppercase;letter-spacing:1px;margin-bottom:5px">
                    <i class="fas fa-map-marker-alt"></i> Lokasi Absen Diizinkan
                </div>
                <div class="lokasi-badge-list" id="lokasiBadgeList">
                    <?php foreach($lokasiKaryawan as $lk): ?>
                    <span class="lokasi-badge" id="badge-lokasi-<?= $lk['id'] ?>">
                        <i class="fas fa-building" style="font-size:9px"></i>
                        <?= htmlspecialchars($lk['nama']) ?>
                    </span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="loc-status" id="locStatus">
                <div class="loc-dot" id="locDot"></div>
                <span id="locText">Mendeteksi lokasi GPS...</span>
            </div>

            <?php if ($isHariKerja): ?>
                <?php if (!$absenToday || !$absenToday['waktu_masuk']): ?>
                <button class="btn-absen btn-absen-masuk" id="btnAbsenMasuk" onclick="startAbsen('masuk')" disabled>
                    <i class="fas fa-fingerprint"></i> Absen Masuk
                </button>
                <?php elseif ($absenToday['waktu_masuk'] && !$absenToday['waktu_keluar']): ?>
                <button class="btn-absen btn-absen-keluar" id="btnAbsenKeluar" onclick="startAbsen('keluar')" disabled>
                    <i class="fas fa-door-open"></i> Absen Keluar
                </button>
                <?php else: ?>
                <div style="margin-top:16px;padding:12px;background:rgba(255,255,255,.15);border-radius:8px;text-align:center;font-size:14px">
                    <i class="fas fa-check-circle" style="color:#00c9a7"></i> Absensi hari ini sudah lengkap
                </div>
                <?php endif; ?>
            <?php else: ?>
            <div style="margin-top:16px;padding:12px;background:rgba(255,255,255,.1);border-radius:8px;text-align:center;font-size:13px;opacity:.8">
                <i class="fas fa-calendar-xmark"></i> Hari ini bukan hari kerja
            </div>
            <?php endif; ?>

            <?php else: ?>
            <div style="margin-top:16px;padding:12px;background:rgba(255,0,0,.2);border-radius:8px;text-align:center;font-size:13px">
                <i class="fas fa-triangle-exclamation"></i> Tidak ada jadwal aktif. Hubungi admin.
            </div>
            <?php endif; ?>
        </div>

        <?php if ($absenToday): ?>
        <div class="card">
            <div class="card-header"><h3>Status Absen Hari Ini</h3></div>
            <div class="card-body">
                <div style="display:grid;gap:10px">
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:10px;background:var(--surface2);border-radius:8px">
                        <div style="display:flex;align-items:center;gap:8px">
                            <i class="fas fa-sign-in-alt" style="color:var(--success)"></i>
                            <span style="font-size:13px;font-weight:600">Waktu Masuk</span>
                        </div>
                        <span style="font-family:'JetBrains Mono',monospace;font-size:15px;font-weight:700">
                            <?= $absenToday['waktu_masuk'] ? date('H:i:s', strtotime($absenToday['waktu_masuk'])) : '--:--:--' ?>
                        </span>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:10px;background:var(--surface2);border-radius:8px">
                        <div style="display:flex;align-items:center;gap:8px">
                            <i class="fas fa-sign-out-alt" style="color:var(--danger)"></i>
                            <span style="font-size:13px;font-weight:600">Waktu Keluar</span>
                        </div>
                        <span style="font-family:'JetBrains Mono',monospace;font-size:15px;font-weight:700">
                            <?= $absenToday['waktu_keluar'] ? date('H:i:s', strtotime($absenToday['waktu_keluar'])) : '--:--:--' ?>
                        </span>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:10px;background:var(--surface2);border-radius:8px">
                        <div style="display:flex;align-items:center;gap:8px">
                            <i class="fas fa-circle-info" style="color:var(--primary)"></i>
                            <span style="font-size:13px;font-weight:600">Status</span>
                        </div>
                        <?= badgeStatus($absenToday['status_kehadiran']) ?>
                    </div>
                    <?php if ($absenToday['terlambat_detik'] > 0): ?>
                    <div style="padding:10px 14px;background:#fffbeb;border-radius:8px;border-left:3px solid var(--warning);font-size:13px;color:#92400e">
                        <i class="fas fa-clock"></i> Terlambat: <strong><?= formatTerlambat($absenToday['terlambat_detik']) ?></strong>
                    </div>
                    <?php endif; ?>
                    <?php if ($absenToday['durasi_kerja']): ?>
                    <div style="padding:10px 14px;background:#ecfdf5;border-radius:8px;border-left:3px solid var(--success);font-size:13px;color:#065f46">
                        <i class="fas fa-stopwatch"></i> Durasi Kerja: <strong><?= formatDurasi($absenToday['durasi_kerja']) ?></strong>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($absenToday['foto_masuk']) || !empty($absenToday['foto_keluar'])): ?>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                        <?php if (!empty($absenToday['foto_masuk'])): ?>
                        <div style="text-align:center">
                            <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px"><i class="fas fa-sign-in-alt"></i> Foto Masuk</div>
                            <img src="<?= htmlspecialchars($absenToday['foto_masuk']) ?>" style="width:100%;border-radius:8px;border:2px solid var(--success)">
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($absenToday['foto_keluar'])): ?>
                        <div style="text-align:center">
                            <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px"><i class="fas fa-sign-out-alt"></i> Foto Keluar</div>
                            <img src="<?= htmlspecialchars($absenToday['foto_keluar']) ?>" style="width:100%;border-radius:8px;border:2px solid var(--warning)">
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div>
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-map-location-dot" style="color:var(--primary)"></i> Peta Lokasi</h3></div>
            <div class="card-body" style="padding:12px">
                <div id="map"></div>
                <div style="margin-top:10px;font-size:12.5px;color:var(--text-muted);display:grid;gap:4px">
                    <div id="coordInfo"><i class="fas fa-crosshairs"></i> Koordinat Anda: mendeteksi...</div>
                    <div id="distInfo"><i class="fas fa-ruler"></i> Jarak ke lokasi terdekat: menghitung...</div>
                    <?php if (!empty($lokasiKaryawan)): ?>
                    <div style="color:var(--primary)">
                        <i class="fas fa-map-pin"></i>
                        <?= count($lokasiKaryawan) ?> lokasi diizinkan:
                        <?= implode(', ', array_map(fn($l) => htmlspecialchars($l['nama']), $lokasiKaryawan)) ?>
                    </div>
                    <?php else: ?>
                    <div style="color:var(--danger)"><i class="fas fa-triangle-exclamation"></i> Belum ada lokasi terdaftar. Hubungi admin.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ===== MODAL ABSEN ===== -->
<div class="modal-overlay" id="modalAbsen">
    <div class="modal modal-absen">
        <div class="modal-header">
            <h3 id="modalTitle">Konfirmasi Absen</h3>
            <div class="modal-close" onclick="closeAbsenModal()"><i class="fas fa-xmark"></i></div>
        </div>
        <div class="modal-body" style="padding:16px 20px">

            <!-- Step 1: Info GPS -->
            <div class="selfie-step active" id="step1">
                <div id="gpsInfo" style="display:grid;gap:8px;margin-bottom:16px"></div>
                <div style="text-align:center;padding:16px;background:var(--surface2);border-radius:10px;margin-bottom:14px">
                    <i class="fas fa-camera" style="font-size:2.2rem;color:var(--primary);display:block;margin-bottom:8px"></i>
                    <div style="font-size:14px;font-weight:600;margin-bottom:4px">Verifikasi Wajah Diperlukan</div>
                    <div style="font-size:12px;color:var(--text-muted)">Ambil foto wajah untuk melanjutkan absensi</div>
                </div>
                <button class="btn btn-primary" style="width:100%;padding:12px" onclick="goToStep2()">
                    <i class="fas fa-camera"></i> Buka Kamera &amp; Ambil Foto
                </button>
            </div>

            <!-- Step 2: Kamera -->
            <div class="selfie-step" id="step2">
                <div style="margin-bottom:8px;font-size:13px;color:var(--text-muted);text-align:center">
                    <i class="fas fa-circle-info"></i> Posisikan wajah di dalam panduan oval, lalu klik Ambil Foto
                </div>
                <div class="camera-wrap" id="cameraWrap">
                    <video id="videoEl" autoplay playsinline muted></video>
                    <div class="face-guide"><div class="face-guide-label">Posisikan wajah di sini</div></div>
                </div>
                <div id="cameraError" style="display:none;padding:12px;background:#fef2f2;border-radius:8px;color:#991b1b;font-size:13px;margin-top:8px;text-align:center">
                    <i class="fas fa-video-slash"></i> Kamera tidak dapat diakses. Pastikan izin kamera diaktifkan di browser.
                </div>
                <canvas id="canvasEl" style="display:none"></canvas>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:10px">
                    <button class="btn btn-outline" onclick="backToStep1()"><i class="fas fa-arrow-left"></i> Kembali</button>
                    <button class="btn btn-primary" id="btnCapture" onclick="capturePhoto()"><i class="fas fa-camera"></i> Ambil Foto</button>
                </div>
            </div>

            <!-- Step 3: Preview + Submit -->
            <div class="selfie-step" id="step3">
                <div style="margin-bottom:8px;font-size:13px;font-weight:600;text-align:center;color:var(--success)">
                    <i class="fas fa-check-circle"></i> Foto berhasil diambil
                </div>
                <img id="selfiePreview" style="width:100%;border-radius:10px;border:3px solid var(--success);margin-bottom:12px;display:block">
                <div id="finalInfo" style="display:grid;gap:8px;margin-bottom:14px"></div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                    <button class="btn btn-outline" onclick="retakePhoto()"><i class="fas fa-rotate-left"></i> Ulangi Foto</button>
                    <button class="btn btn-primary" id="btnSubmit" onclick="submitAbsen()">
                        <i class="fas fa-check"></i> Konfirmasi Absen
                    </button>
                </div>
            </div>

        </div>
    </div>
</div>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
// ── Data lokasi karyawan dari PHP (bisa >1) ──
const lokasiList = <?= json_encode(array_map(fn($l) => [
    'id'           => $l['id'],
    'nama'         => $l['nama'],
    'latitude'     => (float)$l['latitude'],
    'longitude'    => (float)$l['longitude'],
    'radius_meter' => (int)$l['radius_meter'],
], $lokasiKaryawan)) ?>;

// Titik tengah peta: rata-rata semua lokasi, atau default Jakarta
const mapCenter = lokasiList.length > 0
    ? [
        lokasiList.reduce((s,l) => s + l.latitude,  0) / lokasiList.length,
        lokasiList.reduce((s,l) => s + l.longitude, 0) / lokasiList.length
      ]
    : [-6.2088, 106.8456];

let myLat = null, myLng = null, myAccuracy = 9999, mySpeed = null, myGpsUpdatedAt = 0;
let map, myMarker, gpsWatchId = null;
let absenType = null, photoDataURL = null, cameraStream = null;

// ── Lokasi terdekat yang sedang dalam zona ──
let lokasiMatch = null; // {id, nama, jarak}

map = L.map('map').setView(mapCenter, 15);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);

// Tampilkan semua lokasi di peta
lokasiList.forEach(l => {
    L.marker([l.latitude, l.longitude], {
        icon: L.divIcon({
            html: `<div style="background:#0f4c81;color:#fff;padding:4px 8px;border-radius:6px;font-size:11px;font-weight:700;white-space:nowrap">📍 ${l.nama}</div>`,
            className: ''
        })
    }).addTo(map);
    L.circle([l.latitude, l.longitude], {
        radius: l.radius_meter, color: '#0f4c81', fillColor: '#0f4c81', fillOpacity: .1
    }).addTo(map);
});

// Jam digital
setInterval(() => {
    const n = new Date();
    document.getElementById('bigClock').textContent =
        String(n.getHours()).padStart(2,'0') + ':' +
        String(n.getMinutes()).padStart(2,'0') + ':' +
        String(n.getSeconds()).padStart(2,'0');
}, 1000);

function haversine(lat1, lng1, lat2, lng2) {
    const R = 6371000, dL = (lat2-lat1)*Math.PI/180, dG = (lng2-lng1)*Math.PI/180;
    const a = Math.sin(dL/2)**2 + Math.cos(lat1*Math.PI/180)*Math.cos(lat2*Math.PI/180)*Math.sin(dG/2)**2;
    return Math.round(R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a)));
}

// ── Cek apakah posisi masuk ke salah satu lokasi ──
function cekSemuaLokasi(lat, lng) {
    let terdekat = null;
    lokasiList.forEach(l => {
        const jarak = haversine(lat, lng, l.latitude, l.longitude);
        const dalam = jarak <= l.radius_meter;
        // Update badge
        const badge = document.getElementById('badge-lokasi-' + l.id);
        if (badge) badge.classList.toggle('matched', dalam);
        if (dalam) {
            if (!terdekat || jarak < terdekat.jarak) {
                terdekat = { id: l.id, nama: l.nama, jarak };
            }
        }
    });
    return terdekat; // null jika tidak ada yang cocok
}

function updateGPS(pos) {
    myLat          = pos.coords.latitude;
    myLng          = pos.coords.longitude;
    myAccuracy     = pos.coords.accuracy ?? 9999;
    mySpeed        = pos.coords.speed;
    myGpsUpdatedAt = Date.now();

    // Cek semua lokasi
    lokasiMatch = cekSemuaLokasi(myLat, myLng);
    const dalam   = lokasiMatch !== null;
    const gpsOk   = dalam && myAccuracy <= 150;

    const accColor = myAccuracy < 20 ? '#10b981' : myAccuracy < 60 ? '#f59e0b' : '#ef4444';
    const accIcon  = myAccuracy < 20 ? '🎯' : myAccuracy < 60 ? '📡' : '⚠️';

    document.getElementById('coordInfo').innerHTML =
        `<i class="fas fa-crosshairs"></i> Koordinat: ${myLat.toFixed(6)}, ${myLng.toFixed(6)} ` +
        `<span style="color:${accColor};font-weight:600">${accIcon} ±${Math.round(myAccuracy)}m</span>`;

    if (lokasiMatch) {
        document.getElementById('distInfo').innerHTML =
            `<i class="fas fa-ruler"></i> Di zona <strong>${lokasiMatch.nama}</strong> (${lokasiMatch.jarak}m) ✅`;
    } else {
        // Hitung jarak ke lokasi terdekat (meski di luar zona)
        let minJarak = Infinity, minNama = '-';
        lokasiList.forEach(l => {
            const j = haversine(myLat, myLng, l.latitude, l.longitude);
            if (j < minJarak) { minJarak = j; minNama = l.nama; }
        });
        document.getElementById('distInfo').innerHTML = lokasiList.length > 0
            ? `<i class="fas fa-ruler"></i> Terdekat: <strong>${minNama}</strong> (${minJarak}m) ❌ Di luar zona`
            : `<i class="fas fa-ruler"></i> Belum ada lokasi terdaftar`;
    }

    // Update marker posisi
    if (myMarker) myMarker.setLatLng([myLat, myLng]);
    else myMarker = L.marker([myLat, myLng], {
        icon: L.divIcon({
            html: '<div style="background:#00c9a7;color:#fff;padding:4px 8px;border-radius:6px;font-size:11px;font-weight:700">📱 Anda</div>',
            className: ''
        })
    }).addTo(map);

    document.getElementById('locDot').classList.toggle('ok', dalam);

    if (!gpsOk && myAccuracy > 150) {
        document.getElementById('locText').textContent = `GPS kurang akurat (±${Math.round(myAccuracy)}m). Pindah ke luar ruangan.`;
    } else if (lokasiMatch) {
        document.getElementById('locText').textContent = `Dalam zona ${lokasiMatch.nama} (${lokasiMatch.jarak}m)`;
    } else {
        document.getElementById('locText').textContent = `Di luar semua zona kerja`;
    }

    const bM = document.getElementById('btnAbsenMasuk');
    const bK = document.getElementById('btnAbsenKeluar');
    if (bM) bM.disabled = !gpsOk;
    if (bK) bK.disabled = !gpsOk;
}

function onGpsError(err) {
    const dot  = document.getElementById('locDot');
    const text = document.getElementById('locText');
    dot.style.background = '#ef4444';
    dot.style.boxShadow  = '0 0 0 3px rgba(239,68,68,.3)';
    if (err.code === 1) { text.textContent = 'Izin lokasi ditolak.'; showGpsBanner(); }
    else if (err.code === 2) { text.textContent = 'GPS tidak tersedia. Aktifkan GPS di perangkat Anda.'; }
    else { text.textContent = 'Timeout GPS. Pastikan berada di area terbuka.'; setTimeout(startGPS, 3000); }
}

function startGPS() {
    if (!navigator.geolocation) { document.getElementById('locText').textContent = 'Browser tidak mendukung GPS.'; return; }
    navigator.permissions && navigator.permissions.query({name:'geolocation'}).then(r => { if (r.state==='denied') showGpsBanner(); }).catch(()=>{});
    if (gpsWatchId) navigator.geolocation.clearWatch(gpsWatchId);
    gpsWatchId = navigator.geolocation.watchPosition(updateGPS, onGpsError, { enableHighAccuracy:true, timeout:20000, maximumAge:5000 });
}

function showGpsBanner() {
    if (document.getElementById('gpsBanner')) return;
    const isAndroid = /Android/i.test(navigator.userAgent);
    const isIOS     = /iPhone|iPad/i.test(navigator.userAgent);
    let panduan = isIOS
        ? `<ol style="margin:8px 0 0 16px;font-size:12px;line-height:1.8"><li>Buka <b>Pengaturan → Safari</b></li><li>Pilih <b>Lokasi</b> → <b>Izinkan</b></li><li>Refresh halaman ini</li></ol>`
        : `<ol style="margin:8px 0 0 16px;font-size:12px;line-height:1.8"><li>Ketuk ikon 🔒 di address bar</li><li>Pilih <b>Izin → Lokasi</b></li><li>Refresh halaman ini</li></ol>`;
    const banner = document.createElement('div');
    banner.id = 'gpsBanner';
    banner.style.cssText = 'position:fixed;bottom:0;left:0;right:0;z-index:9999;background:#1e293b;color:#fff;padding:16px 20px;box-shadow:0 -4px 24px rgba(0,0,0,.3);border-radius:16px 16px 0 0;animation:slideUp .3s ease';
    banner.innerHTML = `<style>@keyframes slideUp{from{transform:translateY(100%)}to{transform:translateY(0)}}</style>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
            <span style="font-weight:700;font-size:14px">📍 Izin Lokasi Diperlukan</span>
            <button onclick="this.closest('#gpsBanner').remove()" style="background:rgba(255,255,255,.15);border:none;color:#fff;width:28px;height:28px;border-radius:50%;cursor:pointer;font-size:16px">✕</button>
        </div>
        <div style="font-size:13px;color:rgba(255,255,255,.7);margin-bottom:6px">Cara mengaktifkan izin lokasi:</div>
        ${panduan}
        <button onclick="location.reload()" style="margin-top:14px;width:100%;padding:11px;background:#0f4c81;color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer">🔄 Sudah diaktifkan — Refresh</button>`;
    document.body.appendChild(banner);
}

if (location.protocol === 'http:' && !['localhost','127.0.0.1'].includes(location.hostname)) {
    const warn = document.createElement('div');
    warn.style.cssText = 'background:#fffbeb;border:1px solid #f59e0b;border-left:4px solid #f59e0b;border-radius:8px;padding:10px 14px;margin-bottom:12px;font-size:13px;color:#92400e';
    warn.innerHTML = '⚠️ <strong>GPS membutuhkan HTTPS.</strong> Gunakan HTTPS untuk fitur GPS berjalan normal.';
    document.querySelector('.card')?.insertAdjacentElement('beforebegin', warn);
}

startGPS();

/* ── Step helpers ── */
function showStep(n) {
    ['step1','step2','step3'].forEach((id,i) => document.getElementById(id).classList.toggle('active', i+1===n));
}

function buildInfoHTML() {
    const n   = new Date();
    const jam = String(n.getHours()).padStart(2,'0')+':'+String(n.getMinutes()).padStart(2,'0')+':'+String(n.getSeconds()).padStart(2,'0');
    const lokasiInfo = lokasiMatch
        ? `<div style="padding:10px 14px;background:var(--surface2);border-radius:8px;display:flex;justify-content:space-between"><span style="font-size:13px;color:var(--text-muted)"><i class="fas fa-building"></i> Lokasi</span><span style="font-size:13px;font-weight:700;color:#16a34a">${lokasiMatch.nama} (${lokasiMatch.jarak}m)</span></div>`
        : '';
    return `
        <div style="padding:10px 14px;background:var(--surface2);border-radius:8px;display:flex;justify-content:space-between;align-items:center">
            <span style="font-size:13px;color:var(--text-muted)"><i class="fas fa-clock"></i> Waktu</span>
            <span style="font-family:'JetBrains Mono',monospace;font-size:20px;font-weight:700">${jam}</span>
        </div>
        <div style="padding:10px 14px;background:var(--surface2);border-radius:8px;display:flex;justify-content:space-between">
            <span style="font-size:13px;color:var(--text-muted)"><i class="fas fa-location-dot"></i> Koordinat</span>
            <span style="font-size:12px;font-family:'JetBrains Mono',monospace">${myLat.toFixed(6)}, ${myLng.toFixed(6)}</span>
        </div>
        ${lokasiInfo}`;
}

function startAbsen(type) {
    if (!myLat||!myLng) { alert('GPS belum terdeteksi. Tunggu sebentar.'); return; }
    absenType = type; photoDataURL = null;
    document.getElementById('modalTitle').textContent = type==='masuk' ? '🟢 Absen Masuk' : '🟡 Absen Keluar';
    const info = buildInfoHTML();
    document.getElementById('gpsInfo').innerHTML  = info;
    document.getElementById('finalInfo').innerHTML = info;
    showStep(1);
    document.getElementById('modalAbsen').classList.add('open');
}

function closeAbsenModal() {
    document.getElementById('modalAbsen').classList.remove('open');
    stopCamera(); showStep(1);
}

async function goToStep2() {
    showStep(2);
    document.getElementById('cameraError').style.display = 'none';
    try {
        cameraStream = await navigator.mediaDevices.getUserMedia({ video:{facingMode:'user',width:{ideal:640},height:{ideal:480}}, audio:false });
        document.getElementById('videoEl').srcObject = cameraStream;
    } catch(e) {
        document.getElementById('cameraError').style.display = 'block';
    }
}

function stopCamera() {
    if(cameraStream){ cameraStream.getTracks().forEach(t=>t.stop()); cameraStream=null; }
    const v = document.getElementById('videoEl'); if(v) v.srcObject = null;
}

function backToStep1() { stopCamera(); showStep(1); }

function capturePhoto() {
    const video = document.getElementById('videoEl'), canvas = document.getElementById('canvasEl');
    if (!video.srcObject) { alert('Kamera belum siap.'); return; }
    const MAX_W = 480;
    const ratio = Math.min(1, MAX_W / (video.videoWidth || 640));
    canvas.width  = Math.round((video.videoWidth  || 640) * ratio);
    canvas.height = Math.round((video.videoHeight || 480) * ratio);
    const ctx = canvas.getContext('2d');
    ctx.translate(canvas.width, 0); ctx.scale(-1, 1);
    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
    photoDataURL = canvas.toDataURL('image/jpeg', 0.65);
    document.getElementById('selfiePreview').src = photoDataURL;
    stopCamera(); showStep(3);
}

function retakePhoto() { photoDataURL = null; goToStep2(); }

// ── Modal Notifikasi Hasil Absen ──────────────────────────────
function showNotifAbsen(data) {
    // Hapus notif lama jika ada
    const old = document.getElementById('notifAbsenOverlay');
    if (old) old.remove();

    const notif   = data.notif || {};
    const color   = notif.color || '#10b981';
    const bg      = notif.bg    || '#ecfdf5';
    const icon    = notif.icon  || '✅';
    const title   = notif.title || 'Berhasil';
    const detail  = notif.detail || '';
    const warning = data.warning || '';
    const lupaM   = data.lupa_absen_masuk || false;

    // Badge info tambahan
    let extraBadges = '';
    if (lupaM) {
        extraBadges += `<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:8px 12px;font-size:12.5px;color:#991b1b;display:flex;gap:8px;align-items:center;margin-bottom:8px">
            <i class="fas fa-triangle-exclamation"></i>
            <span>Absen masuk tidak tercatat — status hari ini: <strong>Absen</strong></span>
        </div>`;
    }
    if (warning && !lupaM) {
        extraBadges += `<div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:8px 12px;font-size:12.5px;color:#92400e;display:flex;gap:8px;align-items:center;margin-bottom:8px">
            <i class="fas fa-shield-halved"></i>
            <span>${warning}</span>
        </div>`;
    }

    const overlay = document.createElement('div');
    overlay.id    = 'notifAbsenOverlay';
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.55);backdrop-filter:blur(4px);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px;animation:fadeIn .25s ease';
    overlay.innerHTML = `
        <style>@keyframes fadeIn{from{opacity:0;transform:scale(.92)}to{opacity:1;transform:scale(1)}}</style>
        <div style="background:#fff;border-radius:20px;box-shadow:0 24px 64px rgba(0,0,0,.3);width:100%;max-width:380px;overflow:hidden">
            <!-- Header berwarna -->
            <div style="background:${bg};padding:28px 24px 20px;text-align:center;border-bottom:1px solid ${color}22">
                <div style="font-size:3rem;margin-bottom:8px;line-height:1">${icon}</div>
                <div style="font-size:18px;font-weight:800;color:#0f172a;margin-bottom:4px">${title}</div>
                <div style="font-size:12.5px;color:#64748b;line-height:1.6">${detail}</div>
            </div>
            <!-- Body -->
            <div style="padding:16px 20px">
                ${extraBadges}
                <!-- Info lokasi -->
                <div style="background:#f8fafc;border-radius:8px;padding:10px 14px;font-size:12.5px;color:#64748b;display:flex;gap:8px;align-items:center;margin-bottom:16px">
                    <i class="fas fa-map-marker-alt" style="color:${color}"></i>
                    <span>Lokasi: <strong>${data.lokasi || '-'}</strong></span>
                    <span style="margin-left:auto;font-family:'JetBrains Mono',monospace;font-weight:700;color:#0f172a">${data.jam_absen || ''}</span>
                </div>
                <!-- Tombol -->
                <button onclick="tutupNotifAbsen()" style="width:100%;padding:13px;background:linear-gradient(135deg,#0f4c81,#0a2d55);color:#fff;border:none;border-radius:12px;font-size:15px;font-weight:700;cursor:pointer;font-family:inherit;display:flex;align-items:center;justify-content:center;gap:8px">
                    <i class="fas fa-check"></i> OK, Tutup
                </button>
            </div>
        </div>`;
    document.body.appendChild(overlay);
}

function tutupNotifAbsen() {
    const el = document.getElementById('notifAbsenOverlay');
    if (el) el.remove();
    location.reload();
}

// ── Modal Notifikasi Gagal ────────────────────────────────────
function showNotifGagal(message) {
    const old = document.getElementById('notifAbsenOverlay');
    if (old) old.remove();

    const overlay = document.createElement('div');
    overlay.id    = 'notifAbsenOverlay';
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.55);backdrop-filter:blur(4px);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px;animation:fadeIn .25s ease';
    overlay.innerHTML = `
        <style>@keyframes fadeIn{from{opacity:0;transform:scale(.92)}to{opacity:1;transform:scale(1)}}</style>
        <div style="background:#fff;border-radius:20px;box-shadow:0 24px 64px rgba(0,0,0,.3);width:100%;max-width:360px;overflow:hidden">
            <div style="background:#fef2f2;padding:28px 24px 20px;text-align:center;border-bottom:1px solid #fecaca">
                <div style="font-size:3rem;margin-bottom:8px">❌</div>
                <div style="font-size:17px;font-weight:800;color:#991b1b;margin-bottom:6px">Absen Gagal</div>
                <div style="font-size:13px;color:#7f1d1d;line-height:1.6">${message}</div>
            </div>
            <div style="padding:16px 20px">
                <button onclick="document.getElementById('notifAbsenOverlay').remove()" style="width:100%;padding:13px;background:#ef4444;color:#fff;border:none;border-radius:12px;font-size:15px;font-weight:700;cursor:pointer;font-family:inherit">
                    Tutup & Coba Lagi
                </button>
            </div>
        </div>`;
    document.body.appendChild(overlay);
}

function submitAbsen() {
    if (!photoDataURL) { alert('Foto wajah belum diambil.'); return; }
    const btn = document.getElementById('btnSubmit');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menyimpan...';

    fetch('../api/absen.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            type:      absenType,
            lat:       myLat,
            lng:       myLng,
            foto:      photoDataURL,
            accuracy:  myAccuracy,
            gps_age:   myGpsUpdatedAt > 0 ? Math.round((Date.now() - myGpsUpdatedAt) / 1000) : 0,
            speed:     mySpeed,
            lokasi_id: lokasiMatch ? lokasiMatch.id   : null,
            lokasi_nama: lokasiMatch ? lokasiMatch.nama : null
        })
    })
    .then(r => {
        const ct = r.headers.get('content-type') || '';
        if (!ct.includes('application/json')) {
            return r.text().then(txt => { throw new Error('Server error: ' + txt.substring(0, 300)); });
        }
        return r.json();
    })
    .then(data => {
        closeAbsenModal();
        if (data.success) {
            showNotifAbsen(data);
        } else {
            showNotifGagal(data.message || 'Terjadi kesalahan. Coba lagi.');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check"></i> Konfirmasi Absen';
        }
    })
    .catch(err => {
        console.error('Absen error:', err);
        closeAbsenModal();
        showNotifGagal(err.message || 'Gagal menghubungi server. Periksa koneksi internet.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check"></i> Konfirmasi Absen';
    });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>