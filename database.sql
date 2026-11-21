-- =====================================================================
--  Sistem Rancang Reservasi Hall Berbasis Web
--  Skrip database MySQL 5.7+ / MariaDB 10.3+ (InnoDB, utf8mb4)
--
--  Cara pakai:  mysql -u root -p < database.sql
--  Skrip ini memakai CREATE ... IF NOT EXISTS dan TIDAK menghapus data.
--  Data contoh (bagian paling bawah) hanya untuk percobaan, jalankan sekali.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS db_reservasi_hall
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE db_reservasi_hall;

-- ---------------------------------------------------------------------
-- 1. users
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nama        VARCHAR(100) NOT NULL,
  email       VARCHAR(150) NOT NULL,
  password    VARCHAR(255) NOT NULL,             -- hasil password_hash()
  no_hp       VARCHAR(20)  NOT NULL,
  role        ENUM('customer','admin','owner') NOT NULL DEFAULT 'customer',
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 2. halls
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS halls (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nama_hall   VARCHAR(100) NOT NULL,
  kapasitas   INT UNSIGNED NOT NULL,
  harga_dasar BIGINT UNSIGNED NOT NULL,
  deskripsi   TEXT NULL,
  foto        VARCHAR(255) NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 3. fasilitas
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS fasilitas (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nama_fasilitas  VARCHAR(100) NOT NULL,
  harga_tambahan  BIGINT UNSIGNED NOT NULL,
  satuan          VARCHAR(30) NOT NULL DEFAULT 'paket',
  PRIMARY KEY (id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 4. reservasi
--    slot_terkunci: kolom turunan yang bernilai 1 HANYA saat status
--    'DP Terkonfirmasi' / 'Lunas', selain itu NULL. Unique index
--    (hall_id, tanggal_acara, slot_terkunci) membuat database sendiri
--    menolak dua reservasi terkunci pada hall & tanggal yang sama
--    (NULL tidak dihitung bentrok), jadi reservasi 'Pending' berganda
--    tetap diperbolehkan dan slot otomatis terbuka saat 'Dibatalkan'.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS reservasi (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  kode_booking   VARCHAR(30) NOT NULL,
  user_id        INT UNSIGNED NOT NULL,
  hall_id        INT UNSIGNED NOT NULL,
  tanggal_acara  DATE NOT NULL,
  jenis_acara    VARCHAR(50) NOT NULL,
  jumlah_tamu    INT UNSIGNED NOT NULL,
  total_harga    BIGINT UNSIGNED NOT NULL,
  nominal_dp     BIGINT UNSIGNED NOT NULL,
  sisa_bayar     BIGINT UNSIGNED NOT NULL,
  status         ENUM('Pending','DP Terkonfirmasi','Lunas','Dibatalkan') NOT NULL DEFAULT 'Pending',
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  slot_terkunci  TINYINT GENERATED ALWAYS AS
                   (CASE WHEN status IN ('DP Terkonfirmasi','Lunas') THEN 1 ELSE NULL END) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reservasi_kode (kode_booking),
  UNIQUE KEY uq_reservasi_slot (hall_id, tanggal_acara, slot_terkunci),
  KEY idx_reservasi_user (user_id),
  KEY idx_reservasi_tanggal (hall_id, tanggal_acara, status),
  CONSTRAINT fk_reservasi_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_reservasi_hall FOREIGN KEY (hall_id) REFERENCES halls (id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 5. detail_fasilitas_reservasi
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS detail_fasilitas_reservasi (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  reservasi_id        INT UNSIGNED NOT NULL,
  fasilitas_id        INT UNSIGNED NOT NULL,
  harga_saat_booking  BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_detail (reservasi_id, fasilitas_id),
  CONSTRAINT fk_detail_reservasi FOREIGN KEY (reservasi_id) REFERENCES reservasi (id) ON DELETE CASCADE,
  CONSTRAINT fk_detail_fasilitas FOREIGN KEY (fasilitas_id) REFERENCES fasilitas (id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 6. pembayaran_pembatalan
--    Kolom tambahan di luar daftar awal (semua boleh NULL):
--      persen_refund, dibatalkan_oleh, catatan_admin, tanggal_verifikasi
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pembayaran_pembatalan (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  reservasi_id       INT UNSIGNED NOT NULL,
  jenis_transaksi    ENUM('DP','Pelunasan','Refund') NOT NULL,
  nominal            BIGINT UNSIGNED NOT NULL,
  bukti_transfer     VARCHAR(255) NULL,
  alasan_batal       TEXT NULL,
  rekening_refund    VARCHAR(150) NULL,
  status_verifikasi  ENUM('Menunggu','Terverifikasi','Ditolak') NOT NULL DEFAULT 'Menunggu',
  tanggal_transaksi  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  persen_refund      TINYINT UNSIGNED NULL,
  dibatalkan_oleh    ENUM('Customer','Pengelola') NULL,
  catatan_admin      VARCHAR(255) NULL,
  tanggal_verifikasi DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_bayar_reservasi (reservasi_id),
  KEY idx_bayar_status (jenis_transaksi, status_verifikasi),
  CONSTRAINT fk_bayar_reservasi FOREIGN KEY (reservasi_id) REFERENCES reservasi (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =====================================================================
--  DATA CONTOH
--  Akun uji coba:
--    admin@hall.test     / Admin123!
--    owner@hall.test     / Owner123!
--    budi@hall.test      / Customer123!   (pelanggan)
--    sari@hall.test      / Customer123!   (pelanggan)
-- =====================================================================
INSERT INTO users (id, nama, email, password, no_hp, role) VALUES
 (1, 'Administrator Hall', 'admin@hall.test', '$2y$10$JvT.bDKbNCZTC4MBVLcvJ.JL.Jg6kRsJv9782aNfg1I7ds7X7lAx.', '081200000001', 'admin'),
 (2, 'Pimpinan Graha',     'owner@hall.test', '$2y$10$PXLEuGXICxLboo4abo8vk.a7Byym1eiLTKIfACDyUCOZ/2gFxzVvm', '081200000002', 'owner'),
 (3, 'Budi Santoso',       'budi@hall.test',  '$2y$10$fyw8vxrMuh3FbzHuDJZtne6YdnY8VHq6Cz55DlYJP3TI71KjpqiqK', '081200000003', 'customer'),
 (4, 'Sari Wulandari',     'sari@hall.test',  '$2y$10$fyw8vxrMuh3FbzHuDJZtne6YdnY8VHq6Cz55DlYJP3TI71KjpqiqK', '081200000004', 'customer');

INSERT INTO halls (id, nama_hall, kapasitas, harga_dasar, deskripsi, foto) VALUES
 (1, 'Grand Ballroom',        500, 25000000, 'Ballroom mewah ber-AC dengan panggung besar, cocok untuk resepsi pernikahan dan gala dinner. Parkir luas untuk 150 kendaraan.', NULL),
 (2, 'Hall Melati',           200, 12000000, 'Hall menengah dengan pencahayaan alami dan taman indoor. Ideal untuk resepsi intim, ulang tahun, dan seminar.', NULL),
 (3, 'Ruang Rapat Anggrek',    50,  3500000, 'Ruang rapat eksekutif dengan meja konferensi, AC, dan akses internet cepat.', NULL),
 (4, 'Hall Seminar Mawar',    150,  7500000, 'Hall bergaya teater dengan panggung, proyektor, dan sistem suara standar untuk seminar atau pelatihan.', NULL);

INSERT INTO fasilitas (id, nama_fasilitas, harga_tambahan, satuan) VALUES
 (1, 'Sound System Premium',        2000000, 'paket'),
 (2, 'Dekorasi Pelaminan',          6000000, 'paket'),
 (3, 'Proyektor & Layar',            750000, 'unit'),
 (4, 'Live Music Akustik',          3500000, 'sesi'),
 (5, 'Dokumentasi Foto & Video',    4000000, 'paket'),
 (6, 'Wi-Fi Dedicated',              500000, 'hari'),
 (7, 'Genset Cadangan',             1200000, 'unit');

-- Tanggal acara dibuat relatif terhadap hari impor agar selalu di masa depan.
INSERT INTO reservasi
 (id, kode_booking, user_id, hall_id, tanggal_acara, jenis_acara, jumlah_tamu, total_harga, nominal_dp, sisa_bayar, status) VALUES
 (1, 'HL-SAMPLE-0001', 3, 1, DATE_ADD(CURDATE(), INTERVAL 45 DAY), 'Wedding',     300, 35000000, 10500000,        0, 'Lunas'),
 (2, 'HL-SAMPLE-0002', 3, 2, DATE_ADD(CURDATE(), INTERVAL 20 DAY), 'Seminar',     120, 12750000,  3825000,  8925000, 'DP Terkonfirmasi'),
 (3, 'HL-SAMPLE-0003', 4, 3, DATE_ADD(CURDATE(), INTERVAL 10 DAY), 'Rapat',        30,  3500000,  1050000,  2450000, 'Pending'),
 (4, 'HL-SAMPLE-0004', 4, 1, DATE_ADD(CURDATE(), INTERVAL 45 DAY), 'Wedding',     250, 25000000,  7500000, 17500000, 'Pending'),   -- bentrok dengan #1
 (5, 'HL-SAMPLE-0005', 4, 4, DATE_ADD(CURDATE(), INTERVAL 60 DAY), 'Workshop',    100,  7500000,  2250000,  5250000, 'Dibatalkan'),
 (6, 'HL-SAMPLE-0006', 4, 2, DATE_ADD(CURDATE(), INTERVAL 40 DAY), 'Ulang Tahun',  80, 12000000,  3600000,  8400000, 'DP Terkonfirmasi');

INSERT INTO detail_fasilitas_reservasi (reservasi_id, fasilitas_id, harga_saat_booking) VALUES
 (1, 2, 6000000), (1, 5, 4000000),
 (2, 3,  750000);

INSERT INTO pembayaran_pembatalan
 (id, reservasi_id, jenis_transaksi, nominal, bukti_transfer, alasan_batal, rekening_refund,
  status_verifikasi, tanggal_transaksi, persen_refund, dibatalkan_oleh, tanggal_verifikasi) VALUES
 (1, 1, 'DP',        10500000, 'sample_bukti.png', NULL, NULL, 'Terverifikasi', DATE_SUB(NOW(), INTERVAL 20 DAY), NULL, NULL, DATE_SUB(NOW(), INTERVAL 19 DAY)),
 (2, 1, 'Pelunasan', 24500000, 'sample_bukti.png', NULL, NULL, 'Terverifikasi', DATE_SUB(NOW(), INTERVAL 5 DAY),  NULL, NULL, DATE_SUB(NOW(), INTERVAL 4 DAY)),
 (3, 2, 'DP',         3825000, 'sample_bukti.png', NULL, NULL, 'Terverifikasi', DATE_SUB(NOW(), INTERVAL 10 DAY), NULL, NULL, DATE_SUB(NOW(), INTERVAL 9 DAY)),
 (4, 3, 'DP',         1050000, 'sample_bukti.png', NULL, NULL, 'Menunggu',      DATE_SUB(NOW(), INTERVAL 1 DAY),  NULL, NULL, NULL),
 (5, 4, 'DP',         7500000, 'sample_bukti.png', NULL, NULL, 'Menunggu',      DATE_SUB(NOW(), INTERVAL 2 DAY),  NULL, NULL, NULL),
 (6, 5, 'DP',         2250000, 'sample_bukti.png', NULL, NULL, 'Terverifikasi', DATE_SUB(NOW(), INTERVAL 30 DAY), NULL, NULL, DATE_SUB(NOW(), INTERVAL 29 DAY)),
 (7, 5, 'Refund',     1687500, 'sample_bukti.png', 'Acara ditunda ke tahun depan.', 'BCA 8880011223 a.n. Sari Wulandari',
      'Terverifikasi', DATE_SUB(NOW(), INTERVAL 3 DAY), 75, 'Customer', DATE_SUB(NOW(), INTERVAL 2 DAY)),
 (8, 6, 'DP',         3600000, 'sample_bukti.png', NULL, NULL, 'Terverifikasi', DATE_SUB(NOW(), INTERVAL 8 DAY),  NULL, NULL, DATE_SUB(NOW(), INTERVAL 7 DAY)),
 (9, 6, 'Refund',     2700000, NULL, 'Rencana acara berubah.', 'BCA 8880011223 a.n. Sari Wulandari',
      'Menunggu', NOW(), 75, 'Customer', NULL);
