<?php
@ini_set('post_max_size', '16M');
@ini_set('upload_max_filesize', '16M');

require_once __DIR__ . '/../includes/config.php';
requireLogin();

set_exception_handler(function($e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    exit;
});

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

$input    = json_decode(file_get_contents('php://input'), true);
$type     = $input['type']     ?? '';
$lat      = (float)($input['lat']      ?? 0);
$lng      = (float)($input['lng']      ?? 0);
$foto     = $input['foto']     ?? '';
$accuracy = (float)($input['accuracy'] ?? 9999);
$gpsAge   = (float)($input['gps_age']  ?? 0);
$speed    = $input['speed']    ?? null;

if (!in_array($type, ['masuk', 'keluar']) || !$lat || !$lng) {
    jsonResponse(['success' => false, 'message' => 'Data tidak lengkap']);
}

$db    = getDB();
$user  = currentUser();
$today = date('Y-m-d');
$now   = date('Y-m-d H:i:s');

// ── LAYER 1-7 ────────────────────────────────────────────────────
if ($accuracy > 100) jsonResponse(['success'=>false,'message'=>"Akurasi GPS terlalu rendah ({$accuracy}m).",'flag'=>'low_accuracy']);
if ($gpsAge > 300)   jsonResponse(['success'=>false,'message'=>'Data GPS terlalu lama. Refresh halaman.','flag'=>'gps_stale']);
if ($speed !== null && $speed > 50) jsonResponse(['success'=>false,'message'=>'Terdeteksi pergerakan tidak wajar.','flag'=>'speed_anomaly']);

$latDec = strlen(explode('.', (string)$lat)[1] ?? '');
$lngDec = strlen(explode('.', (string)$lng)[1] ?? '');
if ($latDec < 4 || $lngDec < 4) jsonResponse(['success'=>false,'message'=>'Koordinat GPS tidak valid.','flag'=>'suspicious_coords']);

$stmtRate = $db->prepare("SELECT COUNT(*) as cnt FROM log_aktivitas WHERE karyawan_id=? AND aksi LIKE 'ABSEN%' AND created_at >= ?");
$stmtRate->execute([$user['id'], date('Y-m-d H:i:s', strtotime('-10 minutes'))]);
if ($stmtRate->fetch()['cnt'] >= 5) jsonResponse(['success'=>false,'message'=>'Terlalu banyak percobaan.','flag'=>'rate_limit']);

$ipAddr    = $_SERVER['REMOTE_ADDR'] ?? '';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$stmtIP    = $db->prepare("SELECT COUNT(DISTINCT karyawan_id) as cnt FROM absensi WHERE tanggal=? AND ip_address=? AND karyawan_id != ?");
$stmtIP->execute([$today, $ipAddr, $user['id']]);
$suspiciousIP = $stmtIP->fetch()['cnt'] >= 3;

if (!$foto || strpos($foto, 'data:image/') !== 0) jsonResponse(['success'=>false,'message'=>'Foto wajah tidak ditemukan.']);
$fotoPath = $foto;

// ── Jadwal aktif ─────────────────────────────────────────────────
$stmtJadwal = $db->prepare("
    SELECT jk.*, j.hari_kerja, j.id as jadwal_real_id,
           s.id as shift_id, s.nama as shift_nama,
           s.jam_masuk, s.jam_keluar,
           s.toleransi_terlambat_detik,
           IFNULL(s.toleransi_pulang_cepat_detik, 0) as toleransi_pulang_cepat_detik
    FROM jadwal_karyawan jk
    JOIN jadwal j ON j.id = jk.jadwal_id
    JOIN shift s  ON s.id = j.shift_id
    WHERE jk.karyawan_id = ?
      AND jk.berlaku_dari <= CURDATE()
      AND (jk.berlaku_sampai IS NULL OR jk.berlaku_sampai >= CURDATE())
    ORDER BY s.jam_masuk ASC
");
$stmtJadwal->execute([$user['id']]);
$semuaJadwal = $stmtJadwal->fetchAll();
if (empty($semuaJadwal)) jsonResponse(['success'=>false,'message'=>'Tidak ada jadwal aktif. Hubungi admin.']);

$hariIni = (int)date('N');
$jadwal  = null;
foreach ($semuaJadwal as $j) {
    if (in_array($hariIni, json_decode($j['hari_kerja'], true) ?? [])) { $jadwal = $j; break; }
}
if (!$jadwal) {
    $namaHari = ['','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu'][$hariIni];
    jsonResponse(['success'=>false,'message'=>"Tidak ada jadwal untuk hari {$namaHari}."]);
}

// ── Validasi lokasi ───────────────────────────────────────────────
$stmtLokasi = $db->prepare("
    SELECT l.id, l.nama, l.latitude, l.longitude, l.radius_meter
    FROM karyawan_lokasi kl
    JOIN lokasi l ON l.id = kl.lokasi_id
    WHERE kl.karyawan_id = ? AND l.status = 'aktif'
");
$stmtLokasi->execute([$user['id']]);
$lokasiList = $stmtLokasi->fetchAll();
if (empty($lokasiList)) jsonResponse(['success'=>false,'message'=>'Belum ada lokasi absen terdaftar. Hubungi admin.']);

$lokasiMatch   = null;
$jarakTerdekat = PHP_INT_MAX;
$namaTerdekat  = '';
foreach ($lokasiList as $l) {
    $jj = jarakDuaTitik($lat, $lng, (float)$l['latitude'], (float)$l['longitude']);
    if ($jj < $jarakTerdekat) { $jarakTerdekat = $jj; $namaTerdekat = $l['nama']; }
    if ($jj <= (int)$l['radius_meter']) {
        if (!$lokasiMatch || $jj < $lokasiMatch['jarak'])
            $lokasiMatch = ['id'=>$l['id'],'nama'=>$l['nama'],'jarak'=>$jj,'radius'=>$l['radius_meter']];
    }
}
if (!$lokasiMatch) {
    logActivity('ABSEN_GAGAL', "Diluar radius: {$jarakTerdekat}m ({$namaTerdekat}), acc:{$accuracy}m");
    jsonResponse(['success'=>false,'message'=>"❌ Anda berada {$jarakTerdekat}m dari lokasi terdekat ({$namaTerdekat}).","flag"=>'out_of_radius']);
}
$jarak    = $lokasiMatch['jarak'];
$lokasiId = $lokasiMatch['id'];

// ── Anomali historis ──────────────────────────────────────────────
$stmtHist = $db->prepare("SELECT lat_masuk, lng_masuk FROM absensi WHERE karyawan_id=? AND lat_masuk IS NOT NULL ORDER BY tanggal DESC LIMIT 10");
$stmtHist->execute([$user['id']]);
$hist = $stmtHist->fetchAll();
$locationSuspicious = false;
if (count($hist) >= 3) {
    $aLat = array_sum(array_column($hist,'lat_masuk')) / count($hist);
    $aLng = array_sum(array_column($hist,'lng_masuk')) / count($hist);
    if (jarakDuaTitik($lat, $lng, $aLat, $aLng) > 500) $locationSuspicious = true;
}

$fraudFlags = [];
if ($suspiciousIP)       $fraudFlags[] = 'shared_ip';
if ($locationSuspicious) $fraudFlags[] = 'location_anomaly';
if ($accuracy > 50)      $fraudFlags[] = 'low_accuracy';
$fraudFlag = !empty($fraudFlags) ? implode(',', $fraudFlags) : null;

// ── Cek absensi hari ini ──────────────────────────────────────────
$stmtCek = $db->prepare("SELECT * FROM absensi WHERE karyawan_id=? AND tanggal=?");
$stmtCek->execute([$user['id'], $today]);
$absenToday = $stmtCek->fetch();

// ── Helpers ───────────────────────────────────────────────────────
function hitungTerlambat($jamShift, $toleransiDetik, $waktuAbsen) {
    $tgl      = date('Y-m-d', strtotime($waktuAbsen));
    $tsShift  = strtotime($tgl . ' ' . $jamShift);
    $tsAbsen  = strtotime($waktuAbsen);
    $batas    = $tsShift + (int)$toleransiDetik;
    if ($tsAbsen <= $batas) return 0;
    return $tsAbsen - $tsShift;
}

function hitungPulangCepat($jamKeluar, $toleransiDetik, $waktuKeluar) {
    $tgl      = date('Y-m-d', strtotime($waktuKeluar));
    $tsShift  = strtotime($tgl . ' ' . $jamKeluar);
    $tsKeluar = strtotime($waktuKeluar);
    if ($tsKeluar >= $tsShift) return 0;
    $selisih  = $tsShift - $tsKeluar;
    if ($selisih <= (int)$toleransiDetik) return 0;
    return $selisih;
}

function fmtDetik($detik) {
    if ($detik <= 0) return '-';
    $j = floor($detik/3600); $m = floor(($detik%3600)/60); $s = $detik%60;
    $p = [];
    if ($j) $p[] = $j.' jam';
    if ($m) $p[] = $m.' menit';
    if ($s) $p[] = $s.' detik';
    return implode(' ', $p);
}

// ════════════════════════════════════════════════════════════════
// ABSEN MASUK
// ════════════════════════════════════════════════════════════════
if ($type === 'masuk') {
    if ($absenToday && $absenToday['waktu_masuk']) jsonResponse(['success'=>false,'message'=>'Anda sudah absen masuk hari ini.']);

    $terlambatDetik  = hitungTerlambat($jadwal['jam_masuk'], $jadwal['toleransi_terlambat_detik'], $now);
    $statusKehadiran = $terlambatDetik > 0 ? 'terlambat' : 'hadir';
    $keterangan      = "lokasi:{$lokasiMatch['nama']}, acc:{$accuracy}m" . ($fraudFlag ? " [FLAG:{$fraudFlag}]" : '');

    if ($absenToday) {
        $db->prepare("UPDATE absensi SET waktu_masuk=?,lat_masuk=?,lng_masuk=?,jarak_masuk=?,lokasi_id=?,status_kehadiran=?,terlambat_detik=?,foto_masuk=?,device_info=?,ip_address=?,keterangan=? WHERE id=?")
           ->execute([$now,$lat,$lng,$jarak,$lokasiId,$statusKehadiran,$terlambatDetik,$fotoPath,$userAgent,$ipAddr,$keterangan,$absenToday['id']]);
    } else {
        $db->prepare("INSERT INTO absensi (karyawan_id,jadwal_id,shift_id,tanggal,waktu_masuk,lat_masuk,lng_masuk,jarak_masuk,lokasi_id,status_kehadiran,terlambat_detik,pulang_cepat_detik,foto_masuk,device_info,ip_address,keterangan) VALUES (?,?,?,?,?,?,?,?,?,?,?,0,?,?,?,?)")
           ->execute([$user['id'],$jadwal['jadwal_real_id'],$jadwal['shift_id'],$today,$now,$lat,$lng,$jarak,$lokasiId,$statusKehadiran,$terlambatDetik,$fotoPath,$userAgent,$ipAddr,$keterangan]);
    }

    $jamAbsen = date('H:i', strtotime($now));
    $jamShift = substr($jadwal['jam_masuk'], 0, 5);
    $tolStr   = fmtDetik($jadwal['toleransi_terlambat_detik']);

    if ($terlambatDetik > 0) {
        $dur = fmtDetik($terlambatDetik);
        $notif = ['type'=>'terlambat','icon'=>'⏰','title'=>"Terlambat {$dur}",
            'detail'=>"Jadwal masuk: {$jamShift}" . ($jadwal['toleransi_terlambat_detik']>0?" (toleransi: {$tolStr})":'') . " · Anda masuk: {$jamAbsen} · Terlambat: {$dur}",
            'color'=>'#f59e0b','bg'=>'#fffbeb'];
        $msg = "⏰ Absen masuk pukul {$jamAbsen} — terlambat {$dur}";
    } else {
        $notif = ['type'=>'sukses','icon'=>'✅','title'=>'Absen Masuk Berhasil',
            'detail'=>"Tepat waktu! Jadwal masuk: {$jamShift}" . ($jadwal['toleransi_terlambat_detik']>0?" (toleransi: {$tolStr})":'') . " · Anda masuk: {$jamAbsen}",
            'color'=>'#10b981','bg'=>'#ecfdf5'];
        $msg = "✅ Absen masuk berhasil pukul {$jamAbsen}";
    }

    logActivity('ABSEN_MASUK', "Masuk {$now}, lokasi:{$lokasiMatch['nama']}, jarak:{$jarak}m, terlambat:{$terlambatDetik}s, acc:{$accuracy}m" . ($fraudFlag?", FLAG:{$fraudFlag}":''));

    $resp = ['success'=>true,'message'=>$msg,'notif'=>$notif,'terlambat'=>$terlambatDetik,'status'=>$statusKehadiran,'lokasi'=>$lokasiMatch['nama'],'jam_absen'=>$jamAbsen,'jam_shift'=>$jamShift];
    if ($fraudFlag) $resp['warning'] = 'Absensi tercatat namun terdeteksi anomali lokasi.';
    jsonResponse($resp);

// ════════════════════════════════════════════════════════════════
// ABSEN KELUAR
// ════════════════════════════════════════════════════════════════
} else {

    $lupaAbsenMasuk = false;
    if (!$absenToday) {
        $db->prepare("INSERT INTO absensi (karyawan_id,jadwal_id,shift_id,tanggal,lokasi_id,status_kehadiran,terlambat_detik,pulang_cepat_detik,device_info,ip_address,keterangan) VALUES (?,?,?,?,?,?,0,0,?,?,?)")
           ->execute([$user['id'],$jadwal['jadwal_real_id'],$jadwal['shift_id'],$today,$lokasiId,'absen',$userAgent,$ipAddr,'LUPA_ABSEN_MASUK']);
        $newId      = (int)$db->lastInsertId();
        $absenToday = ['id'=>$newId,'waktu_masuk'=>null,'waktu_keluar'=>null,'status_kehadiran'=>'absen'];
        $lupaAbsenMasuk = true;
    } elseif (!$absenToday['waktu_masuk']) {
        $db->prepare("UPDATE absensi SET status_kehadiran='absen', keterangan=CONCAT(IFNULL(keterangan,''),' LUPA_ABSEN_MASUK') WHERE id=?")->execute([$absenToday['id']]);
        $lupaAbsenMasuk = true;
    }

    if (!empty($absenToday['waktu_keluar'])) jsonResponse(['success'=>false,'message'=>'Anda sudah absen keluar hari ini.']);

    $durasi = 0;
    if (!empty($absenToday['waktu_masuk']))
        $durasi = round((strtotime($now) - strtotime($absenToday['waktu_masuk'])) / 60);

    $tolPulang        = (int)$jadwal['toleransi_pulang_cepat_detik'];
    $pulangCepatDetik = hitungPulangCepat($jadwal['jam_keluar'], $tolPulang, $now);
    $jamKeluar        = date('H:i', strtotime($now));
    $jamKeluarShift   = substr($jadwal['jam_keluar'], 0, 5);
    $tolPulangStr     = fmtDetik($tolPulang);

    $keterangan = "lokasi:{$lokasiMatch['nama']}, acc:{$accuracy}m" . ($fraudFlag?" [FLAG:{$fraudFlag}]":'');
    if ($lupaAbsenMasuk)       $keterangan .= ' | LUPA_ABSEN_MASUK';
    if ($pulangCepatDetik > 0) $keterangan .= " | PULANG_CEPAT:{$pulangCepatDetik}s";

    // Simpan pulang_cepat_detik ke DB
    $db->prepare("UPDATE absensi SET waktu_keluar=?,lat_keluar=?,lng_keluar=?,jarak_keluar=?,durasi_kerja=?,pulang_cepat_detik=?,foto_keluar=?,keterangan=CONCAT(IFNULL(keterangan,''),' | keluar: ',?) WHERE id=?")
       ->execute([$now,$lat,$lng,$jarak,$durasi,$pulangCepatDetik,$fotoPath,$keterangan,$absenToday['id']]);

    if ($pulangCepatDetik > 0) {
        $durPulang = fmtDetik($pulangCepatDetik);
        $notif = ['type'=>'pulang_cepat','icon'=>'🏃','title'=>"Pulang Lebih Awal {$durPulang}",
            'detail'=>"Jadwal keluar: {$jamKeluarShift}" . ($tolPulang>0?" (toleransi: {$tolPulangStr})":'') . " · Anda keluar: {$jamKeluar} · Lebih awal: {$durPulang}" . ($durasi>0?" · Durasi kerja: ".formatDurasi($durasi):''),
            'color'=>'#8b5cf6','bg'=>'#faf5ff'];
        $msg = "🏃 Absen keluar pukul {$jamKeluar} — pulang lebih awal {$durPulang}";
    } else {
        $notif = ['type'=>'sukses','icon'=>'✅','title'=>'Absen Keluar Berhasil',
            'detail'=>"Jadwal keluar: {$jamKeluarShift}" . ($tolPulang>0?" (toleransi: {$tolPulangStr})":'') . " · Keluar: {$jamKeluar}" . ($durasi>0?" · Durasi kerja: ".formatDurasi($durasi):''),
            'color'=>'#10b981','bg'=>'#ecfdf5'];
        $msg = "✅ Absen keluar berhasil pukul {$jamKeluar}" . ($durasi>0?" — Durasi: ".formatDurasi($durasi):'');
    }

    logActivity('ABSEN_KELUAR', "Keluar {$now}, lokasi:{$lokasiMatch['nama']}, durasi:{$durasi}m, pulang_cepat:{$pulangCepatDetik}s, acc:{$accuracy}m" . ($fraudFlag?", FLAG:{$fraudFlag}":''));

    $resp = ['success'=>true,'message'=>$msg,'notif'=>$notif,'durasi'=>$durasi,'pulang_cepat'=>$pulangCepatDetik,'lupa_absen_masuk'=>$lupaAbsenMasuk,'lokasi'=>$lokasiMatch['nama'],'jam_absen'=>$jamKeluar,'jam_shift'=>$jamKeluarShift];
    $warnings = [];
    if ($lupaAbsenMasuk) $warnings[] = '⚠️ Absen masuk tidak tercatat — status ditandai Absen.';
    if ($fraudFlag)      $warnings[] = 'Terdeteksi anomali lokasi.';
    if (!empty($warnings)) $resp['warning'] = implode(' ', $warnings);
    jsonResponse($resp);
}