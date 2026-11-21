<?php
/**
 * Menyajikan bukti transfer / bukti refund dari folder privat storage/bukti.
 * Hanya pemilik reservasi, admin, dan owner yang boleh melihat.
 */
require __DIR__ . '/includes/init.php';
$user = require_login();

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    http_response_code(404);
    exit('Tidak ditemukan.');
}

$st = db()->prepare(
    'SELECT p.bukti_transfer, r.user_id
     FROM pembayaran_pembatalan p JOIN reservasi r ON r.id = p.reservasi_id
     WHERE p.id = ?'
);
$st->execute([$id]);
$row = $st->fetch();

if (!$row || !$row['bukti_transfer']) {
    http_response_code(404);
    exit('Tidak ditemukan.');
}
if ($user['role'] === 'customer' && (int)$row['user_id'] !== $user['id']) {
    http_response_code(403);
    exit('Akses ditolak.');
}

$path = BUKTI_DIR . '/' . basename($row['bukti_transfer']);
if (!is_file($path)) {
    http_response_code(404);
    exit('File tidak ditemukan.');
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    http_response_code(415);
    exit('Tipe file tidak didukung.');
}

session_write_close();
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="bukti-' . $id . '"');
header('Cache-Control: private, max-age=3600');
readfile($path);
