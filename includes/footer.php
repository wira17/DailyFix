</main>
    <footer style="padding:14px 24px;border-top:1px solid var(--border);background:var(--surface);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
        <p style="font-size:12px;color:var(--text-muted);margin:0">
            &copy; <?= date('Y') ?> <strong>DailyFix</strong> — Sistem Absensi Digital v<?= APP_VERSION ?>
        </p>
        <button onclick="openAboutModal()" style="display:inline-flex;align-items:center;gap:6px;background:none;border:1.5px solid var(--border);color:var(--text-muted);padding:5px 14px;border-radius:20px;font-size:12px;font-weight:600;font-family:inherit;cursor:pointer;transition:all .2s" onmouseover="this.style.borderColor='var(--primary)';this.style.color='var(--primary)'" onmouseout="this.style.borderColor='var(--border)';this.style.color='var(--text-muted)'">
            <i class="fas fa-circle-info"></i> Tentang Aplikasi
        </button>
    </footer>
</div>

<!-- ════════════════════════════════════════════════════
     MODAL TENTANG APLIKASI
════════════════════════════════════════════════════ -->
<style>
@keyframes aboutIn { from{opacity:0;transform:scale(.93) translateY(16px)} to{opacity:1;transform:scale(1) translateY(0)} }

#modalAboutOverlay {
    position:fixed;inset:0;
    background:rgba(10,22,40,.7);
    backdrop-filter:blur(6px);
    z-index:9999;
    display:none;
    align-items:center;
    justify-content:center;
    padding:16px;
}

/* ── Kotak modal utama ── */
.about-box {
    background:#fff;
    border-radius:20px;
    width:100%;
    max-width:860px;
    max-height:92vh;
    display:flex;
    flex-direction:row;       /* landscape default */
    box-shadow:0 32px 80px rgba(0,0,0,.35);
    overflow:hidden;
    animation:aboutIn .3s cubic-bezier(.34,1.56,.64,1);
}

/* ── Sidebar kiri ── */
.about-sidebar {
    width:230px;
    min-width:230px;
    background:linear-gradient(160deg,#0f4c81 0%,#0a2d55 60%,#061a33 100%);
    padding:28px 22px;
    display:flex;
    flex-direction:column;
    align-items:center;
    text-align:center;
    position:relative;
    overflow:hidden;
    flex-shrink:0;
}
.about-sidebar::before { content:'';position:absolute;top:-60px;right:-60px;width:180px;height:180px;border-radius:50%;background:radial-gradient(circle,rgba(0,201,167,.2) 0%,transparent 70%); }
.about-sidebar::after  { content:'';position:absolute;bottom:-40px;left:-40px;width:140px;height:140px;border-radius:50%;background:radial-gradient(circle,rgba(255,255,255,.05) 0%,transparent 70%); }
.about-dots { position:absolute;inset:0;background-image:radial-gradient(rgba(255,255,255,.06) 1px,transparent 1px);background-size:24px 24px; }
.about-sc { position:relative;z-index:1;width:100%;display:flex;flex-direction:column;align-items:center;gap:12px; }
.about-logo { width:56px;height:56px;background:linear-gradient(135deg,#00c9a7,#0ea5e9);border-radius:14px;display:inline-flex;align-items:center;justify-content:center;font-size:26px;font-weight:900;color:#fff;box-shadow:0 8px 24px rgba(0,201,167,.45); }
.about-badge { display:inline-flex;align-items:center;gap:6px;background:rgba(0,201,167,.15);border:1px solid rgba(0,201,167,.35);color:#00c9a7;padding:5px 12px;border-radius:20px;font-size:11.5px;font-weight:700; }
.about-feat { width:100%;display:flex;flex-direction:column;gap:6px;margin-top:4px; }
.about-feat-item { display:flex;align-items:center;gap:9px;background:rgba(255,255,255,.07);border-radius:9px;padding:7px 10px;font-size:11.5px;color:rgba(255,255,255,.8);text-align:left; }
.about-feat-item i { color:#00c9a7;width:14px;text-align:center;flex-shrink:0; }
.about-copy { font-size:10.5px;color:rgba(255,255,255,.3);line-height:1.6;margin-top:auto;padding-top:14px; }

/* ── Panel kanan ── */
.about-main { flex:1;display:flex;flex-direction:column;min-width:0;overflow:hidden; }
.about-main-hdr { display:flex;align-items:center;justify-content:space-between;padding:16px 24px;border-bottom:1px solid #f1f5f9;flex-shrink:0; }
.about-main-body { flex:1;overflow-y:auto;padding:20px 24px; }
.about-main-ftr { padding:14px 24px;border-top:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;flex-wrap:wrap;gap:8px; }

/* ── Elemen dalam body ── */
.about-sec-title { font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:1.5px;color:#94a3b8;margin-bottom:10px;display:flex;align-items:center;gap:7px; }
.about-sec-title::after { content:'';flex:1;height:1px;background:#f1f5f9; }
.about-free-badge { display:inline-flex;align-items:center;gap:7px;background:#f0fdf4;border:1.5px solid #bbf7d0;color:#15803d;padding:7px 14px;border-radius:10px;font-size:12.5px;font-weight:700;margin-bottom:10px; }
.about-warn { background:#fef2f2;border:1px solid #fecaca;border-left:4px solid #ef4444;border-radius:8px;padding:10px 14px;font-size:12.5px;color:#991b1b;display:flex;gap:8px;align-items:flex-start;margin-bottom:18px; }
.menu-grid { display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:18px; }
.menu-item { display:flex;align-items:center;gap:8px;padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;font-size:12.5px;color:#374151;font-weight:500; }
.menu-item i { color:#0f4c81;width:16px;text-align:center;flex-shrink:0; }
.donasi-grid { display:grid;grid-template-columns:1fr 1fr;gap:10px; }
.donasi-card { border:1.5px solid #e2e8f0;border-radius:12px;padding:14px 16px;display:flex;flex-direction:column;gap:10px;transition:border-color .2s,box-shadow .2s; }
.donasi-card:hover { border-color:#0f4c81;box-shadow:0 3px 16px rgba(15,76,129,.12); }
.donasi-icon { width:40px;height:40px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center; }
.donasi-icon.gopay { background:linear-gradient(135deg,#00aed6,#0070ba); }
.donasi-icon.bsi   { background:linear-gradient(135deg,#00a650,#006633); }
.copy-btn { width:100%;background:#f1f5f9;border:none;color:#64748b;padding:8px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:6px; }
.copy-btn:hover { background:#0f4c81;color:#fff; }
.copy-btn.copied { background:#10b981;color:#fff; }

/* ════ MOBILE: kolom vertikal ════ */
@media(max-width:640px) {
    #modalAboutOverlay { padding:0; align-items:flex-end; }

    /* Modal full-width sheet dari bawah */
    .about-box {
        flex-direction: column;      /* ← KUNCI: vertikal di mobile */
        max-width: 100%;
        max-height: 92vh;
        border-radius: 20px 20px 0 0;
        animation: aboutInMobile .3s ease;
    }
    @keyframes aboutInMobile { from{transform:translateY(100%)} to{transform:translateY(0)} }

    /* Sidebar jadi strip horizontal di atas */
    .about-sidebar {
        width: 100% !important;
        min-width: 0 !important;
        flex-direction: row !important;
        align-items: center !important;
        padding: 14px 18px !important;
        text-align: left !important;
        flex-shrink: 0;
        gap: 0;
    }
    .about-sidebar::before,
    .about-sidebar::after,
    .about-dots { display: none; }

    .about-sc {
        flex-direction: row !important;
        align-items: center !important;
        gap: 12px !important;
        width: 100%;
    }

    /* Sembunyikan fitur list, badge, copy di strip */
    .about-feat,
    .about-copy,
    .about-badge { display: none !important; }

    .about-logo { width:36px; height:36px; font-size:16px; flex-shrink:0; }

    /* Panel kanan bisa scroll normal */
    .about-main { flex:1; min-height:0; overflow:hidden; }
    .about-main-body { overflow-y:auto; padding:16px 18px; }
    .about-main-hdr { padding:12px 18px; }
    .about-main-ftr { padding:12px 18px; }

    /* Grid 1 kolom */
    .menu-grid,
    .donasi-grid { grid-template-columns: 1fr; }
}
</style>

<div id="modalAboutOverlay" onclick="if(event.target===this)closeAboutModal()">
    <div class="about-box">

        <!-- ── Sidebar kiri ── -->
        <div class="about-sidebar">
            <div class="about-dots"></div>
            <div class="about-sc">
                <div class="about-logo">D</div>
                <div>
                    <div style="color:#fff;font-size:17px;font-weight:800;margin-bottom:2px">DailyFix</div>
                    <div style="color:rgba(255,255,255,.55);font-size:11.5px">Sistem Absensi Digital v<?= APP_VERSION ?></div>
                </div>
                <div class="about-badge"><i class="fas fa-heart" style="color:#ef4444"></i> Gratis & Bebas</div>
                <div class="about-feat">
                    <div class="about-feat-item"><i class="fas fa-map-location-dot"></i> Absensi GPS Real-time</div>
                    <div class="about-feat-item"><i class="fas fa-map-pin"></i> Multi Lokasi Absen</div>
                    <div class="about-feat-item"><i class="fas fa-camera"></i> Verifikasi Foto Wajah</div>
                    <div class="about-feat-item"><i class="fas fa-shield-halved"></i> Anti Fake GPS (9 Layer)</div>
                    <div class="about-feat-item"><i class="fas fa-key"></i> Login OTP Email</div>
                    <div class="about-feat-item"><i class="fas fa-clock"></i> Terlambat & Pulang Cepat</div>
                    <div class="about-feat-item"><i class="fas fa-chart-bar"></i> Rekap & Laporan PDF</div>
                    <div class="about-feat-item"><i class="fas fa-building"></i> Multi Perusahaan</div>
                </div>
                <div class="about-copy">© <?= date('Y') ?> DailyFix<br>Hak Cipta Dilindungi</div>
            </div>
        </div>

        <!-- ── Panel kanan ── -->
        <div class="about-main">
            <div class="about-main-hdr">
                <h4 style="font-size:15px;font-weight:800;color:#0f172a;margin:0">
                    <i class="fas fa-circle-info" style="color:#0ea5e9;margin-right:6px"></i>Tentang Aplikasi
                </h4>
                <button onclick="closeAboutModal()" style="background:#f1f5f9;border:none;color:#64748b;width:30px;height:30px;border-radius:8px;cursor:pointer;font-size:13px;display:flex;align-items:center;justify-content:center;transition:all .2s" onmouseover="this.style.background='#ef4444';this.style.color='#fff'" onmouseout="this.style.background='#f1f5f9';this.style.color='#64748b'">
                    <i class="fas fa-xmark"></i>
                </button>
            </div>

            <div class="about-main-body">

                <!-- Info -->
                <div class="about-sec-title"><i class="fas fa-info" style="color:#0ea5e9"></i> Informasi</div>
                <div class="about-free-badge"><i class="fas fa-heart" style="color:#ef4444"></i> Aplikasi Gratis & Open Source</div>
                <p style="font-size:13px;color:#475569;line-height:1.75;margin-bottom:10px">
                    <strong>DailyFix</strong> dikembangkan secara independen oleh <strong>M. Wira Satria Buana, S.Kom</strong> dan dibagikan secara <strong>gratis</strong> untuk membantu perusahaan dan organisasi mengelola kehadiran karyawan secara akurat dan modern.
                </p>
                <div class="about-warn">
                    <i class="fas fa-ban" style="flex-shrink:0;margin-top:1px"></i>
                    <div><strong>Larangan Keras:</strong> Aplikasi ini <strong>tidak boleh diperjualbelikan</strong> dalam bentuk apapun. Redistribusi komersial tanpa izin tertulis dari pengembang adalah pelanggaran hak cipta.</div>
                </div>

                <!-- Menu -->
                <div class="about-sec-title"><i class="fas fa-list-check" style="color:#10b981"></i> Menu yang Tersedia</div>
                <div class="menu-grid">
                    <div class="menu-item"><i class="fas fa-gauge-high"></i> Dashboard</div>
                    <div class="menu-item"><i class="fas fa-fingerprint"></i> Absensi GPS + Foto</div>
                    <div class="menu-item"><i class="fas fa-calendar-check"></i> Rekap Absensi Saya</div>
                    <div class="menu-item"><i class="fas fa-building"></i> Master Perusahaan</div>
                    <div class="menu-item"><i class="fas fa-map-marker-alt"></i> Master Lokasi (Multi)</div>
                    <div class="menu-item"><i class="fas fa-clock"></i> Master Shift</div>
                    <div class="menu-item"><i class="fas fa-calendar-days"></i> Master Jadwal</div>
                    <div class="menu-item"><i class="fas fa-briefcase"></i> Master Jabatan</div>
                    <div class="menu-item"><i class="fas fa-sitemap"></i> Master Departemen</div>
                    <div class="menu-item"><i class="fas fa-chart-bar"></i> Rekap Kehadiran Admin</div>
                    <div class="menu-item"><i class="fas fa-shield-halved"></i> Fraud Alert GPS</div>
                    <div class="menu-item"><i class="fas fa-envelope"></i> Pengaturan SMTP Gmail</div>
                </div>

                <!-- Keunggulan -->
                <div class="about-sec-title"><i class="fas fa-star" style="color:#f59e0b"></i> Keunggulan</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:18px" class="fitur-grid">
                    <?php
                    $features = [
                        ['fas fa-map-pin',       '#0f4c81', 'Multi Lokasi',          'Karyawan bisa absen dari beberapa lokasi sekaligus'],
                        ['fas fa-shield-halved', '#ef4444', 'Anti Fake GPS',          '9 lapisan keamanan: akurasi, kecepatan, koordinat, IP, historis, dll'],
                        ['fas fa-clock',         '#f59e0b', 'Terlambat & Pulang Cepat','Toleransi fleksibel per shift, notifikasi real-time'],
                        ['fas fa-key',           '#10b981', 'Login OTP',              'Tanpa password, cukup kode 6 digit via email'],
                        ['fas fa-camera',        '#8b5cf6', 'Foto Wajah',             'Verifikasi selfie setiap kali absen masuk/keluar'],
                        ['fas fa-file-pdf',      '#dc2626', 'Laporan PDF',            'Cetak rekap bulanan per karyawan atau semua karyawan'],
                    ];
                    foreach($features as $f): ?>
                    <div style="display:flex;align-items:flex-start;gap:10px;padding:10px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px">
                        <div style="width:32px;height:32px;border-radius:8px;background:<?= $f[1] ?>18;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                            <i class="<?= $f[0] ?>" style="color:<?= $f[1] ?>;font-size:13px"></i>
                        </div>
                        <div>
                            <div style="font-size:12.5px;font-weight:700;color:#0f172a;margin-bottom:2px"><?= $f[2] ?></div>
                            <div style="font-size:11px;color:#64748b;line-height:1.5"><?= $f[3] ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <style>
                @media(max-width:640px) { .fitur-grid { grid-template-columns:1fr !important; } }
                </style>

                <!-- Donasi -->
                <div class="about-sec-title"><i class="fas fa-hand-holding-heart" style="color:#ef4444"></i> Dukung Pengembang</div>
                <p style="font-size:13px;color:#475569;margin-bottom:12px">Jika aplikasi ini bermanfaat, dukung pengembangan melalui donasi sukarela 🙏</p>
                <div class="donasi-grid">
                    <div class="donasi-card">
                        <div style="display:flex;align-items:center;gap:10px">
                            <div class="donasi-icon gopay"><span style="color:#fff;font-weight:900;font-size:12px">GP</span></div>
                            <div>
                                <div style="font-size:10.5px;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:.5px">GoPay / WhatsApp</div>
                                <div style="font-size:15px;font-weight:900;color:#0f172a;font-family:'JetBrains Mono',monospace">082177846209</div>
                                <div style="font-size:11.5px;color:#64748b">M. Wira Satria Buana</div>
                            </div>
                        </div>
                        <button class="copy-btn" onclick="copyAbout('082177846209', this)"><i class="fas fa-copy"></i> Salin Nomor</button>
                    </div>
                    <div class="donasi-card">
                        <div style="display:flex;align-items:center;gap:10px">
                            <div class="donasi-icon bsi"><span style="color:#fff;font-weight:900;font-size:11px;letter-spacing:-1px">BSI</span></div>
                            <div>
                                <div style="font-size:10.5px;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:.5px">Bank BSI</div>
                                <div style="font-size:15px;font-weight:900;color:#0f172a;font-family:'JetBrains Mono',monospace">7134197557</div>
                                <div style="font-size:11.5px;color:#64748b">M. Wira Satria Buana</div>
                            </div>
                        </div>
                        <button class="copy-btn" onclick="copyAbout('7134197557', this)"><i class="fas fa-copy"></i> Salin Rekening</button>
                    </div>
                </div>

            </div><!-- /body -->

            <div class="about-main-ftr">
                <span style="font-size:11.5px;color:#94a3b8">© <?= date('Y') ?> DailyFix — Develop M. Wira Sb. S.Kom</span>
                <button onclick="closeAboutModal()" style="background:linear-gradient(135deg,#0f4c81,#0a2d55);border:none;color:#fff;padding:9px 22px;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;display:flex;align-items:center;gap:6px">
                    <i class="fas fa-xmark"></i> Tutup
                </button>
            </div>
        </div><!-- /main -->

    </div><!-- /about-box -->
</div><!-- /overlay -->

<script>
// ── Modal Tentang ─────────────────────────────────────────────
function openAboutModal() {
    const el = document.getElementById('modalAboutOverlay');
    el.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function closeAboutModal() {
    document.getElementById('modalAboutOverlay').style.display = 'none';
    document.body.style.overflow = '';
}
function copyAbout(text, btn) {
    navigator.clipboard.writeText(text).then(() => {
        btn.classList.add('copied');
        btn.innerHTML = '<i class="fas fa-check"></i> Disalin!';
        setTimeout(() => { btn.classList.remove('copied'); btn.innerHTML = '<i class="fas fa-copy"></i> Salin'; }, 2200);
    });
}

// ===== CLOCK =====
function updateClock() {
    const now = new Date();
    const el = document.getElementById('headerClock');
    if (el) el.textContent = String(now.getHours()).padStart(2,'0')+':'+String(now.getMinutes()).padStart(2,'0')+':'+String(now.getSeconds()).padStart(2,'0');
}
setInterval(updateClock, 1000); updateClock();

// ===== SIDEBAR TOGGLE =====
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('open');
}

// ===== NAV GROUP COLLAPSIBLE =====
function toggleGroup(header) {
    const isOpen = header.classList.contains('open');
    document.querySelectorAll('.nav-group-header.open').forEach(h => {
        if (h !== header) { h.classList.remove('open'); h.nextElementSibling.classList.remove('open'); }
    });
    header.classList.toggle('open', !isOpen);
    header.nextElementSibling.classList.toggle('open', !isOpen);
    localStorage.setItem('nav_open_' + header.textContent.trim().replace(/\s+/g,'_'), !isOpen ? '1' : '0');
}
document.querySelectorAll('.nav-group-header:not(.has-active)').forEach(header => {
    if (localStorage.getItem('nav_open_' + header.textContent.trim().replace(/\s+/g,'_')) === '1') {
        header.classList.add('open'); header.nextElementSibling.classList.add('open');
    }
});

// ===== AUTO HIDE ALERT =====
setTimeout(() => {
    document.querySelectorAll('.alert').forEach(el => {
        el.style.transition = 'opacity .5s'; el.style.opacity = '0';
        setTimeout(() => el.remove(), 500);
    });
}, 4000);
</script>
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('<?= APP_URL ?>/sw.js').catch(err => console.log('SW:', err));
    });
}
</script>
</body>
</html>