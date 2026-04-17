<?php
require_once __DIR__ . '/../includes/config.php';
requireAdmin();

$pageTitle  = 'Master Shift';
$activePage = 'shift';
$user       = currentUser();
$db         = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id         = (int)($_POST['id'] ?? 0);
        $nama       = sanitize($_POST['nama'] ?? '');
        $jam_masuk  = $_POST['jam_masuk']  ?? '';
        $jam_keluar = $_POST['jam_keluar'] ?? '';
        $status     = $_POST['status']     ?? 'aktif';
        $keterangan = sanitize($_POST['keterangan'] ?? '');

        // Toleransi terlambat masuk
        $tolMasukJam   = (int)($_POST['tol_jam']   ?? 0);
        $tolMasukMenit = (int)($_POST['tol_menit'] ?? 0);
        $tolMasukDetik = (int)($_POST['tol_detik'] ?? 0);

        // Toleransi pulang cepat
        $tolPulangJam   = (int)($_POST['tol_pulang_jam']   ?? 0);
        $tolPulangMenit = (int)($_POST['tol_pulang_menit'] ?? 0);
        $tolPulangDetik = (int)($_POST['tol_pulang_detik'] ?? 0);

        if ($tolMasukJam > 2) {
            redirect(APP_URL.'/pages/shift.php', "Toleransi terlambat masuk terlalu besar ({$tolMasukJam} jam). Maksimal 2 jam.", 'danger');
        }
        if ($tolPulangJam > 2) {
            redirect(APP_URL.'/pages/shift.php', "Toleransi pulang cepat terlalu besar ({$tolPulangJam} jam). Maksimal 2 jam.", 'danger');
        }

        $totalMasukDetik  = ($tolMasukJam * 3600)  + ($tolMasukMenit * 60)  + $tolMasukDetik;
        $totalPulangDetik = ($tolPulangJam * 3600)  + ($tolPulangMenit * 60) + $tolPulangDetik;

        if (!$nama || !$jam_masuk || !$jam_keluar) {
            redirect(APP_URL.'/pages/shift.php', 'Semua field wajib diisi!', 'danger');
        }

        if ($id) {
            $db->prepare("UPDATE shift SET nama=?,jam_masuk=?,jam_keluar=?,toleransi_terlambat_detik=?,toleransi_pulang_cepat_detik=?,keterangan=?,status=? WHERE id=? AND perusahaan_id=?")
               ->execute([$nama,$jam_masuk,$jam_keluar,$totalMasukDetik,$totalPulangDetik,$keterangan,$status,$id,$user['perusahaan_id']]);
            redirect(APP_URL.'/pages/shift.php', 'Shift berhasil diperbarui.', 'success');
        } else {
            $db->prepare("INSERT INTO shift (perusahaan_id,nama,jam_masuk,jam_keluar,toleransi_terlambat_detik,toleransi_pulang_cepat_detik,keterangan,status) VALUES (?,?,?,?,?,?,?,?)")
               ->execute([$user['perusahaan_id'],$nama,$jam_masuk,$jam_keluar,$totalMasukDetik,$totalPulangDetik,$keterangan,$status]);
            redirect(APP_URL.'/pages/shift.php', 'Shift berhasil ditambahkan.', 'success');
        }

    } elseif ($action === 'delete') {
        $db->prepare("DELETE FROM shift WHERE id=? AND perusahaan_id=?")->execute([(int)$_POST['id'],$user['perusahaan_id']]);
        redirect(APP_URL.'/pages/shift.php', 'Shift berhasil dihapus.', 'success');
    }
}

$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM shift WHERE id=? AND perusahaan_id=?");
    $stmt->execute([(int)$_GET['edit'], $user['perusahaan_id']]);
    $edit = $stmt->fetch();
}

$shifts = $db->prepare("SELECT * FROM shift WHERE perusahaan_id=? ORDER BY jam_masuk");
$shifts->execute([$user['perusahaan_id']]);
$shifts = $shifts->fetchAll();

// Helper format detik → string
function formatDetikToStr($det) {
    $det = (int)$det;
    if ($det <= 0) return null;
    $parts = [];
    $j = floor($det / 3600); $m = floor(($det % 3600) / 60); $s = $det % 60;
    if ($j) $parts[] = $j . ' jam';
    if ($m) $parts[] = $m . ' menit';
    if ($s) $parts[] = $s . ' detik';
    return implode(' ', $parts);
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header flex justify-between items-center">
    <div>
        <h2>Master Shift</h2>
        <p>Kelola shift kerja, toleransi keterlambatan dan pulang cepat</p>
    </div>
    <button class="btn btn-primary" onclick="openModal()">
        <i class="fas fa-plus"></i> Tambah Shift
    </button>
</div>

<div class="card">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Nama Shift</th>
                    <th>Jam Masuk</th>
                    <th>Jam Keluar</th>
                    <th>Durasi</th>
                    <th>Toleransi Terlambat</th>
                    <th>Toleransi Pulang Cepat</th>
                    <th>Status</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($shifts)): ?>
                <tr><td colspan="9" class="text-center text-muted" style="padding:30px">Belum ada data shift</td></tr>
                <?php else: foreach ($shifts as $i => $s):
                    $masuk  = strtotime('2000-01-01 ' . $s['jam_masuk']);
                    $keluar = strtotime('2000-01-01 ' . $s['jam_keluar']);
                    if ($keluar < $masuk) $keluar += 86400;
                    $durasi = round(($keluar - $masuk) / 3600, 1);
                    $tolMasukStr  = formatDetikToStr($s['toleransi_terlambat_detik'])  ?? 'Tidak ada';
                    $tolPulangStr = formatDetikToStr($s['toleransi_pulang_cepat_detik'] ?? 0) ?? 'Tidak ada';
                    $masukWarning  = (int)$s['toleransi_terlambat_detik']      > 7200;
                    $pulangWarning = (int)($s['toleransi_pulang_cepat_detik'] ?? 0) > 7200;
                ?>
                <tr>
                    <td><?= $i+1 ?></td>
                    <td><strong><?= htmlspecialchars($s['nama']) ?></strong></td>
                    <td style="font-family:'JetBrains Mono',monospace;font-weight:600;color:var(--success)"><?= substr($s['jam_masuk'],0,5) ?></td>
                    <td style="font-family:'JetBrains Mono',monospace;font-weight:600;color:var(--danger)"><?= substr($s['jam_keluar'],0,5) ?></td>
                    <td><?= $durasi ?> jam</td>
                    <td>
                        <?php if ($masukWarning): ?>
                        <span style="color:#ef4444;font-size:12.5px;font-weight:600"><i class="fas fa-triangle-exclamation"></i> <?= $tolMasukStr ?></span>
                        <?php elseif ((int)$s['toleransi_terlambat_detik'] > 0): ?>
                        <span style="color:#f59e0b;font-size:12.5px;font-weight:600"><i class="fas fa-clock"></i> <?= $tolMasukStr ?></span>
                        <?php else: ?>
                        <span style="color:var(--text-muted);font-size:12.5px">Tidak ada</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($pulangWarning): ?>
                        <span style="color:#ef4444;font-size:12.5px;font-weight:600"><i class="fas fa-triangle-exclamation"></i> <?= $tolPulangStr ?></span>
                        <?php elseif ((int)($s['toleransi_pulang_cepat_detik'] ?? 0) > 0): ?>
                        <span style="color:#8b5cf6;font-size:12.5px;font-weight:600"><i class="fas fa-person-running"></i> <?= $tolPulangStr ?></span>
                        <?php else: ?>
                        <span style="color:var(--text-muted);font-size:12.5px">Tidak ada</span>
                        <?php endif; ?>
                    </td>
                    <td><?= badgeStatus($s['status']) ?></td>
                    <td>
                        <div style="display:flex;gap:6px">
                            <a href="?edit=<?= $s['id'] ?>" class="btn btn-outline btn-sm btn-icon" title="Edit"><i class="fas fa-pen"></i></a>
                            <form method="POST" onsubmit="return confirm('Hapus shift ini?')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                <button class="btn btn-danger btn-sm btn-icon"><i class="fas fa-trash"></i></button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal -->
<div class="modal-overlay <?= $edit ? 'open' : '' ?>" id="modalShift">
    <div class="modal modal-lg">
        <div class="modal-header">
            <h3><?= $edit ? 'Edit Shift' : 'Tambah Shift' ?></h3>
            <div class="modal-close" onclick="closeModal()"><i class="fas fa-xmark"></i></div>
        </div>
        <form method="POST" onsubmit="return validasiForm()">
            <div class="modal-body">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">

                <div class="form-group">
                    <label class="form-label">Nama Shift <span class="req">*</span></label>
                    <input type="text" name="nama" class="form-control"
                        value="<?= htmlspecialchars($edit['nama'] ?? '') ?>"
                        placeholder="contoh: Shift Pagi" required>
                </div>

                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">Jam Masuk <span class="req">*</span></label>
                        <input type="time" name="jam_masuk" class="form-control"
                            value="<?= $edit['jam_masuk'] ?? '' ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Jam Keluar <span class="req">*</span></label>
                        <input type="time" name="jam_keluar" class="form-control"
                            value="<?= $edit['jam_keluar'] ?? '' ?>" required>
                    </div>
                </div>

                <!-- ── TOLERANSI TERLAMBAT MASUK ── -->
                <?php
                $detM  = (int)($edit['toleransi_terlambat_detik'] ?? 0);
                $jM    = floor($detM / 3600);
                $mM    = floor(($detM % 3600) / 60);
                $sM    = $detM % 60;
                $strM  = formatDetikToStr($detM) ?? 'Tidak ada toleransi';
                ?>
                <div class="form-group">
                    <label class="form-label">
                        <i class="fas fa-clock" style="color:#f59e0b"></i>
                        Toleransi Terlambat Masuk
                        <span style="font-size:11.5px;font-weight:400;color:#64748b;margin-left:4px">— batas aman sebelum dihitung terlambat</span>
                    </label>
                    <?= toleransiBlock('masuk', $jM, $mM, $sM, $strM, '#fffbeb', '#fcd34d', '#92400e', 'tol_jam', 'tol_menit', 'tol_detik', 'tolMasukJam', 'tolMasukMenit', 'tolMasukDetik', 'tolMasukPreview', 'tolMasukPreviewText') ?>
                </div>

                <!-- ── TOLERANSI PULANG CEPAT ── -->
                <?php
                $detP  = (int)($edit['toleransi_pulang_cepat_detik'] ?? 0);
                $jP    = floor($detP / 3600);
                $mP    = floor(($detP % 3600) / 60);
                $sP    = $detP % 60;
                $strP  = formatDetikToStr($detP) ?? 'Tidak ada toleransi';
                ?>
                <div class="form-group">
                    <label class="form-label">
                        <i class="fas fa-person-running" style="color:#8b5cf6"></i>
                        Toleransi Pulang Cepat
                        <span style="font-size:11.5px;font-weight:400;color:#64748b;margin-left:4px">— batas aman sebelum dihitung pulang cepat</span>
                    </label>
                    <?= toleransiBlock('pulang', $jP, $mP, $sP, $strP, '#faf5ff', '#d8b4fe', '#6d28d9', 'tol_pulang_jam', 'tol_pulang_menit', 'tol_pulang_detik', 'tolPulangJam', 'tolPulangMenit', 'tolPulangDetik', 'tolPulangPreview', 'tolPulangPreviewText') ?>
                </div>

                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="aktif"    <?= ($edit['status']??'aktif')==='aktif'?'selected':'' ?>>Aktif</option>
                            <option value="nonaktif" <?= ($edit['status']??'')==='nonaktif'?'selected':'' ?>>Nonaktif</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Keterangan</label>
                    <textarea name="keterangan" class="form-control" rows="2"><?= htmlspecialchars($edit['keterangan'] ?? '') ?></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal()">Batal</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Simpan</button>
            </div>
        </form>
    </div>
</div>

<?php
// Helper render blok toleransi (reusable untuk masuk & pulang)
function toleransiBlock($prefix, $j, $m, $s, $strDisplay,
    $bgColor, $borderColor, $textColor,
    $nameJam, $nameMenit, $nameDetik,
    $idJam, $idMenit, $idDetik,
    $idPreview, $idPreviewText) {
    ob_start(); ?>
    <div style="padding:16px;background:var(--surface2);border-radius:10px;border:1.5px solid var(--border)">
        <div id="<?= $idPreview ?>" style="display:flex;align-items:center;gap:8px;background:<?= $bgColor ?>;border:1px solid <?= $borderColor ?>;border-radius:8px;padding:9px 13px;margin-bottom:12px;font-size:13px;color:<?= $textColor ?>">
            <i class="fas fa-clock" style="flex-shrink:0"></i>
            <span id="<?= $idPreviewText ?>"><strong><?= $strDisplay ?></strong></span>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:12px">
            <div>
                <label style="display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:5px">Jam <em style="font-weight:400;color:#94a3b8">(max 2)</em></label>
                <div style="position:relative">
                    <input type="number" name="<?= $nameJam ?>" id="<?= $idJam ?>" class="form-control"
                        value="<?= $j ?>" min="0" max="2" placeholder="0"
                        oninput="updatePreview('<?= $prefix ?>')"
                        style="padding-right:38px;text-align:center;font-size:16px;font-weight:700">
                    <span style="position:absolute;right:8px;top:50%;transform:translateY(-50%);font-size:10.5px;color:#94a3b8;pointer-events:none">jam</span>
                </div>
            </div>
            <div>
                <label style="display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:5px">Menit <em style="font-weight:400;color:#94a3b8">(0–59)</em></label>
                <div style="position:relative">
                    <input type="number" name="<?= $nameMenit ?>" id="<?= $idMenit ?>" class="form-control"
                        value="<?= $m ?>" min="0" max="59" placeholder="0"
                        oninput="updatePreview('<?= $prefix ?>')"
                        style="padding-right:38px;text-align:center;font-size:16px;font-weight:700">
                    <span style="position:absolute;right:8px;top:50%;transform:translateY(-50%);font-size:10.5px;color:#94a3b8;pointer-events:none">mnt</span>
                </div>
            </div>
            <div>
                <label style="display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:5px">Detik <em style="font-weight:400;color:#94a3b8">(0–59)</em></label>
                <div style="position:relative">
                    <input type="number" name="<?= $nameDetik ?>" id="<?= $idDetik ?>" class="form-control"
                        value="<?= $s ?>" min="0" max="59" placeholder="0"
                        oninput="updatePreview('<?= $prefix ?>')"
                        style="padding-right:38px;text-align:center;font-size:16px;font-weight:700">
                    <span style="position:absolute;right:8px;top:50%;transform:translateY(-50%);font-size:10.5px;color:#94a3b8;pointer-events:none">dtk</span>
                </div>
            </div>
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center">
            <span style="font-size:11.5px;color:#94a3b8;font-weight:600">Pilih cepat:</span>
            <button type="button" class="btn-tol-quick" onclick="setToleransi('<?= $prefix ?>',0,0,0)">Tidak ada</button>
            <button type="button" class="btn-tol-quick" onclick="setToleransi('<?= $prefix ?>',0,5,0)">5 menit</button>
            <button type="button" class="btn-tol-quick" onclick="setToleransi('<?= $prefix ?>',0,10,0)">10 menit</button>
            <button type="button" class="btn-tol-quick" onclick="setToleransi('<?= $prefix ?>',0,15,0)">15 menit</button>
            <button type="button" class="btn-tol-quick" onclick="setToleransi('<?= $prefix ?>',0,30,0)">30 menit</button>
            <button type="button" class="btn-tol-quick" onclick="setToleransi('<?= $prefix ?>',1,0,0)">1 jam</button>
        </div>
    </div>
    <?php return ob_get_clean();
}
?>

<style>
.btn-tol-quick {
    background:#f1f5f9; border:1.5px solid #e2e8f0; color:#475569;
    padding:5px 12px; border-radius:6px; font-size:12px; font-weight:600;
    cursor:pointer; font-family:inherit; transition:all .15s;
}
.btn-tol-quick:hover { background:#0f4c81; color:#fff; border-color:#0f4c81; }
</style>

<script>
function openModal()  { document.getElementById('modalShift').classList.add('open'); }
function closeModal() {
    document.getElementById('modalShift').classList.remove('open');
    history.replaceState(null,'',window.location.pathname);
}
<?php if($edit): ?>window.addEventListener('load',()=>openModal());<?php endif; ?>

// Map prefix → element IDs & warna
const TOL_CONFIG = {
    masuk: {
        idJam:'tolMasukJam', idMenit:'tolMasukMenit', idDetik:'tolMasukDetik',
        idPreview:'tolMasukPreview', idText:'tolMasukPreviewText',
        bgNormal:'#fffbeb', bdNormal:'#fcd34d', txtNormal:'#92400e',
        label:'Toleransi terlambat masuk'
    },
    pulang: {
        idJam:'tolPulangJam', idMenit:'tolPulangMenit', idDetik:'tolPulangDetik',
        idPreview:'tolPulangPreview', idText:'tolPulangPreviewText',
        bgNormal:'#faf5ff', bdNormal:'#d8b4fe', txtNormal:'#6d28d9',
        label:'Toleransi pulang cepat'
    }
};

function updatePreview(prefix) {
    const cfg  = TOL_CONFIG[prefix];
    const jam   = parseInt(document.getElementById(cfg.idJam).value)   || 0;
    const menit = parseInt(document.getElementById(cfg.idMenit).value)  || 0;
    const detik = parseInt(document.getElementById(cfg.idDetik).value)  || 0;

    const box  = document.getElementById(cfg.idPreview);
    const text = document.getElementById(cfg.idText);

    if (jam === 0 && menit === 0 && detik === 0) {
        text.innerHTML = '<strong>Tidak ada toleransi</strong>';
        box.style.background   = '#f8fafc';
        box.style.borderColor  = '#e2e8f0';
        box.style.color        = '#64748b';
        return;
    }

    const parts = [];
    if (jam)   parts.push(jam   + ' jam');
    if (menit) parts.push(menit + ' menit');
    if (detik) parts.push(detik + ' detik');

    if (jam > 2) {
        text.innerHTML = `<i class="fas fa-triangle-exclamation"></i> <strong style="color:#ef4444">${parts.join(' ')} — terlalu besar! Maksimal 2 jam.</strong>`;
        box.style.background  = '#fef2f2';
        box.style.borderColor = '#fecaca';
        box.style.color       = '#991b1b';
    } else {
        text.innerHTML = `${cfg.label}: <strong>${parts.join(' ')}</strong>`;
        box.style.background  = cfg.bgNormal;
        box.style.borderColor = cfg.bdNormal;
        box.style.color       = cfg.txtNormal;
    }
}

function setToleransi(prefix, j, m, s) {
    const cfg = TOL_CONFIG[prefix];
    document.getElementById(cfg.idJam).value   = j;
    document.getElementById(cfg.idMenit).value = m;
    document.getElementById(cfg.idDetik).value = s;
    updatePreview(prefix);
}

function validasiForm() {
    const jM = parseInt(document.getElementById('tolMasukJam').value)  || 0;
    const jP = parseInt(document.getElementById('tolPulangJam').value) || 0;
    if (jM > 2) { alert('⚠️ Toleransi terlambat masuk terlalu besar (' + jM + ' jam). Gunakan Menit.'); return false; }
    if (jP > 2) { alert('⚠️ Toleransi pulang cepat terlalu besar (' + jP + ' jam). Gunakan Menit.');  return false; }
    return true;
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>