<?php
require_once __DIR__ . '/../includes/config.php';
requireAdmin();

$pageTitle  = 'Pengaturan SMTP Gmail';
$activePage = 'smtp_gmail';
$user       = currentUser();
$db         = getDB();

// ── Buat tabel global (tanpa perusahaan_id) ──
$db->exec("CREATE TABLE IF NOT EXISTS smtp_settings (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    host        VARCHAR(100)                    NOT NULL DEFAULT 'smtp.gmail.com',
    port        INT                             NOT NULL DEFAULT 587,
    encryption  ENUM('tls','ssl','none')        NOT NULL DEFAULT 'tls',
    username    VARCHAR(150)                    NOT NULL DEFAULT '',
    password    VARCHAR(255)                    NOT NULL DEFAULT '',
    from_email  VARCHAR(150)                    NOT NULL DEFAULT '',
    from_name   VARCHAR(100)                    NOT NULL DEFAULT '',
    is_active   TINYINT(1)                      NOT NULL DEFAULT 1,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// Pastikan selalu ada 1 baris (id = 1)
$check = $db->query("SELECT COUNT(*) FROM smtp_settings")->fetchColumn();
if ((int)$check === 0) {
    $db->exec("INSERT INTO smtp_settings (id) VALUES (1)");
}

$errors  = [];
$success = false;

// Ambil baris tunggal
$smtp = $db->query("SELECT * FROM smtp_settings WHERE id=1 LIMIT 1")->fetch();

// ── SIMPAN ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $host       = sanitize($_POST['host']       ?? 'smtp.gmail.com');
        $port       = (int)($_POST['port']          ?? 587);
        $encryption = $_POST['encryption']           ?? 'tls';
        $username   = sanitize($_POST['username']   ?? '');
        $password   = $_POST['password']             ?? '';
        $from_email = sanitize($_POST['from_email'] ?? '');
        $from_name  = sanitize($_POST['from_name']  ?? '');
        $is_active  = isset($_POST['is_active']) ? 1 : 0;

        if (!$username)   $errors[] = 'Email Gmail wajib diisi.';
        if (!$from_email) $errors[] = 'Email pengirim wajib diisi.';

        // Password: kosong = pakai yang lama
        if (empty($password) && $smtp) {
            $password = $smtp['password'];
        } elseif (!empty($password)) {
            $password = base64_encode($password);
        }

        if (empty($errors)) {
            $db->prepare("UPDATE smtp_settings SET
                host=?, port=?, encryption=?, username=?, password=?,
                from_email=?, from_name=?, is_active=?
                WHERE id=1"
            )->execute([$host, $port, $encryption, $username, $password,
                        $from_email, $from_name, $is_active]);

            $smtp    = $db->query("SELECT * FROM smtp_settings WHERE id=1 LIMIT 1")->fetch();
            $success = true;
        }
    }

    // ── TEST ──
    if ($action === 'test') {
        $to = sanitize($_POST['test_email'] ?? '');
        if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email tujuan test tidak valid.';
        } else {
            $result = sendTestEmail($db, $to);
            if ($result === true) {
                redirect(APP_URL . '/pages/smtp_gmail.php',
                    "✅ Email test berhasil dikirim ke $to!", 'success');
            } else {
                $errors[] = 'Gagal kirim: ' . $result;
            }
        }
    }
}

// ── Fungsi kirim test ──
function sendTestEmail($db, $to) {
    $s = $db->query("SELECT * FROM smtp_settings WHERE id=1 AND is_active=1 LIMIT 1")->fetch();
    if (!$s) return 'Konfigurasi SMTP belum disimpan atau tidak aktif.';

    $pw = base64_decode($s['password']);

    $phpmailerPaths = [
        __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php',
        __DIR__ . '/../phpmailer/src/PHPMailer.php',
        __DIR__ . '/../PHPMailer/src/PHPMailer.php',
    ];
    $found = false;
    foreach ($phpmailerPaths as $p) {
        if (file_exists($p)) {
            require_once $p;
            require_once dirname($p) . '/SMTP.php';
            require_once dirname($p) . '/Exception.php';
            $found = true; break;
        }
    }

    if ($found) {
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = $s['host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $s['username'];
            $mail->Password   = $pw;
            $mail->SMTPSecure = $s['encryption'] === 'ssl'
                ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port    = $s['port'];
            $mail->CharSet = 'UTF-8';
            $mail->setFrom($s['from_email'], $s['from_name']);
            $mail->addAddress($to);
            $mail->Subject = 'Test Email — DailyFix';
            $mail->isHTML(true);
            $mail->Body = '
            <div style="font-family:sans-serif;max-width:480px;margin:0 auto;padding:24px;
                        border:1px solid #e2e8f0;border-radius:12px">
                <div style="background:#0f4c81;color:#fff;padding:16px 20px;border-radius:8px;margin-bottom:16px">
                    <strong style="font-size:20px">D</strong> &nbsp; <strong>DailyFix</strong>
                </div>
                <h2 style="color:#0f4c81">✅ Konfigurasi SMTP Berhasil!</h2>
                <p style="color:#64748b;margin-top:8px">Email ini membuktikan konfigurasi SMTP Gmail di DailyFix berjalan dengan baik.</p>
                <hr style="margin:16px 0;border-color:#e2e8f0">
                <p style="font-size:12px;color:#94a3b8">
                    Dikirim otomatis oleh DailyFix &mdash; ' . date('d/m/Y H:i:s') . '
                </p>
            </div>';
            $mail->send();
            return true;
        } catch (Exception $e) {
            return $e->getMessage();
        }
    } else {
        // Fallback mail()
        $headers  = "From: {$s['from_name']} <{$s['from_email']}>\r\n";
        $headers .= "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n";
        $ok = mail($to, 'Test Email — DailyFix',
            "Test email dari DailyFix.\nDikirim: " . date('d/m/Y H:i:s'), $headers);
        return $ok ? true : 'Fungsi mail() gagal. Pastikan server mendukung pengiriman email.';
    }
}

// Cek PHPMailer tersedia
$phpmailerPaths = [
    __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php',
    __DIR__ . '/../phpmailer/src/PHPMailer.php',
    __DIR__ . '/../PHPMailer/src/PHPMailer.php',
];
$phpmailerFound = false;
foreach ($phpmailerPaths as $p) { if (file_exists($p)) { $phpmailerFound = true; break; } }

include __DIR__ . '/../includes/header.php';
?>

<style>
.smtp-grid { display:grid; grid-template-columns:1fr 360px; gap:20px; align-items:start; }
@media(max-width:900px) { .smtp-grid { grid-template-columns:1fr; } }

.ss { background:#fff; border-radius:12px; border:1px solid var(--border); overflow:hidden; margin-bottom:16px; }
.ss-head {
    padding:13px 18px; background:var(--surface2); border-bottom:1px solid var(--border);
    display:flex; align-items:center; gap:10px;
}
.ss-head h3 { font-size:13.5px; font-weight:800; margin:0; }
.ss-icon { width:30px; height:30px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:13px; flex-shrink:0; }
.ss-body { padding:18px; display:flex; flex-direction:column; gap:14px; }

.g2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; }

.toggle-switch { position:relative; display:inline-block; width:44px; height:24px; }
.toggle-switch input { opacity:0; width:0; height:0; }
.toggle-slider { position:absolute; cursor:pointer; inset:0; background:#e2e8f0; border-radius:24px; transition:.3s; }
.toggle-slider::before { content:''; position:absolute; height:18px; width:18px; left:3px; bottom:3px; background:#fff; border-radius:50%; transition:.3s; box-shadow:0 1px 3px rgba(0,0,0,.2); }
input:checked + .toggle-slider { background:var(--success); }
input:checked + .toggle-slider::before { transform:translateX(20px); }

.status-badge { display:inline-flex; align-items:center; gap:6px; padding:4px 12px; border-radius:20px; font-size:12px; font-weight:700; }
.status-badge.on  { background:#dcfce7; color:#16a34a; }
.status-badge.off { background:#fee2e2; color:#dc2626; }

.panduan-item { display:flex; gap:10px; align-items:flex-start; padding:9px 0; border-bottom:1px solid var(--border); font-size:13px; }
.panduan-item:last-child { border:none; padding-bottom:0; }
.panduan-num { width:22px; height:22px; border-radius:50%; background:var(--primary); color:#fff; display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:800; flex-shrink:0; margin-top:1px; }
.panduan-item a { color:var(--primary); font-weight:600; }

.pw-wrap { position:relative; }
.pw-wrap input { padding-right:40px; }
.pw-btn { position:absolute; right:10px; top:50%; transform:translateY(-50%); background:none; border:none; color:var(--text-muted); cursor:pointer; padding:4px; font-size:13px; }

.info-box { background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:11px 14px; font-size:13px; color:#1e40af; display:flex; gap:8px; align-items:flex-start; }

.code-block { background:#1e293b; color:#e2e8f0; padding:12px 14px; border-radius:8px; font-family:'JetBrains Mono',monospace; font-size:12px; overflow-x:auto; line-height:1.7; }

.cfg-row { display:flex; padding:6px 0; border-bottom:1px solid #f1f5f9; font-size:13px; }
.cfg-row:last-child { border:none; }
.cfg-key { width:130px; flex-shrink:0; color:var(--text-muted); }
.cfg-val { font-weight:700; color:#0f172a; }
</style>

<!-- PAGE HEADER -->
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <div>
        <h2><i class="fas fa-envelope-circle-check" style="color:var(--primary)"></i> Pengaturan SMTP Gmail</h2>
        <p>Konfigurasi email untuk notifikasi dan pengiriman laporan otomatis</p>
    </div>
    <?php if ($smtp): ?>
    <span class="status-badge <?= $smtp['is_active'] ? 'on' : 'off' ?>">
        <i class="fas fa-circle" style="font-size:8px"></i>
        <?= $smtp['is_active'] ? 'SMTP Aktif' : 'SMTP Nonaktif' ?>
    </span>
    <?php endif; ?>
</div>

<!-- ALERTS -->
<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <i class="fas fa-triangle-exclamation"></i>
    <ul style="margin:0;padding-left:1rem"><?php foreach($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>
<?php if ($success): ?>
<div class="alert alert-success"><i class="fas fa-check-circle"></i> Konfigurasi SMTP berhasil disimpan!</div>
<?php endif; ?>

<div class="smtp-grid">

    <!-- ══ KIRI: Form ══ -->
    <div>
        <form method="POST" id="fSmtp">
        <input type="hidden" name="action" value="save">

        <!-- Server -->
        <div class="ss">
            <div class="ss-head">
                <div class="ss-icon" style="background:#dbeafe;color:#2563eb"><i class="fas fa-server"></i></div>
                <h3>Konfigurasi Server SMTP</h3>
            </div>
            <div class="ss-body">
                <div class="form-group" style="margin:0">
                    <label class="form-label">SMTP Host <span class="req">*</span></label>
                    <input type="text" name="host" class="form-control"
                        value="<?= htmlspecialchars($smtp['host'] ?? 'smtp.gmail.com') ?>"
                        placeholder="smtp.gmail.com">
                    <div class="form-hint">Untuk Gmail: <code style="background:#f1f5f9;padding:1px 6px;border-radius:4px">smtp.gmail.com</code></div>
                </div>
                <div class="g2">
                    <div class="form-group" style="margin:0">
                        <label class="form-label">Port</label>
                        <select name="port" class="form-select" id="portSel">
                            <option value="587" <?= ($smtp['port']??587)==587?'selected':'' ?>>587 — TLS (Rekomendasi)</option>
                            <option value="465" <?= ($smtp['port']??0)==465?'selected':'' ?>>465 — SSL</option>
                            <option value="25"  <?= ($smtp['port']??0)==25?'selected':''  ?>>25 — Tidak Aman</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0">
                        <label class="form-label">Enkripsi</label>
                        <select name="encryption" class="form-select" id="encSel">
                            <option value="tls"  <?= ($smtp['encryption']??'tls')==='tls'?'selected':''  ?>>TLS (Rekomendasi)</option>
                            <option value="ssl"  <?= ($smtp['encryption']??'')==='ssl'?'selected':''  ?>>SSL</option>
                            <option value="none" <?= ($smtp['encryption']??'')==='none'?'selected':'' ?>>None</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- Akun Gmail -->
        <div class="ss">
            <div class="ss-head">
                <div class="ss-icon" style="background:#fef3c7;color:#d97706"><i class="fab fa-google"></i></div>
                <h3>Akun Gmail</h3>
            </div>
            <div class="ss-body">
                <div class="form-group" style="margin:0">
                    <label class="form-label">Email Gmail <span class="req">*</span></label>
                    <input type="email" name="username" class="form-control"
                        value="<?= htmlspecialchars($smtp['username'] ?? '') ?>"
                        placeholder="nama@gmail.com">
                </div>
                <div class="form-group" style="margin:0">
                    <label class="form-label">
                        App Password Google
                        <?php if (!empty($smtp['password'])): ?>
                        <span style="font-size:11px;color:var(--success);font-weight:400">
                            <i class="fas fa-check-circle"></i> Sudah tersimpan
                        </span>
                        <?php endif; ?>
                    </label>
                    <div class="pw-wrap">
                        <input type="password" name="password" id="pwInput" class="form-control"
                            placeholder="<?= !empty($smtp['password']) ? '••••••••••••••••' : 'xxxx xxxx xxxx xxxx' ?>">
                        <button type="button" class="pw-btn" onclick="togglePw()">
                            <i class="fas fa-eye" id="pwIcon"></i>
                        </button>
                    </div>
                    <div class="form-hint">
                        <?= !empty($smtp['password'])
                            ? 'Kosongkan jika tidak ingin mengubah password.'
                            : 'Bukan password Gmail biasa. Buat di Google Account → Keamanan → App Passwords.' ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Identitas Pengirim -->
        <div class="ss">
            <div class="ss-head">
                <div class="ss-icon" style="background:#dcfce7;color:#16a34a"><i class="fas fa-id-badge"></i></div>
                <h3>Identitas Pengirim</h3>
            </div>
            <div class="ss-body">
                <div class="g2">
                    <div class="form-group" style="margin:0">
                        <label class="form-label">Nama Pengirim</label>
                        <input type="text" name="from_name" class="form-control"
                            value="<?= htmlspecialchars($smtp['from_name'] ?? 'DailyFix Absensi') ?>"
                            placeholder="DailyFix Absensi">
                    </div>
                    <div class="form-group" style="margin:0">
                        <label class="form-label">Email Pengirim <span class="req">*</span></label>
                        <input type="email" name="from_email" class="form-control"
                            value="<?= htmlspecialchars($smtp['from_email'] ?? '') ?>"
                            placeholder="noreply@perusahaan.com">
                    </div>
                </div>
                <div class="form-hint" style="margin-top:0">
                    <i class="fas fa-info-circle"></i>
                    Untuk Gmail, email pengirim harus sama dengan email akun Gmail di atas.
                </div>
            </div>
        </div>

        <!-- Toggle & Simpan -->
        <div class="ss">
            <div class="ss-body" style="flex-direction:row;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
                <div style="display:flex;align-items:center;gap:10px">
                    <label class="toggle-switch">
                        <input type="checkbox" name="is_active" value="1"
                            <?= ($smtp['is_active'] ?? 1) ? 'checked' : '' ?>>
                        <span class="toggle-slider"></span>
                    </label>
                    <div>
                        <div style="font-weight:700;font-size:13px">Aktifkan SMTP</div>
                        <div style="font-size:12px;color:var(--text-muted)">Matikan untuk pakai mail() bawaan PHP</div>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Simpan Konfigurasi
                </button>
            </div>
        </div>
        </form>

        <!-- Test Email -->
        <div class="ss">
            <div class="ss-head">
                <div class="ss-icon" style="background:#ede9fe;color:#7c3aed"><i class="fas fa-paper-plane"></i></div>
                <h3>Kirim Email Test</h3>
            </div>
            <div class="ss-body">
                <?php if (empty($smtp['username'])): ?>
                <div class="info-box">
                    <i class="fas fa-info-circle"></i>
                    Simpan konfigurasi terlebih dahulu sebelum mengirim test email.
                </div>
                <?php else: ?>
                <form method="POST" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
                    <input type="hidden" name="action" value="test">
                    <div style="flex:1;min-width:200px">
                        <label class="form-label">Kirim test ke email</label>
                        <input type="email" name="test_email" class="form-control"
                            value="<?= htmlspecialchars($smtp['username'] ?? '') ?>"
                            placeholder="test@gmail.com" required>
                    </div>
                    <button type="submit" class="btn btn-outline" style="color:#7c3aed;border-color:#7c3aed;white-space:nowrap">
                        <i class="fas fa-paper-plane"></i> Kirim Test
                    </button>
                </form>
                <div class="form-hint" style="margin-top:2px">
                    <i class="fas fa-info-circle"></i>
                    <?= $phpmailerFound
                        ? '<span style="color:var(--success)">✅ PHPMailer terdeteksi — SMTP penuh akan digunakan</span>'
                        : '<span style="color:var(--warning)">⚠️ PHPMailer tidak ditemukan — akan pakai mail() bawaan PHP. <a href="#panduan-phpmailer">Cara install →</a></span>' ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Info tersimpan -->
        <?php if (!empty($smtp['username'])): ?>
        <div class="ss">
            <div class="ss-head">
                <div class="ss-icon" style="background:#f1f5f9;color:#64748b"><i class="fas fa-circle-info"></i></div>
                <h3>Konfigurasi Tersimpan</h3>
            </div>
            <div style="padding:14px 18px">
                <div class="cfg-row"><span class="cfg-key">Host</span><span class="cfg-val"><?= htmlspecialchars($smtp['host']) ?></span></div>
                <div class="cfg-row"><span class="cfg-key">Port</span><span class="cfg-val"><?= $smtp['port'] ?> (<?= strtoupper($smtp['encryption']) ?>)</span></div>
                <div class="cfg-row"><span class="cfg-key">Username</span><span class="cfg-val"><?= htmlspecialchars($smtp['username']) ?></span></div>
                <div class="cfg-row"><span class="cfg-key">Nama Pengirim</span><span class="cfg-val"><?= htmlspecialchars($smtp['from_name']) ?></span></div>
                <div class="cfg-row"><span class="cfg-key">Email Pengirim</span><span class="cfg-val"><?= htmlspecialchars($smtp['from_email']) ?></span></div>
                <div class="cfg-row">
                    <span class="cfg-key">Diperbarui</span>
                    <span style="color:var(--text-muted);font-size:12.5px">
                        <?= tglIndonesia($smtp['updated_at'], 'short') ?> <?= date('H:i', strtotime($smtp['updated_at'])) ?>
                    </span>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ══ KANAN: Panduan ══ -->
    <div>

        <!-- Panduan App Password -->
        <div class="ss">
            <div class="ss-head">
                <div class="ss-icon" style="background:#fef3c7;color:#d97706"><i class="fab fa-google"></i></div>
                <h3>Cara Buat App Password Gmail</h3>
            </div>
            <div style="padding:14px 18px">
                <div class="panduan-item">
                    <div class="panduan-num">1</div>
                    <div>Buka <a href="https://myaccount.google.com/security" target="_blank">myaccount.google.com/security</a></div>
                </div>
                <div class="panduan-item">
                    <div class="panduan-num">2</div>
                    <div>Pastikan <strong>2-Step Verification</strong> sudah diaktifkan</div>
                </div>
                <div class="panduan-item">
                    <div class="panduan-num">3</div>
                    <div>Di kolom pencarian, ketik <strong>"App passwords"</strong> lalu klik</div>
                </div>
                <div class="panduan-item">
                    <div class="panduan-num">4</div>
                    <div>Klik <strong>Create</strong>, beri nama misal <em>DailyFix</em>, lalu klik <strong>Create</strong></div>
                </div>
                <div class="panduan-item">
                    <div class="panduan-num">5</div>
                    <div>Salin 16 karakter yang muncul
                        <br><code style="background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:11.5px">abcd efgh ijkl mnop</code>
                        <br>ke field App Password di form sebelah kiri
                    </div>
                </div>
                <div style="margin-top:12px;padding:10px 12px;background:#fef3c7;border-radius:8px;font-size:12.5px;color:#92400e">
                    <i class="fas fa-triangle-exclamation"></i>
                    <strong>Penting:</strong> Jangan gunakan password Gmail biasa. Harus App Password khusus.
                </div>
            </div>
        </div>

        <!-- PHPMailer -->
        <div class="ss" id="panduan-phpmailer">
            <div class="ss-head">
                <div class="ss-icon" style="background:#ede9fe;color:#7c3aed"><i class="fas fa-code"></i></div>
                <h3>Install PHPMailer</h3>
            </div>
            <div style="padding:14px 18px;font-size:13px">
                <p style="color:var(--text-muted);margin-bottom:12px">
                    PHPMailer diperlukan untuk SMTP Gmail. Tanpanya sistem menggunakan
                    <code>mail()</code> bawaan PHP yang tidak mendukung Gmail.
                </p>
                <p style="font-weight:800;margin-bottom:6px">Cara 1 — Composer (Rekomendasi)</p>
                <div class="code-block" style="margin-bottom:14px">
                    cd /path/to/dailyfix<br>
                    composer require phpmailer/phpmailer
                </div>
                <p style="font-weight:800;margin-bottom:6px">Cara 2 — Manual</p>
                <div class="panduan-item" style="padding:5px 0">
                    <div class="panduan-num" style="width:18px;height:18px;font-size:9px">1</div>
                    <div>Download dari <a href="https://github.com/PHPMailer/PHPMailer/releases" target="_blank">github.com/PHPMailer</a></div>
                </div>
                <div class="panduan-item" style="padding:5px 0">
                    <div class="panduan-num" style="width:18px;height:18px;font-size:9px">2</div>
                    <div>Salin folder <code style="background:#f1f5f9;padding:1px 5px;border-radius:3px">PHPMailer/</code> ke root project</div>
                </div>
                <div class="panduan-item" style="padding:5px 0;border:none">
                    <div class="panduan-num" style="width:18px;height:18px;font-size:9px">3</div>
                    <div>Sistem akan otomatis mendeteksinya</div>
                </div>
                <div class="code-block" style="margin-top:10px;font-size:11px">
                    dailyfix/<br>
                    ├── PHPMailer/<br>
                    │&nbsp;&nbsp; └── src/<br>
                    │&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; ├── PHPMailer.php<br>
                    │&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; ├── SMTP.php<br>
                    │&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; └── Exception.php<br>
                    └── pages/smtp_gmail.php
                </div>
            </div>
        </div>

        <!-- Helper di kode -->
        <div class="ss">
            <div class="ss-head">
                <div class="ss-icon" style="background:#dcfce7;color:#16a34a"><i class="fas fa-plug"></i></div>
                <h3>Penggunaan di Kode PHP</h3>
            </div>
            <div style="padding:14px 18px;font-size:13px;color:var(--text-muted)">
                <p style="margin-bottom:10px">
                    Tambahkan helper berikut di <code>includes/config.php</code> atau file helper Anda:
                </p>
                <div class="code-block" style="font-size:11px;color:#a5f3fc">
<span style="color:#64748b">// Kirim email via SMTP yang tersimpan</span><br>
<span style="color:#fb7185">function</span> <span style="color:#4ade80">sendSmtpEmail</span>($db, $to, $subj, $html) {<br>
&nbsp; $s = $db->query(<span style="color:#fbbf24">"SELECT * FROM smtp_settings<br>
&nbsp;&nbsp;&nbsp;&nbsp;WHERE id=1 AND is_active=1"</span>)->fetch();<br>
&nbsp; <span style="color:#fb7185">if</span> (!$s) <span style="color:#fb7185">return false</span>;<br>
&nbsp; <span style="color:#64748b">// ... PHPMailer setup sama seperti sendTestEmail()</span><br>
}<br><br>
<span style="color:#64748b">// Contoh pemakaian:</span><br>
<span style="color:#fb7185">$ok</span> = sendSmtpEmail($db,<br>
&nbsp; <span style="color:#fbbf24">'tujuan@email.com'</span>,<br>
&nbsp; <span style="color:#fbbf24">'Notifikasi Absen'</span>,<br>
&nbsp; <span style="color:#fbbf24">'&lt;p&gt;Isi email HTML&lt;/p&gt;'</span><br>
);
                </div>
                <div class="info-box" style="margin-top:12px;font-size:12.5px">
                    <i class="fas fa-lightbulb"></i>
                    Tidak perlu lagi kirim <code>perusahaan_id</code> — konfigurasi SMTP bersifat global untuk seluruh aplikasi.
                </div>
            </div>
        </div>

    </div><!-- /.kanan -->
</div><!-- /.smtp-grid -->

<script>
function togglePw() {
    const inp  = document.getElementById('pwInput');
    const icon = document.getElementById('pwIcon');
    inp.type   = inp.type === 'password' ? 'text' : 'password';
    icon.className = inp.type === 'text' ? 'fas fa-eye-slash' : 'fas fa-eye';
}

// Sinkronkan port & enkripsi
document.getElementById('portSel').addEventListener('change', function () {
    const enc = document.getElementById('encSel');
    if (this.value === '465') enc.value = 'ssl';
    else if (this.value === '587') enc.value = 'tls';
});
document.getElementById('encSel').addEventListener('change', function () {
    const port = document.getElementById('portSel');
    if (this.value === 'ssl')  port.value = '465';
    else if (this.value === 'tls') port.value = '587';
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>