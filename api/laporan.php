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
    echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit;
}

$db   = getDB();
$user = currentUser();

$type       = trim($_POST['type']       ?? '');
$tanggal    = trim($_POST['tanggal']    ?? '');
$keterangan = trim($_POST['keterangan'] ?? '');

if (!in_array($type, ['dinas', 'sakit', 'izin'])) {
    echo json_encode(['success' => false, 'message' => 'Tipe laporan tidak valid.']); exit;
}
if (!$tanggal || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
    echo json_encode(['success' => false, 'message' => 'Tanggal tidak valid.']); exit;
}
if (empty($keterangan)) {
    echo json_encode(['success' => false, 'message' => 'Keterangan wajib diisi.']); exit;
}

// ── Upload bukti (opsional untuk sakit & izin, wajib untuk dinas) ──
$buktiPath = null;
if (isset($_FILES['bukti']) && $_FILES['bukti']['error'] === UPLOAD_ERR_OK) {
    $file    = $_FILES['bukti'];
    $maxSize = 5 * 1024 * 1024;
    $allowed = ['image/jpeg','image/png','image/jpg','application/pdf'];
    $mime    = mime_content_type($file['tmp_name']);

    if ($file['size'] > $maxSize) {
        echo json_encode(['success' => false, 'message' => 'Ukuran file maksimal 5MB.']); exit;
    }
    if (!in_array($mime, $allowed)) {
        echo json_encode(['success' => false, 'message' => 'Format file harus JPG, PNG, atau PDF.']); exit;
    }

    $ext       = match($mime) { 'image/png' => 'png', 'application/pdf' => 'pdf', default => 'jpg' };
    $uploadDir = __DIR__ . '/../uploads/laporan/' . $type . '/' . date('Y/m/') . $user['id'] . '/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $fileName  = $type . '_' . date('Ymd_His') . '_' . uniqid() . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $uploadDir . $fileName)) {
        echo json_encode(['success' => false, 'message' => 'Gagal menyimpan file.']); exit;
    }
    $buktiPath = 'uploads/laporan/' . $type . '/' . date('Y/m/') . $user['id'] . '/' . $fileName;
}

// ── Status & keterangan per tipe ──
$statusKehadiran = match($type) {
    'dinas' => 'dinas_luar',
    'sakit' => 'sakit',
    'izin'  => 'izin',
};

$extraKet = $keterangan;

if ($type === 'dinas') {
    $tujuan = trim($_POST['tujuan'] ?? '');
    if (empty($tujuan)) {
        echo json_encode(['success' => false, 'message' => 'Tujuan dinas wajib diisi.']); exit;
    }
    if (!$buktiPath) {
        echo json_encode(['success' => false, 'message' => 'Surat tugas wajib diupload untuk dinas luar.']); exit;
    }
    $extraKet = "Tujuan: {$tujuan} | {$keterangan}";
}

if ($type === 'izin') {
    $jenis = trim($_POST['jenis'] ?? '');
    if (empty($jenis)) {
        echo json_encode(['success' => false, 'message' => 'Jenis izin wajib dipilih.']); exit;
    }
    $jenisLabel = match($jenis) {
        'keluarga' => 'Urusan Keluarga',
        'pribadi'  => 'Keperluan Pribadi',
        'darurat'  => 'Kondisi Darurat',
        default    => 'Lainnya',
    };
    $extraKet = "Jenis: {$jenisLabel} | {$keterangan}";
}
// Sakit: hanya keterangan, tanpa durasi

// ── Cek absensi tanggal ini ──
$stmtCek = $db->prepare("SELECT id FROM absensi WHERE karyawan_id=? AND tanggal=?");
$stmtCek->execute([$user['id'], $tanggal]);
$absenHari = $stmtCek->fetch();

// ── Jadwal aktif untuk tanggal tersebut ──
$hariTanggal = (int)date('N', strtotime($tanggal));
$stmtJadwal  = $db->prepare("
    SELECT jk.*, j.hari_kerja, j.id as jadwal_real_id,
           s.id as shift_id
    FROM jadwal_karyawan jk
    JOIN jadwal j ON j.id = jk.jadwal_id
    JOIN shift s  ON s.id = j.shift_id
    WHERE jk.karyawan_id = ?
      AND jk.berlaku_dari <= ?
      AND (jk.berlaku_sampai IS NULL OR jk.berlaku_sampai >= ?)
    ORDER BY s.jam_masuk ASC
");
$stmtJadwal->execute([$user['id'], $tanggal, $tanggal]);
$semuaJadwal = $stmtJadwal->fetchAll();
$jadwal = null;
foreach ($semuaJadwal as $j) {
    if (in_array($hariTanggal, json_decode($j['hari_kerja'], true) ?? [])) {
        $jadwal = $j; break;
    }
}

// ── Simpan ke DB ──
try {
    if ($absenHari) {
        // Update record yang ada, kosongkan waktu masuk/keluar
        $db->prepare("
            UPDATE absensi
            SET status_kehadiran  = ?,
                keterangan        = ?,
                bukti_file        = ?,
                waktu_masuk       = NULL,
                waktu_keluar      = NULL,
                terlambat_detik   = 0,
                pulang_cepat_detik= 0,
                durasi_kerja      = NULL
            WHERE id = ?
        ")->execute([$statusKehadiran, $extraKet, $buktiPath, $absenHari['id']]);
    } else {
        if ($jadwal) {
            $db->prepare("
                INSERT INTO absensi
                    (karyawan_id, jadwal_id, shift_id, tanggal, status_kehadiran,
                     terlambat_detik, pulang_cepat_detik, keterangan, bukti_file,
                     device_info, ip_address)
                VALUES (?,?,?,?,?,0,0,?,?,?,?)
            ")->execute([
                $user['id'], $jadwal['jadwal_real_id'], $jadwal['shift_id'],
                $tanggal, $statusKehadiran, $extraKet, $buktiPath,
                $_SERVER['HTTP_USER_AGENT'] ?? '', $_SERVER['REMOTE_ADDR'] ?? '',
            ]);
        } else {
            $db->prepare("
                INSERT INTO absensi
                    (karyawan_id, tanggal, status_kehadiran,
                     terlambat_detik, pulang_cepat_detik, keterangan, bukti_file,
                     device_info, ip_address)
                VALUES (?,?,?,0,0,?,?,?,?)
            ")->execute([
                $user['id'], $tanggal, $statusKehadiran, $extraKet, $buktiPath,
                $_SERVER['HTTP_USER_AGENT'] ?? '', $_SERVER['REMOTE_ADDR'] ?? '',
            ]);
        }
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Gagal menyimpan: ' . $e->getMessage()]); exit;
}

logActivity('LAPORAN_' . strtoupper($type), strtoupper($type) . " tgl:{$tanggal}");

$bulanId = ['','Januari','Februari','Maret','April','Mei','Juni',
            'Juli','Agustus','September','Oktober','November','Desember'];
$tglFmt  = date('d', strtotime($tanggal)) . ' '
         . $bulanId[(int)date('n', strtotime($tanggal))] . ' '
         . date('Y', strtotime($tanggal));

echo json_encode([
    'success' => true,
    'message' => ucfirst($type === 'dinas' ? 'Dinas Luar' : $type) . ' berhasil dicatat.',
    'tanggal' => $tglFmt,
    'status'  => $statusKehadiran,
    'type'    => $type,
]);