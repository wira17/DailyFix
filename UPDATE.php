-- ============================================================
-- Migration: tambah kolom bukti_file ke tabel absensi
-- Jalankan sekali di database Anda
-- ============================================================

ALTER TABLE absensi
    ADD COLUMN bukti_file varchar(255) NULL COMMENT 'Path file bukti (dinas/sakit/izin)' AFTER foto_keluar;

-- Pastikan enum status_kehadiran sudah include nilai berikut:
-- 'hadir', 'terlambat', 'absen', 'izin', 'sakit', 'dinas_luar'
-- Jika belum, jalankan:
ALTER TABLE absensi
    MODIFY COLUMN status_kehadiran
        ENUM('hadir','terlambat','absen','izin','sakit','dinas_luar','cuti','libur')
        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
        DEFAULT 'absen';




-- =====================================================
-- DailyFix — Tabel SMTP Settings (Global)
-- Jalankan di phpMyAdmin → Database dailyfix → SQL
-- =====================================================

-- Hapus tabel lama jika ada (yang masih pakai perusahaan_id)
DROP TABLE IF EXISTS `smtp_settings`;

-- Buat tabel baru tanpa perusahaan_id
CREATE TABLE `smtp_settings` (
    `id`          INT           NOT NULL AUTO_INCREMENT,
    `host`        VARCHAR(100)  NOT NULL DEFAULT 'smtp.gmail.com',
    `port`        INT           NOT NULL DEFAULT 587,
    `encryption`  ENUM('tls','ssl','none') NOT NULL DEFAULT 'tls',
    `username`    VARCHAR(150)  NOT NULL DEFAULT '',
    `password`    VARCHAR(255)  NOT NULL DEFAULT '',
    `from_email`  VARCHAR(150)  NOT NULL DEFAULT '',
    `from_name`   VARCHAR(100)  NOT NULL DEFAULT '',
    `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
    `updated_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                               ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert baris pertama (id=1) sebagai default kosong
INSERT INTO `smtp_settings` (`id`, `host`, `port`, `encryption`, `is_active`)
VALUES (1, 'smtp.gmail.com', 587, 'tls', 1);

-- Selesai! Silakan buka halaman Pengaturan → SMTP Gmail
-- untuk mengisi username, App Password, dan email pengirim.




-- Hapus data lama jika ada
DELETE FROM smtp_settings WHERE id = 1;

-- Insert dengan password sudah base64
INSERT INTO smtp_settings (id, host, port, encryption, username, password, from_email, from_name, is_active)
VALUES (
    1,
    'smtp.gmail.com',
    587,
    'tls',
    'MASUKKAN EMAIL SMTP',
    TO_BASE64('MASUKKAN PASSWORD SMTP'),
    'MASUKKAN EMAIL SMTP',
    'DailyFix',
    1
);