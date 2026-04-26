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