<?php
/**
 * Aksi admin yang mengubah data. Semuanya berjalan dalam transaksi dan mengunci
 * baris hall lalu reservasi (urutan sama di semua fungsi, mencegah deadlock).
 * Kesalahan bisnis dilempar sebagai RuntimeException (pesannya aman ditampilkan).
 */

function dalam_transaksi(PDO $pdo, callable $fn)
{
    $pdo->beginTransaction();
    try {
        $hasil = $fn();
        $pdo->commit();
        return $hasil;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e->getCode() === '23000') {
            // unique index (hall, tanggal, slot_terkunci) menolak dua reservasi terkunci pada tanggal yang sama
            throw new RuntimeException('Perubahan ditolak database: tanggal tersebut sudah terkunci oleh reservasi lain.');
        }
        throw $e;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function kunci_reservasi(PDO $pdo, int $reservasiId): array
{
    $st = $pdo->prepare('SELECT hall_id FROM reservasi WHERE id = ?');
    $st->execute([$reservasiId]);
    $hallId = $st->fetchColumn();
    if (!$hallId) {
        throw new RuntimeException('Reservasi tidak ditemukan.');
    }
    $pdo->prepare('SELECT id FROM halls WHERE id = ? FOR UPDATE')->execute([$hallId]);
    $st = $pdo->prepare('SELECT * FROM reservasi WHERE id = ? FOR UPDATE');
    $st->execute([$reservasiId]);
    return $st->fetch();
}

function reservasi_id_dari_pembayaran(PDO $pdo, int $payId): int
{
    $st = $pdo->prepare('SELECT reservasi_id FROM pembayaran_pembatalan WHERE id = ?');
    $st->execute([$payId]);
    $rid = $st->fetchColumn();
    if (!$rid) {
        throw new RuntimeException('Data transaksi tidak ditemukan.');
    }
    return (int)$rid;
}

function kunci_pembayaran(PDO $pdo, int $payId): array
{
    $st = $pdo->prepare('SELECT * FROM pembayaran_pembatalan WHERE id = ? FOR UPDATE');
    $st->execute([$payId]);
    $p = $st->fetch();
    if (!$p) {
        throw new RuntimeException('Data transaksi tidak ditemukan.');
    }
    return $p;
}

/** Verifikasi DP / Pelunasan -> status reservasi berubah. */
function aksi_bayar_terima(PDO $pdo, int $payId): string
{
    return dalam_transaksi($pdo, function () use ($pdo, $payId) {
        $r = kunci_reservasi($pdo, reservasi_id_dari_pembayaran($pdo, $payId));
        $p = kunci_pembayaran($pdo, $payId);

        if (!in_array($p['jenis_transaksi'], ['DP', 'Pelunasan'], true) || $p['status_verifikasi'] !== 'Menunggu') {
            throw new RuntimeException('Transaksi ini sudah diproses sebelumnya.');
        }

        if ($p['jenis_transaksi'] === 'DP') {
            if ($r['status'] !== 'Pending') {
                throw new RuntimeException('Status reservasi bukan Pending, DP tidak dapat diverifikasi.');
            }
            if ($r['tanggal_acara'] <= date('Y-m-d')) {
                throw new RuntimeException('Tanggal acara sudah lewat. Gunakan "Batalkan & Refund 100%".');
            }
            if (tanggal_terkunci($pdo, (int)$r['hall_id'], $r['tanggal_acara'], (int)$r['id'])) {
                throw new RuntimeException('Jadwal bentrok: tanggal ini sudah terkunci oleh reservasi lain. Gunakan "Batalkan & Refund 100%" untuk mengembalikan DP.');
            }
            $sisa   = max((int)$r['total_harga'] - (int)$p['nominal'], 0);
            $status = $sisa === 0 ? 'Lunas' : 'DP Terkonfirmasi';
            $pesan  = 'DP diverifikasi. Tanggal ' . tgl_indo($r['tanggal_acara']) . ' kini terkunci untuk ' . $r['kode_booking'] . '.';
        } else {
            if ($r['status'] !== 'DP Terkonfirmasi') {
                throw new RuntimeException('Pelunasan hanya dapat diverifikasi untuk reservasi berstatus DP Terkonfirmasi.');
            }
            $sisa   = 0;
            $status = 'Lunas';
            $pesan  = 'Pelunasan diverifikasi. ' . $r['kode_booking'] . ' berstatus Lunas.';
        }

        $pdo->prepare("UPDATE pembayaran_pembatalan SET status_verifikasi = 'Terverifikasi', tanggal_verifikasi = NOW(), catatan_admin = NULL WHERE id = ?")
            ->execute([$payId]);
        $pdo->prepare('UPDATE reservasi SET status = ?, sisa_bayar = ? WHERE id = ?')
            ->execute([$status, $sisa, $r['id']]);
        return $pesan;
    });
}

function aksi_bayar_tolak(PDO $pdo, int $payId, string $catatan): string
{
    if (mb_strlen($catatan) < 5 || mb_strlen($catatan) > 255) {
        throw new RuntimeException('Alasan penolakan wajib diisi (5–255 karakter).');
    }
    return dalam_transaksi($pdo, function () use ($pdo, $payId, $catatan) {
        kunci_reservasi($pdo, reservasi_id_dari_pembayaran($pdo, $payId));
        $p = kunci_pembayaran($pdo, $payId);
        if (!in_array($p['jenis_transaksi'], ['DP', 'Pelunasan'], true) || $p['status_verifikasi'] !== 'Menunggu') {
            throw new RuntimeException('Transaksi ini sudah diproses sebelumnya.');
        }
        $pdo->prepare("UPDATE pembayaran_pembatalan SET status_verifikasi = 'Ditolak', tanggal_verifikasi = NOW(), catatan_admin = ? WHERE id = ?")
            ->execute([$catatan, $payId]);
        return 'Bukti pembayaran ditolak. Pelanggan dapat mengunggah ulang.';
    });
}

/**
 * Menyetujui pembatalan pelanggan / menyelesaikan refund pembatalan pengelola.
 * Jika nominal refund > 0 wajib rekening + bukti transfer refund.
 */
function aksi_proses_refund(PDO $pdo, int $refundId, string $rekening, array $file): string
{
    $namaFile = null;
    try {
        return dalam_transaksi($pdo, function () use ($pdo, $refundId, $rekening, $file, &$namaFile) {
            $r = kunci_reservasi($pdo, reservasi_id_dari_pembayaran($pdo, $refundId));
            $p = kunci_pembayaran($pdo, $refundId);

            if ($p['jenis_transaksi'] !== 'Refund' || $p['status_verifikasi'] !== 'Menunggu') {
                throw new RuntimeException('Pengajuan ini sudah diproses sebelumnya.');
            }

            $nominal = (int)$p['nominal'];
            $rek     = $rekening !== '' ? $rekening : (string)$p['rekening_refund'];
            $bukti   = null;

            if ($nominal > 0) {
                if (mb_strlen($rek) < 6 || mb_strlen($rek) > 100) {
                    throw new RuntimeException('Rekening tujuan refund wajib diisi (6–100 karakter).');
                }
                [$ok, $res] = upload_image($file, BUKTI_DIR, 'refund');
                if (!$ok) {
                    throw new RuntimeException('Bukti transfer refund: ' . $res);
                }
                $namaFile = $res;
                $bukti    = $res;
            }

            $pdo->prepare(
                "UPDATE pembayaran_pembatalan
                 SET status_verifikasi = 'Terverifikasi', bukti_transfer = ?, rekening_refund = ?, tanggal_verifikasi = NOW(), catatan_admin = NULL
                 WHERE id = ?"
            )->execute([$bukti, $nominal > 0 ? $rek : $p['rekening_refund'], $refundId]);

            if ($r['status'] !== 'Dibatalkan') {
                $pdo->prepare("UPDATE reservasi SET status = 'Dibatalkan' WHERE id = ?")->execute([$r['id']]);
            }
            return $nominal > 0
                ? 'Refund ' . rupiah($nominal) . ' tercatat. ' . $r['kode_booking'] . ' dibatalkan dan tanggalnya kembali tersedia.'
                : 'Pembatalan disetujui (tanpa refund). ' . $r['kode_booking'] . ' dibatalkan dan tanggalnya kembali tersedia.';
        });
    } catch (Throwable $e) {
        hapus_file(BUKTI_DIR, $namaFile);
        throw $e;
    }
}

function aksi_tolak_pembatalan(PDO $pdo, int $refundId, string $catatan): string
{
    if (mb_strlen($catatan) < 5 || mb_strlen($catatan) > 255) {
        throw new RuntimeException('Alasan penolakan wajib diisi (5–255 karakter).');
    }
    return dalam_transaksi($pdo, function () use ($pdo, $refundId, $catatan) {
        kunci_reservasi($pdo, reservasi_id_dari_pembayaran($pdo, $refundId));
        $p = kunci_pembayaran($pdo, $refundId);
        if ($p['jenis_transaksi'] !== 'Refund' || $p['status_verifikasi'] !== 'Menunggu' || $p['dibatalkan_oleh'] !== 'Customer') {
            throw new RuntimeException('Pengajuan ini tidak dapat ditolak.');
        }
        $pdo->prepare("UPDATE pembayaran_pembatalan SET status_verifikasi = 'Ditolak', tanggal_verifikasi = NOW(), catatan_admin = ? WHERE id = ?")
            ->execute([$catatan, $refundId]);
        return 'Pengajuan pembatalan ditolak. Reservasi tetap berjalan.';
    });
}

/**
 * Pembatalan oleh pihak pengelola: refund 100% (DP + pelunasan yang sudah diterima).
 * Pembayaran yang masih "Menunggu" dianggap sudah diterima dan ikut dikembalikan.
 * Status langsung menjadi Dibatalkan sehingga tanggal terbuka kembali.
 */
function aksi_batal_pengelola(PDO $pdo, int $reservasiId, string $alasan): string
{
    if (mb_strlen($alasan) < 5 || mb_strlen($alasan) > 500) {
        throw new RuntimeException('Alasan pembatalan wajib diisi (5–500 karakter).');
    }
    return dalam_transaksi($pdo, function () use ($pdo, $reservasiId, $alasan) {
        $r = kunci_reservasi($pdo, $reservasiId);
        if (!in_array($r['status'], ['Pending', 'DP Terkonfirmasi', 'Lunas'], true)) {
            throw new RuntimeException('Reservasi ini sudah dibatalkan.');
        }

        $pdo->prepare(
            "UPDATE pembayaran_pembatalan
             SET status_verifikasi = 'Terverifikasi', tanggal_verifikasi = NOW(),
                 catatan_admin = 'Dianggap diterima saat pembatalan oleh pengelola'
             WHERE reservasi_id = ? AND jenis_transaksi IN ('DP','Pelunasan') AND status_verifikasi = 'Menunggu'"
        )->execute([$r['id']]);

        $pdo->prepare(
            "UPDATE pembayaran_pembatalan
             SET status_verifikasi = 'Ditolak', tanggal_verifikasi = NOW(), catatan_admin = 'Digantikan pembatalan oleh pengelola'
             WHERE reservasi_id = ? AND jenis_transaksi = 'Refund' AND status_verifikasi = 'Menunggu'"
        )->execute([$r['id']]);

        $bayar = total_terbayar($pdo, (int)$r['id']);
        $hit   = hitung_refund($bayar['dp'], $bayar['pelunasan'], 0, 'Pengelola');

        $pdo->prepare("UPDATE reservasi SET status = 'Dibatalkan' WHERE id = ?")->execute([$r['id']]);

        $perluTransfer = $hit['total'] > 0;
        $pdo->prepare(
            "INSERT INTO pembayaran_pembatalan
             (reservasi_id, jenis_transaksi, nominal, alasan_batal, status_verifikasi, tanggal_transaksi, persen_refund, dibatalkan_oleh, tanggal_verifikasi)
             VALUES (?, 'Refund', ?, ?, ?, NOW(), 100, 'Pengelola', ?)"
        )->execute([
            $r['id'], $hit['total'], $alasan,
            $perluTransfer ? 'Menunggu' : 'Terverifikasi',
            $perluTransfer ? null : date('Y-m-d H:i:s'),
        ]);

        return $perluTransfer
            ? $r['kode_booking'] . ' dibatalkan. Refund 100% ' . rupiah($hit['total']) . ' menunggu Anda transfer (tab "Refund & Pembatalan").'
            : $r['kode_booking'] . ' dibatalkan. Tidak ada pembayaran yang perlu dikembalikan.';
    });
}
