<?php
require __DIR__ . '/../includes/init.php';
$user = require_role('customer');
$pdo  = db();
$today = date('Y-m-d');

$st = $pdo->prepare(
    'SELECT r.*, h.nama_hall FROM reservasi r JOIN halls h ON h.id = r.hall_id
     WHERE r.user_id = ? ORDER BY r.created_at DESC, r.id DESC'
);
$st->execute([$user['id']]);
$rows = $st->fetchAll();

$bayar = [];
$fasil = [];
if ($rows) {
    $ids = array_column($rows, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));

    $q = $pdo->prepare("SELECT * FROM pembayaran_pembatalan WHERE reservasi_id IN ($in) ORDER BY tanggal_transaksi, id");
    $q->execute($ids);
    foreach ($q->fetchAll() as $p) {
        $bayar[$p['reservasi_id']][] = $p;
    }

    $q = $pdo->prepare(
        "SELECT d.reservasi_id, f.nama_fasilitas, d.harga_saat_booking
         FROM detail_fasilitas_reservasi d JOIN fasilitas f ON f.id = d.fasilitas_id
         WHERE d.reservasi_id IN ($in)"
    );
    $q->execute($ids);
    foreach ($q->fetchAll() as $f) {
        $fasil[$f['reservasi_id']][] = $f;
    }
}

$pageTitle    = 'Riwayat Reservasi';
$extraScripts = [url('assets/js/riwayat.js')];
include __DIR__ . '/../includes/header.php';
?>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">Riwayat Reservasi</h1>
        <a class="btn btn-gold" href="<?= e(url('booking.php')) ?>"><i class="bi bi-plus-lg"></i> Reservasi Baru</a>
    </div>

    <?php if (!$rows): ?>
        <div class="alert alert-info">Anda belum memiliki reservasi. <a href="<?= e(url('booking.php')) ?>">Buat reservasi pertama Anda</a>.</div>
    <?php endif; ?>

    <?php foreach ($rows as $r):
        $list = $bayar[$r['id']] ?? [];
        $verifDp = $verifPel = 0;
        $menunggu = $ajuan = false;
        $refundRow = null;
        foreach ($list as $p) {
            if ($p['jenis_transaksi'] !== 'Refund' && $p['status_verifikasi'] === 'Terverifikasi') {
                if ($p['jenis_transaksi'] === 'DP') { $verifDp += (int)$p['nominal']; } else { $verifPel += (int)$p['nominal']; }
            }
            if ($p['jenis_transaksi'] !== 'Refund' && $p['status_verifikasi'] === 'Menunggu') { $menunggu = true; }
            if ($p['jenis_transaksi'] === 'Refund') {
                if ($p['status_verifikasi'] === 'Menunggu' && $p['dibatalkan_oleh'] === 'Customer') { $ajuan = true; }
                if ($p['status_verifikasi'] !== 'Ditolak') { $refundRow = $p; }
            }
        }
        $aktif      = in_array($r['status'], ['Pending', 'DP Terkonfirmasi', 'Lunas'], true);
        $mendatang  = $r['tanggal_acara'] > $today;
        $bisaBatal  = $aktif && $mendatang && !$menunggu && !$ajuan;
        $perluBayar = ($r['status'] === 'Pending' || ($r['status'] === 'DP Terkonfirmasi' && (int)$r['sisa_bayar'] > 0)) && !$ajuan;
    ?>
    <div class="card mb-4">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <span class="fw-semibold"><?= e($r['kode_booking']) ?></span>
                <span class="text-muted small ms-2">dibuat <?= e(waktu_indo($r['created_at'])) ?></span>
            </div>
            <?= badge_status($r['status']) ?>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="h5 mb-1"><?= e($r['nama_hall']) ?></div>
                    <div><i class="bi bi-calendar-event"></i> <?= e(tgl_indo($r['tanggal_acara'], true)) ?></div>
                    <div class="text-muted small"><?= e($r['jenis_acara']) ?> · <?= (int)$r['jumlah_tamu'] ?> tamu</div>
                    <div class="small mt-2">
                        <span class="text-muted">Fasilitas:</span>
                        <?php if (!empty($fasil[$r['id']])): ?>
                            <?= e(implode(', ', array_column($fasil[$r['id']], 'nama_fasilitas'))) ?>
                        <?php else: ?>-<?php endif; ?>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="sum-row py-0"><span>Total harga</span><strong><?= rupiah($r['total_harga']) ?></strong></div>
                    <div class="sum-row py-0"><span>DP</span><span><?= rupiah($r['nominal_dp']) ?></span></div>
                    <div class="sum-row py-0"><span>Sisa bayar</span><span><?= rupiah($r['sisa_bayar']) ?></span></div>
                </div>
            </div>

            <?php if ($list): ?>
                <div class="table-responsive mt-3">
                    <table class="table table-sm small mb-0">
                        <thead class="table-light"><tr><th>Waktu</th><th>Transaksi</th><th class="text-end">Nominal</th><th>Status</th><th>Keterangan</th><th>Bukti</th></tr></thead>
                        <tbody>
                        <?php foreach ($list as $p): ?>
                            <tr>
                                <td><?= e(waktu_indo($p['tanggal_transaksi'])) ?></td>
                                <td><?= e($p['jenis_transaksi']) ?><?php if ($p['jenis_transaksi'] === 'Refund' && $p['persen_refund'] !== null): ?> (<?= (int)$p['persen_refund'] ?>% DP)<?php endif; ?></td>
                                <td class="text-end"><?= rupiah($p['nominal']) ?></td>
                                <td><?= badge_verif($p['status_verifikasi']) ?></td>
                                <td>
                                    <?php if ($p['jenis_transaksi'] === 'Refund'): ?>
                                        <?= e($p['dibatalkan_oleh'] === 'Pengelola' ? 'Dibatalkan oleh pengelola. ' : '') ?><?= e((string)$p['alasan_batal']) ?>
                                    <?php endif; ?>
                                    <?php if ($p['catatan_admin']): ?><div class="text-danger"><?= e($p['catatan_admin']) ?></div><?php endif; ?>
                                </td>
                                <td><?php if ($p['bukti_transfer']): ?><a target="_blank" rel="noopener" href="<?= e(url('bukti.php?id=' . (int)$p['id'])) ?>">Lihat</a><?php else: ?>-<?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if ($ajuan): ?>
                <div class="alert alert-warning mt-3 mb-0 py-2">Pengajuan pembatalan Anda sedang diproses admin.</div>
            <?php endif; ?>
        </div>

        <?php if ($aktif): ?>
        <div class="card-footer bg-white d-flex flex-wrap gap-2 align-items-center">
            <?php if ($perluBayar && !$menunggu): ?>
                <a class="btn btn-gold btn-sm" href="<?= e(url('customer/bayar.php?id=' . (int)$r['id'])) ?>">
                    <i class="bi bi-cash-coin"></i> <?= $r['status'] === 'Pending' ? 'Bayar DP' : 'Bayar Pelunasan' ?>
                </a>
            <?php elseif ($menunggu): ?>
                <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('customer/bayar.php?id=' . (int)$r['id'])) ?>">Pembayaran menunggu verifikasi</a>
            <?php endif; ?>

            <?php if ($bisaBatal): ?>
                <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#modalBatal"
                        data-id="<?= (int)$r['id'] ?>"
                        data-kode="<?= e($r['kode_booking']) ?>"
                        data-tanggal="<?= e($r['tanggal_acara']) ?>"
                        data-tanggal-teks="<?= e(tgl_indo($r['tanggal_acara'])) ?>"
                        data-dp="<?= $verifDp ?>"
                        data-pelunasan="<?= $verifPel ?>">
                    <i class="bi bi-x-circle"></i> Ajukan Pembatalan
                </button>
            <?php elseif ($aktif && $mendatang && $menunggu): ?>
                <span class="small text-muted">Pembatalan dapat diajukan setelah pembayaran Anda diverifikasi.</span>
            <?php elseif ($aktif && !$mendatang): ?>
                <span class="small text-muted">Acara sudah berlangsung / hari ini.</span>
            <?php endif; ?>
        </div>
        <?php elseif ($r['status'] === 'Dibatalkan' && $refundRow): ?>
        <div class="card-footer bg-white small">
            Refund: <strong><?= rupiah($refundRow['nominal']) ?></strong> —
            <?= $refundRow['status_verifikasi'] === 'Terverifikasi' ? ((int)$refundRow['nominal'] > 0 ? 'sudah ditransfer' : 'tidak ada refund') : 'sedang diproses admin' ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>

<!-- Modal pembatalan -->
<div class="modal fade" id="modalBatal" tabindex="-1" aria-labelledby="modalBatalLabel" aria-hidden="true"
     data-today="<?= e($today) ?>" data-refund-pelunasan="<?= (int)REFUND_PELUNASAN_PERSEN ?>">
    <div class="modal-dialog">
        <form class="modal-content" method="post" action="<?= e(url('customer/proses_batal.php')) ?>" data-confirm="Yakin ingin mengajukan pembatalan? Tindakan ini tidak dapat dibatalkan.">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="">
            <div class="modal-header">
                <h2 class="modal-title h5" id="modalBatalLabel">Ajukan Pembatalan</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">Reservasi <strong id="batalKode"></strong> — acara <strong id="batalTanggal"></strong> (<span id="batalSelisih"></span> lagi).</p>
                <div class="alert alert-light border small" id="batalRincian"></div>
                <div class="mb-3">
                    <label class="form-label" for="alasan_batal">Alasan pembatalan</label>
                    <textarea class="form-control" id="alasan_batal" name="alasan_batal" rows="3" minlength="10" maxlength="500" required></textarea>
                </div>
                <div class="mb-1" id="grupRekening">
                    <label class="form-label" for="rekening_refund">Rekening tujuan refund</label>
                    <input class="form-control" id="rekening_refund" name="rekening_refund" maxlength="100" placeholder="Contoh: BCA 1234567890 a.n. Nama Anda">
                </div>
                <div class="small text-muted mt-2">
                    Kebijakan refund DP: &gt;30 hari 75% · 14–30 hari 50% · &lt;14 hari 0%. Nominal resmi dihitung ulang oleh sistem saat pengajuan dikirim.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Kembali</button>
                <button type="submit" class="btn btn-danger">Kirim Pengajuan</button>
            </div>
        </form>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
