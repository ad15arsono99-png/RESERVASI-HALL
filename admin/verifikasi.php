<?php
/**
 * Panel admin: verifikasi pembayaran (DP / Pelunasan) dan proses pembatalan & refund.
 */
require __DIR__ . '/../includes/init.php';
require __DIR__ . '/../includes/admin_actions.php';
$admin = require_role('admin');
$pdo   = db();

$tab = get_str('tab');
$tab = in_array($tab, ['bayar', 'refund', 'riwayat'], true) ? $tab : 'bayar';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $aksi   = post('aksi');
    $id     = (int)post('id');
    $balik  = post('kembali') === 'dashboard' ? 'admin/dashboard.php' : 'admin/verifikasi.php?tab=' . $tab;
    try {
        switch ($aksi) {
            case 'bayar_terima':
                $msg = aksi_bayar_terima($pdo, $id);
                break;
            case 'bayar_tolak':
                $msg = aksi_bayar_tolak($pdo, $id, post('catatan'));
                break;
            case 'proses_refund':
                $msg = aksi_proses_refund($pdo, $id, post('rekening_refund'), $_FILES['bukti'] ?? []);
                break;
            case 'tolak_batal':
                $msg = aksi_tolak_pembatalan($pdo, $id, post('catatan'));
                break;
            case 'batal_pengelola':
                $msg = aksi_batal_pengelola($pdo, (int)post('reservasi_id'), post('alasan'));
                break;
            default:
                throw new RuntimeException('Aksi tidak dikenal.');
        }
        flash('success', $msg);
    } catch (RuntimeException $ex) {
        flash('danger', $ex->getMessage());
    } catch (Throwable $ex) {
        error_log('Aksi admin gagal: ' . $ex->getMessage());
        flash('danger', 'Terjadi kesalahan sistem. Perubahan tidak disimpan.');
    }
    redirect($balik);
}

$base = "FROM pembayaran_pembatalan p
         JOIN reservasi r ON r.id = p.reservasi_id
         JOIN halls h ON h.id = r.hall_id
         JOIN users u ON u.id = r.user_id";

$bayar = $pdo->query(
    "SELECT p.*, r.kode_booking, r.tanggal_acara, r.total_harga, r.nominal_dp, r.sisa_bayar,
            r.status AS status_reservasi, h.nama_hall, u.nama, u.no_hp,
            EXISTS(SELECT 1 FROM reservasi x
                   WHERE x.hall_id = r.hall_id AND x.tanggal_acara = r.tanggal_acara AND x.id <> r.id
                     AND x.status IN ('DP Terkonfirmasi','Lunas')) AS bentrok
     $base
     WHERE p.jenis_transaksi IN ('DP','Pelunasan') AND p.status_verifikasi = 'Menunggu'
     ORDER BY p.tanggal_transaksi"
)->fetchAll();

$refund = $pdo->query(
    "SELECT p.*, r.kode_booking, r.tanggal_acara, r.status AS status_reservasi, h.nama_hall, u.nama, u.no_hp
     $base
     WHERE p.jenis_transaksi = 'Refund' AND p.status_verifikasi = 'Menunggu'
     ORDER BY p.tanggal_transaksi"
)->fetchAll();

$riwayat = $tab === 'riwayat' ? $pdo->query(
    "SELECT p.*, r.kode_booking, h.nama_hall, u.nama
     $base
     WHERE p.status_verifikasi <> 'Menunggu'
     ORDER BY COALESCE(p.tanggal_verifikasi, p.tanggal_transaksi) DESC, p.id DESC
     LIMIT 50"
)->fetchAll() : [];

$pageTitle = 'Verifikasi Pembayaran';
include __DIR__ . '/../includes/header.php';
?>
<div class="container py-4">
    <h1 class="h3 mb-3">Verifikasi &amp; Pembatalan</h1>

    <ul class="nav nav-pills mb-4">
        <li class="nav-item"><a class="nav-link <?= $tab === 'bayar' ? 'active' : '' ?>" href="?tab=bayar">Pembayaran <span class="badge bg-light text-dark"><?= count($bayar) ?></span></a></li>
        <li class="nav-item"><a class="nav-link <?= $tab === 'refund' ? 'active' : '' ?>" href="?tab=refund">Refund &amp; Pembatalan <span class="badge bg-light text-dark"><?= count($refund) ?></span></a></li>
        <li class="nav-item"><a class="nav-link <?= $tab === 'riwayat' ? 'active' : '' ?>" href="?tab=riwayat">Riwayat Diproses</a></li>
    </ul>

<?php if ($tab === 'bayar'): ?>
    <?php if (!$bayar): ?><div class="alert alert-success">Tidak ada pembayaran yang menunggu verifikasi.</div><?php endif; ?>
    <?php foreach ($bayar as $p): ?>
        <div class="card mb-3">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-auto">
                        <?php if ($p['bukti_transfer']): ?>
                            <a href="<?= e(url('bukti.php?id=' . (int)$p['id'])) ?>" target="_blank" rel="noopener">
                                <img class="bukti-thumb" style="width:110px;height:110px" src="<?= e(url('bukti.php?id=' . (int)$p['id'])) ?>" alt="Bukti transfer">
                            </a>
                        <?php endif; ?>
                    </div>
                    <div class="col">
                        <div class="d-flex flex-wrap gap-2 align-items-center mb-1">
                            <span class="badge <?= $p['jenis_transaksi'] === 'DP' ? 'bg-primary' : 'bg-success' ?>"><?= e($p['jenis_transaksi']) ?></span>
                            <strong><?= e($p['kode_booking']) ?></strong>
                            <?= badge_status($p['status_reservasi']) ?>
                            <span class="text-muted small"><?= e(waktu_indo($p['tanggal_transaksi'])) ?></span>
                        </div>
                        <div class="h5 mb-1"><?= rupiah($p['nominal']) ?></div>
                        <div class="small text-muted">
                            <?= e($p['nama']) ?> (<?= e($p['no_hp']) ?>) · <?= e($p['nama_hall']) ?> · acara <?= e(tgl_indo($p['tanggal_acara'])) ?><br>
                            Total <?= rupiah($p['total_harga']) ?> · DP <?= rupiah($p['nominal_dp']) ?> · Sisa <?= rupiah($p['sisa_bayar']) ?>
                        </div>
                        <?php if ($p['jenis_transaksi'] === 'DP' && (int)$p['bentrok'] === 1): ?>
                            <div class="alert alert-danger py-2 mt-2 mb-0 small">
                                <i class="bi bi-exclamation-triangle"></i> <strong>Jadwal bentrok:</strong> tanggal ini sudah terkunci oleh reservasi lain, DP tidak dapat dikonfirmasi.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <hr>
                <div class="d-flex flex-wrap gap-2 align-items-start">
                    <?php if (!($p['jenis_transaksi'] === 'DP' && (int)$p['bentrok'] === 1)): ?>
                        <form method="post" data-confirm="Verifikasi <?= e($p['jenis_transaksi']) ?> <?= e(rupiah($p['nominal'])) ?> untuk <?= e($p['kode_booking']) ?>?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="aksi" value="bayar_terima">
                            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                            <button class="btn btn-success btn-sm"><i class="bi bi-check-lg"></i> Terima &amp; Verifikasi</button>
                        </form>
                    <?php else: ?>
                        <form method="post" data-confirm="Batalkan <?= e($p['kode_booking']) ?> dan kembalikan DP 100%?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="aksi" value="batal_pengelola">
                            <input type="hidden" name="reservasi_id" value="<?= (int)$p['reservasi_id'] ?>">
                            <input type="hidden" name="alasan" value="Jadwal bentrok dengan reservasi lain yang lebih dulu terkonfirmasi.">
                            <button class="btn btn-danger btn-sm"><i class="bi bi-arrow-counterclockwise"></i> Batalkan &amp; Refund 100%</button>
                        </form>
                    <?php endif; ?>
                    <form method="post" class="d-flex gap-2 flex-grow-1" style="max-width:480px">
                        <?= csrf_field() ?>
                        <input type="hidden" name="aksi" value="bayar_tolak">
                        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                        <input class="form-control form-control-sm" name="catatan" placeholder="Alasan penolakan (mis. nominal tidak sesuai)" minlength="5" maxlength="255" required>
                        <button class="btn btn-outline-danger btn-sm text-nowrap">Tolak</button>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

<?php elseif ($tab === 'refund'): ?>
    <?php if (!$refund): ?><div class="alert alert-success">Tidak ada pengajuan pembatalan atau refund yang menunggu.</div><?php endif; ?>
    <?php foreach ($refund as $p): $nominal = (int)$p['nominal']; ?>
        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex flex-wrap gap-2 align-items-center mb-1">
                    <span class="badge <?= $p['dibatalkan_oleh'] === 'Pengelola' ? 'bg-dark' : 'bg-warning text-dark' ?>">
                        <?= $p['dibatalkan_oleh'] === 'Pengelola' ? 'Dibatalkan pengelola' : 'Pengajuan pelanggan' ?>
                    </span>
                    <strong><?= e($p['kode_booking']) ?></strong>
                    <?= badge_status($p['status_reservasi']) ?>
                    <span class="text-muted small"><?= e(waktu_indo($p['tanggal_transaksi'])) ?></span>
                </div>
                <div class="small text-muted mb-2">
                    <?= e($p['nama']) ?> (<?= e($p['no_hp']) ?>) · <?= e($p['nama_hall']) ?> · acara <?= e(tgl_indo($p['tanggal_acara'])) ?>
                </div>
                <div class="row g-3">
                    <div class="col-md-5">
                        <div class="h5 mb-0"><?= rupiah($nominal) ?></div>
                        <div class="small text-muted">Refund <?= (int)$p['persen_refund'] ?>% dari DP<?= $nominal === 0 ? ' — DP hangus' : '' ?></div>
                        <div class="mt-2 small"><span class="text-muted">Alasan:</span> <?= e((string)$p['alasan_batal']) ?></div>
                        <div class="small"><span class="text-muted">Rekening:</span> <?= e($p['rekening_refund'] ?: '—') ?></div>
                    </div>
                    <div class="col-md-7">
                        <form method="post" enctype="multipart/form-data" class="border rounded p-2 mb-2"
                              data-confirm="<?= $p['dibatalkan_oleh'] === 'Customer' ? 'Setujui pembatalan ' . e($p['kode_booking']) . '? Tanggal akan terbuka kembali.' : 'Catat refund ' . e($p['kode_booking']) . ' sebagai selesai?' ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="aksi" value="proses_refund">
                            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                            <?php if ($nominal > 0): ?>
                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <label class="form-label small mb-0">Rekening tujuan</label>
                                        <input class="form-control form-control-sm" name="rekening_refund" value="<?= e((string)$p['rekening_refund']) ?>" maxlength="100" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small mb-0">Bukti transfer refund</label>
                                        <input type="file" class="form-control form-control-sm" name="bukti" accept="image/jpeg,image/png,image/webp" data-maxmb="2" required>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <button class="btn btn-success btn-sm mt-2">
                                <i class="bi bi-check-lg"></i>
                                <?= $p['dibatalkan_oleh'] === 'Customer' ? 'Setujui Pembatalan' . ($nominal > 0 ? ' & Catat Refund' : '') : 'Tandai Refund Selesai' ?>
                            </button>
                        </form>
                        <?php if ($p['dibatalkan_oleh'] === 'Customer'): ?>
                            <form method="post" class="d-flex gap-2">
                                <?= csrf_field() ?>
                                <input type="hidden" name="aksi" value="tolak_batal">
                                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                <input class="form-control form-control-sm" name="catatan" placeholder="Alasan menolak pengajuan" minlength="5" maxlength="255" required>
                                <button class="btn btn-outline-danger btn-sm text-nowrap">Tolak</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

<?php else: ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 small">
                <thead class="table-light"><tr><th>Diproses</th><th>Kode</th><th>Pelanggan</th><th>Jenis</th><th class="text-end">Nominal</th><th>Status</th><th>Catatan</th><th>Bukti</th></tr></thead>
                <tbody>
                <?php foreach ($riwayat as $p): ?>
                    <tr>
                        <td><?= e(waktu_indo($p['tanggal_verifikasi'] ?: $p['tanggal_transaksi'])) ?></td>
                        <td><?= e($p['kode_booking']) ?></td>
                        <td><?= e($p['nama']) ?></td>
                        <td><?= e($p['jenis_transaksi']) ?><?= $p['jenis_transaksi'] === 'Refund' ? ' (' . e((string)$p['dibatalkan_oleh']) . ')' : '' ?></td>
                        <td class="text-end"><?= rupiah($p['nominal']) ?></td>
                        <td><?= badge_verif($p['status_verifikasi']) ?></td>
                        <td><?= e((string)$p['catatan_admin']) ?></td>
                        <td><?php if ($p['bukti_transfer']): ?><a target="_blank" rel="noopener" href="<?= e(url('bukti.php?id=' . (int)$p['id'])) ?>">Lihat</a><?php else: ?>-<?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$riwayat): ?><tr><td colspan="8" class="text-center text-muted py-3">Belum ada riwayat.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
