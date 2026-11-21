<?php
/**
 * Konfigurasi aplikasi Sistem Reservasi Hall.
 * Sesuaikan nilai di bawah dengan lingkungan Anda.
 */

define('APP_NAME', 'Graha Serbaguna');

// Folder aplikasi relatif terhadap document root (tanpa slash di akhir).
// Contoh: http://localhost/reservasi-hall  => '/reservasi-hall'
// Jika dipasang di root domain, isi dengan ''.
define('BASE_URL', '/reservasi-hall');

define('ROOT_PATH', dirname(__DIR__));
define('STORAGE_PATH', ROOT_PATH . '/storage');          // di luar akses web (lihat storage/.htaccess)
define('BUKTI_DIR', STORAGE_PATH . '/bukti');            // bukti transfer & bukti refund
define('HALL_DIR', ROOT_PATH . '/assets/uploads/halls'); // foto hall (publik)
define('MAX_UPLOAD_BYTES', 2 * 1024 * 1024);             // 2 MB

// Database
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'db_reservasi_hall');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Aturan bisnis
define('DP_PERSEN', 30);                // minimal DP (% dari total harga)
define('REFUND_PELUNASAN_PERSEN', 100); // jika pelanggan sudah melunasi lalu batal, bagian pelunasan dikembalikan sebesar ini
define('JENIS_ACARA', ['Wedding', 'Rapat', 'Seminar', 'Ulang Tahun', 'Gathering', 'Workshop', 'Lainnya']);

// Informasi rekening tujuan pembayaran (ditampilkan ke pelanggan)
define('BANK_INFO', 'Bank BCA 123 456 7890 a.n. PT Graha Serbaguna Nusantara');
