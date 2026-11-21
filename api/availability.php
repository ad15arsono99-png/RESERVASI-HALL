<?php
/**
 * GET api/availability.php?hall_id=1&bulan=2026-12
 * Mengembalikan tanggal yang terkunci (status DP Terkonfirmasi / Lunas) pada hall & bulan tersebut.
 * Endpoint publik, tidak memuat data pribadi apa pun.
 */
require __DIR__ . '/../includes/init.php';
session_write_close();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$hallId = filter_input(INPUT_GET, 'hall_id', FILTER_VALIDATE_INT);
$bulan  = get_str('bulan');

if (!$hallId || $hallId < 1 || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $bulan)) {
    http_response_code(400);
    echo json_encode(['error' => 'Parameter tidak valid']);
    exit;
}

$awal  = $bulan . '-01';
$akhir = date('Y-m-t', strtotime($awal));

$st = db()->prepare(
    "SELECT tanggal_acara FROM reservasi
     WHERE hall_id = ? AND tanggal_acara BETWEEN ? AND ? AND status IN ('DP Terkonfirmasi','Lunas')"
);
$st->execute([$hallId, $awal, $akhir]);

echo json_encode([
    'today'  => date('Y-m-d'),
    'locked' => array_column($st->fetchAll(), 'tanggal_acara'),
]);
