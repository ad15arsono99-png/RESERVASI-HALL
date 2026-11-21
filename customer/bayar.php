<?php
/**
 * Unggah bukti pembayaran: DP (status Pending) atau Pelunasan (status DP Terkonfirmasi).
 */
require __DIR__ . '/../includes/init.php';
$user = require_role('customer');
$pdo  = db();

/**
 * Menentukan tahap pembayaran yang boleh dilakukan saat ini.
 * @return array [tahap|null, nominal, pesan]
 */
function status_bayar(PDO $pdo, array $r): array
{
    $q = $pdo->prepare(
        "SELECT
           COALESCE(SUM(jenis_transaksi IN ('DP','Pelunasan') AND status_verifikasi = 'Menunggu'), 0) AS menunggu,
           COALESCE(SUM(jenis_transaksi = 'Refund' AND status_verifikasi = 'Menunggu' AND dibatalkan_oleh = 'Customer'), 0) AS ajuan
         FROM pembayaran_pembatalan WHERE reservasi_id = ?"
    );
    $q->execute([$r['id']]);
    $c = $q->fetch();

    if ($r['status'] === 'Dibatalkan') {
        return [null, 0, 'Reservasi ini sudah dibatalkan.'];
    }
    if ($r['status'] === 'Lunas') {
        return [null, 0, 'Reservasi sudah lunas. Terima kasih!'];
    }
    if ((int)$c['ajuan'] > 0) {
        return [null, 0, 'Pengajuan pembatalan Anda sedang diproses admin.'];
    }
    if ((int)$c['menunggu'] > 0) {
        return [null, 0, 'Bukti pembayaran Anda sedang menunggu verifikasi admin.'];
    }
    if ($r['tanggal_acara'] <= date('Y-m-d')) {
        return [null, 0, 'Tanggal acara sudah lewat atau hari ini; pembayaran online tidak dapat dilakukan.'];
    }
    if ($r['status'] === 'Pending') {
        if (tanggal_terkunci($pdo, (int)$r['hall_id'], $r['tanggal_acara'], (int)$r['id'])) {
            return [null, 0, 'Maaf, tanggal ini sudah dikunci oleh reservasi lain. Anda dapat membatalkan reservasi ini dari halaman Riwayat dan memilih tanggal lain.'];
        }
        return ['DP', (int)$r['nominal_dp'], ''];
    }
    if ($r['status'] === 'DP Terkonfirmasi' && (int)$r['sisa_bayar'] > 0) {
        return ['Pelunasan', (int)$r['sisa_bayar'], ''];
    }
    return [null, 0, ''];
}

$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT r.*, h.nama_hall FROM reservasi r JOIN halls h ON h.id = r.hall_id WHERE r.id = ? AND r.user_id = ?');
$st->execute([$id, $user['id']]);
$r = $st->fetch();
if (!$r) {
    flash('danger', 'Reservasi tidak ditemukan.');
    redirect('customer/riwayat.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $namaFile = null;
    try {
        $pdo->beginTransaction();

        // Kunci baris reservasi supaya klik ganda tidak membuat dua bukti
        $lock = $pdo->prepare('SELECT r.*, h.nama_hall FROM reservasi r JOIN halls h ON h.id = r.hall_id WHERE r.id = ? AND r.user_id = ? FOR UPDATE');
        $lock->execute([$id, $user['id']]);
        $r = $lock->fetch();
        if (!$r) {
            throw new RuntimeException('Reservasi tidak ditemukan.');
        }

        [$tahap, $nominal, $pesan] = status_bayar($pdo, $r);
        if (!$tahap) {
            throw new RuntimeException($pesan ?: 'Saat ini tidak ada pembayaran yang dapat diunggah.');
        }
        if (post('tahap') !== $tahap) {
            throw new RuntimeException('Tahap pembayaran telah berubah. Silakan periksa kembali halaman ini.');
        }

        [$ok, $res] = upload_image($_FILES['bukti'] ?? [], BUKTI_DIR, 'bukti');
        if (!$ok) {
            throw new RuntimeException($res);
        }
        $namaFile = $res;

        $pdo->prepare(
            "INSERT INTO pembayaran_pembatalan (reservasi_id, jenis_transaksi, nominal, bukti_transfer, status_verifikasi, tanggal_transaksi)
             VALUES (?, ?, ?, ?, 'Menunggu', NOW())"
        )->execute([$r['id'], $tahap, $nominal, $namaFile]);

        $pdo->commit();
        flash('success', 'Bukti ' . ($tahap === 'DP' ? 'DP' : 'pelunasan') . ' berhasil diunggah dan menunggu verifikasi admin.');
    } catch (RuntimeException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        hapus_file(BUKTI_DIR, $namaFile);
        flash('danger', $ex->getMessage());
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        hapus_file(BUKTI_DIR, $namaFile);
        error_log('Upload bukti gagal: ' . $ex->getMessage());
        flash('danger', 'Terjadi kesalahan saat menyimpan bukti pembayaran.');
    }
    redirect('customer/bayar.php?id=' . $id);
}

[$tahap, $nominal, $pesan] = status_bayar($pdo, $r);

$hist = $pdo->prepare('SELECT * FROM pembayaran_pembatalan WHERE reservasi_id = ? ORDER BY tanggal_transaksi DESC, id DESC');
$hist->execute([$r['id']]);
$riwayatBayar = $hist->fetchAll();

$pageTitle = 'Pembayaran ' . $r['kode_booking'];
include __DIR__ . '/../includes/header.php';
?>
<div class="container py-4">
    <a href="<?= e(url('customer/riwayat.php')) ?>" class="small text-decoration-none"><i class="bi bi-arrow-left"></i> Kembali ke riwayat</a>
    <div class="d-flex align-items-center gap-2 mt-2 mb-3">
        <h1 class="h3 mb-0">Pembayaran <?= e($r['kode_booking']) ?></h1>
        <?= badge_status($r['status']) ?>
    </div>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header">Rincian Reservasi</div>
                <div class="card-body">
                    <div class="sum-row"><span>Hall</span><strong><?= e($r['nama_hall']) ?></strong></div>
                    <div class="sum-row"><span>Tanggal acara</span><strong><?= e(tgl_indo($r['tanggal_acara'], true)) ?></strong></div>
                    <div class="sum-row"><span>Jenis acara</span><span><?= e($r['jenis_acara']) ?> (<?= (int)$r['jumlah_tamu'] ?> tamu)</span></div>
                    <div class="sum-row sum-total"><span>Total harga</span><span><?= rupiah($r['total_harga']) ?></span></div>
                    <div class="sum-row"><span>DP</span><span><?= rupiah($r['nominal_dp']) ?></span></div>
                    <div class="sum-row"><span>Sisa bayar</span><span><?= rupiah($r['sisa_bayar']) ?></span></div>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card mb-4">
                <div class="card-header">Unggah Bukti Pembayaran</div>
                <div class="card-body">
                    <?php if ($tahap): ?>
                        <div class="alert alert-info">
                            <div class="fw-semibold mb-1">Tahap: <?= $tahap === 'DP' ? 'Pembayaran DP' : 'Pelunasan' ?></div>
                            Transfer sebesar <strong><?= rupiah($nominal) ?></strong> ke:<br>
                            <strong><?= e(BANK_INFO) ?></strong>
                        </div>
                        <form method="post" enctype="multipart/form-data">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <input type="hidden" name="tahap" value="<?= e($tahap) ?>">
                            <div class="mb-3">
                                <label class="form-label" for="bukti">Bukti transfer (JPG / PNG / WEBP, maks. 2 MB)</label>
                                <input type="file" class="form-control" id="bukti" name="bukti" accept="image/jpeg,image/png,image/webp"
                                       data-maxmb="2" data-preview="#previewBukti" required>
                                <img id="previewBukti" class="d-none mt-2 rounded border" style="max-height:200px" alt="Pratinjau bukti">
                            </div>
                            <button class="btn btn-gold" type="submit"><i class="bi bi-upload"></i> Kirim Bukti <?= $tahap === 'DP' ? 'DP' : 'Pelunasan' ?></button>
                        </form>
                        <?php if ($tahap === 'DP'): ?>
                            <p class="small text-muted mt-3 mb-0">Tanggal acara akan terkunci untuk Anda setelah admin memverifikasi DP.</p>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="alert alert-secondary mb-0"><?= e($pesan ?: 'Tidak ada pembayaran yang perlu dilakukan saat ini.') ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header">Riwayat Transaksi</div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Waktu</th><th>Jenis</th><th class="text-end">Nominal</th><th>Status</th><th>Bukti</th></tr></thead>
                        <tbody>
                        <?php foreach ($riwayatBayar as $p): ?>
                            <tr>
                                <td class="small"><?= e(waktu_indo($p['tanggal_transaksi'])) ?></td>
                                <td><?= e($p['jenis_transaksi']) ?></td>
                                <td class="text-end"><?= rupiah($p['nominal']) ?></td>
                                <td>
                                    <?= badge_verif($p['status_verifikasi']) ?>
                                    <?php if ($p['catatan_admin']): ?><div class="small text-danger"><?= e($p['catatan_admin']) ?></div><?php endif; ?>
                                </td>
                                <td><?php if ($p['bukti_transfer']): ?><a target="_blank" rel="noopener" href="<?= e(url('bukti.php?id=' . (int)$p['id'])) ?>">Lihat</a><?php else: ?>-<?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$riwayatBayar): ?><tr><td colspan="5" class="text-center text-muted py-3">Belum ada transaksi.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
