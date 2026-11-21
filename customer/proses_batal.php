<?php
/**
 * Memproses pengajuan pembatalan oleh pelanggan.
 *
 * - Selisih hari dihitung dari TANGGAL PENGAJUAN (hari ini) ke tanggal acara.
 * - Refund dihitung di server dari pembayaran yang sudah terverifikasi (nilai dari klien diabaikan).
 * - Jika belum ada pembayaran terverifikasi, reservasi langsung dibatalkan (tanpa refund).
 * - Jika ada uang yang harus dikembalikan / dihanguskan, pengajuan masuk antrean admin
 *   (admin/verifikasi.php); slot tanggal baru terbuka saat admin menyetujui (status Dibatalkan).
 */
require __DIR__ . '/../includes/init.php';
$user = require_role('customer');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('customer/riwayat.php');
}
csrf_verify();

$pdo       = db();
$id        = (int)post('id');
$alasan    = post('alasan_batal');
$rekening  = preg_replace('/\s+/', ' ', post('rekening_refund'));

try {
    if (mb_strlen($alasan) < 10 || mb_strlen($alasan) > 500) {
        throw new RuntimeException('Alasan pembatalan harus 10–500 karakter.');
    }
    if (mb_strlen($rekening) > 100) {
        throw new RuntimeException('Data rekening terlalu panjang (maks. 100 karakter).');
    }

    $pdo->beginTransaction();

    $st = $pdo->prepare('SELECT * FROM reservasi WHERE id = ? AND user_id = ? FOR UPDATE');
    $st->execute([$id, $user['id']]);
    $r = $st->fetch();

    if (!$r) {
        throw new RuntimeException('Reservasi tidak ditemukan.');
    }
    if (!in_array($r['status'], ['Pending', 'DP Terkonfirmasi', 'Lunas'], true)) {
        throw new RuntimeException('Reservasi ini tidak dapat dibatalkan.');
    }
    if ($r['tanggal_acara'] <= date('Y-m-d')) {
        throw new RuntimeException('Acara sudah berlangsung atau hari ini, pembatalan tidak dapat diajukan.');
    }

    $cek = $pdo->prepare(
        "SELECT
           COALESCE(SUM(jenis_transaksi IN ('DP','Pelunasan') AND status_verifikasi = 'Menunggu'), 0) AS menunggu,
           COALESCE(SUM(jenis_transaksi = 'Refund' AND status_verifikasi = 'Menunggu'), 0) AS ajuan
         FROM pembayaran_pembatalan WHERE reservasi_id = ?"
    );
    $cek->execute([$r['id']]);
    $c = $cek->fetch();
    if ((int)$c['ajuan'] > 0) {
        throw new RuntimeException('Sudah ada pengajuan pembatalan yang sedang diproses.');
    }
    if ((int)$c['menunggu'] > 0) {
        throw new RuntimeException('Ada pembayaran yang masih menunggu verifikasi admin. Pembatalan dapat diajukan setelah diverifikasi.');
    }

    $bayar   = total_terbayar($pdo, (int)$r['id']);
    $selisih = selisih_hari(date('Y-m-d'), $r['tanggal_acara']);
    $hit     = hitung_refund($bayar['dp'], $bayar['pelunasan'], $selisih, 'Customer');

    if ($bayar['dp'] + $bayar['pelunasan'] === 0) {
        // Belum ada uang masuk: batalkan langsung
        $pdo->prepare("UPDATE reservasi SET status = 'Dibatalkan' WHERE id = ?")->execute([$r['id']]);
        $pdo->prepare(
            "INSERT INTO pembayaran_pembatalan
             (reservasi_id, jenis_transaksi, nominal, alasan_batal, status_verifikasi, tanggal_transaksi, persen_refund, dibatalkan_oleh, tanggal_verifikasi)
             VALUES (?, 'Refund', 0, ?, 'Terverifikasi', NOW(), 0, 'Customer', NOW())"
        )->execute([$r['id'], $alasan]);
        $pdo->commit();
        flash('success', 'Reservasi dibatalkan. Tanggal acara kembali tersedia untuk pelanggan lain.');
        redirect('customer/riwayat.php');
    }

    if ($hit['total'] > 0 && mb_strlen($rekening) < 6) {
        throw new RuntimeException('Rekening tujuan refund wajib diisi (bank, nomor rekening, atas nama).');
    }

    $pdo->prepare(
        "INSERT INTO pembayaran_pembatalan
         (reservasi_id, jenis_transaksi, nominal, alasan_batal, rekening_refund, status_verifikasi, tanggal_transaksi, persen_refund, dibatalkan_oleh)
         VALUES (?, 'Refund', ?, ?, ?, 'Menunggu', NOW(), ?, 'Customer')"
    )->execute([$r['id'], $hit['total'], $alasan, $hit['total'] > 0 ? $rekening : null, $hit['persen']]);

    $pdo->commit();

    $pesan = 'Pengajuan pembatalan terkirim. ';
    if ($hit['total'] > 0) {
        $pesan .= 'Estimasi refund ' . rupiah($hit['total']) . ' (' . $hit['persen'] . '% dari DP'
               . ($hit['refund_pelunasan'] > 0 ? ' + pelunasan' : '') . ') akan ditransfer setelah admin memprosesnya.';
    } else {
        $pesan .= 'Karena pembatalan dilakukan kurang dari 14 hari sebelum acara, DP tidak dapat dikembalikan.';
    }
    flash('success', $pesan);
} catch (RuntimeException $ex) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('danger', $ex->getMessage());
} catch (PDOException $ex) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Pembatalan gagal: ' . $ex->getMessage());
    flash('danger', 'Terjadi kesalahan saat memproses pembatalan.');
}
redirect('customer/riwayat.php');
