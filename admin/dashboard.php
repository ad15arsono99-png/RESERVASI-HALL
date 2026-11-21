<?php
require __DIR__ . '/../includes/init.php';
$admin = require_role('admin');
$pdo   = db();

$stat = $pdo->query(
    "SELECT
       (SELECT COUNT(*) FROM reservasi WHERE status IN ('Pending','DP Terkonfirmasi','Lunas')) AS aktif,
       (SELECT COUNT(*) FROM pembayaran_pembatalan WHERE jenis_transaksi IN ('DP','Pelunasan') AND status_verifikasi = 'Menunggu') AS bayar_menunggu,
       (SELECT COUNT(*) FROM pembayaran_pembatalan WHERE jenis_transaksi = 'Refund' AND status_verifikasi = 'Menunggu') AS refund_menunggu,
       (SELECT COUNT(*) FROM reservasi WHERE status IN ('DP Terkonfirmasi','Lunas')
          AND tanggal_acara BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)) AS acara_30"
)->fetch();

$statusList = ['Pending', 'DP Terkonfirmasi', 'Lunas', 'Dibatalkan'];
$fStatus = get_str('status');
$q       = get_str('q');

$where  = [];
$params = [];
if (in_array($fStatus, $statusList, true)) {
    $where[]  = 'r.status = ?';
    $params[] = $fStatus;
}
if ($q !== '') {
    $like     = '%' . addcslashes($q, '%_\\') . '%';
    $where[]  = '(r.kode_booking LIKE ? OR u.nama LIKE ? OR h.nama_hall LIKE ?)';
    array_push($params, $like, $like, $like);
}
$sql = "SELECT r.*, h.nama_hall, u.nama, u.no_hp
        FROM reservasi r JOIN halls h ON h.id = r.hall_id JOIN users u ON u.id = r.user_id"
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY r.created_at DESC, r.id DESC LIMIT 100';
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

$akan = $pdo->query(
    "SELECT r.kode_booking, r.tanggal_acara, r.jenis_acara, r.status, h.nama_hall, u.nama
     FROM reservasi r JOIN halls h ON h.id = r.hall_id JOIN users u ON u.id = r.user_id
     WHERE r.status IN ('DP Terkonfirmasi','Lunas') AND r.tanggal_acara >= CURDATE()
     ORDER BY r.tanggal_acara LIMIT 6"
)->fetchAll();

$pageTitle = 'Dashboard Admin';
include __DIR__ . '/../includes/header.php';
?>
<div class="container py-4">
    <h1 class="h3 mb-3">Dashboard Admin</h1>

    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3"><div class="card stat"><div class="card-body"><div class="text-muted small">Reservasi aktif</div><div class="num"><?= (int)$stat['aktif'] ?></div></div></div></div>
        <div class="col-6 col-lg-3"><a class="text-decoration-none" href="<?= e(url('admin/verifikasi.php?tab=bayar')) ?>"><div class="card stat"><div class="card-body"><div class="text-muted small">Pembayaran menunggu verifikasi</div><div class="num"><?= (int)$stat['bayar_menunggu'] ?></div></div></div></a></div>
        <div class="col-6 col-lg-3"><a class="text-decoration-none" href="<?= e(url('admin/verifikasi.php?tab=refund')) ?>"><div class="card stat"><div class="card-body"><div class="text-muted small">Pembatalan / refund menunggu</div><div class="num"><?= (int)$stat['refund_menunggu'] ?></div></div></div></a></div>
        <div class="col-6 col-lg-3"><div class="card stat"><div class="card-body"><div class="text-muted small">Acara 30 hari ke depan</div><div class="num"><?= (int)$stat['acara_30'] ?></div></div></div></div>
    </div>

    <div class="row g-4">
        <div class="col-lg-9">
            <div class="card">
                <div class="card-header">
                    <form class="row g-2 align-items-center" method="get">
                        <div class="col-auto fw-semibold">Reservasi</div>
                        <div class="col-sm">
                            <input class="form-control form-control-sm" name="q" value="<?= e($q) ?>" placeholder="Cari kode / pelanggan / hall">
                        </div>
                        <div class="col-sm-auto">
                            <select class="form-select form-select-sm" name="status">
                                <option value="">Semua status</option>
                                <?php foreach ($statusList as $s): ?>
                                    <option <?= $fStatus === $s ? 'selected' : '' ?>><?= e($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-auto"><button class="btn btn-sm btn-brand">Filter</button></div>
                    </form>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover table-sm mb-0 small">
                        <thead class="table-light"><tr><th>Kode</th><th>Pelanggan</th><th>Hall</th><th>Tanggal acara</th><th class="text-end">Total</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td><strong><?= e($r['kode_booking']) ?></strong><div class="text-muted"><?= e($r['jenis_acara']) ?></div></td>
                                <td><?= e($r['nama']) ?><div class="text-muted"><?= e($r['no_hp']) ?></div></td>
                                <td><?= e($r['nama_hall']) ?></td>
                                <td><?= e(tgl_indo($r['tanggal_acara'])) ?></td>
                                <td class="text-end"><?= rupiah($r['total_harga']) ?><div class="text-muted">sisa <?= rupiah($r['sisa_bayar']) ?></div></td>
                                <td><?= badge_status($r['status']) ?></td>
                                <td class="text-end">
                                    <?php if ($r['status'] !== 'Dibatalkan'): ?>
                                        <button class="btn btn-outline-danger btn-sm py-0" data-bs-toggle="modal" data-bs-target="#modalBatalAdmin"
                                                data-id="<?= (int)$r['id'] ?>" data-kode="<?= e($r['kode_booking']) ?>">Batalkan</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">Tidak ada data.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-3">
            <div class="card">
                <div class="card-header">Acara Terdekat (terkunci)</div>
                <ul class="list-group list-group-flush small">
                    <?php foreach ($akan as $a): ?>
                        <li class="list-group-item">
                            <div class="fw-semibold"><?= e(tgl_indo($a['tanggal_acara'])) ?></div>
                            <?= e($a['nama_hall']) ?> · <?= e($a['jenis_acara']) ?><br>
                            <span class="text-muted"><?= e($a['nama']) ?></span> <?= badge_status($a['status']) ?>
                        </li>
                    <?php endforeach; ?>
                    <?php if (!$akan): ?><li class="list-group-item text-muted">Belum ada acara terkunci.</li><?php endif; ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalBatalAdmin" tabindex="-1" aria-labelledby="modalBatalAdminLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="post" action="<?= e(url('admin/verifikasi.php?tab=refund')) ?>" data-confirm="Batalkan reservasi ini? Tindakan tidak dapat diurungkan.">
            <?= csrf_field() ?>
            <input type="hidden" name="aksi" value="batal_pengelola">
            <input type="hidden" name="kembali" value="dashboard">
            <input type="hidden" name="reservasi_id" value="">
            <div class="modal-header">
                <h2 class="modal-title h5" id="modalBatalAdminLabel">Batalkan oleh Pengelola</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <p>Reservasi <strong id="admKode"></strong> akan dibatalkan dan tanggalnya terbuka kembali.</p>
                <div class="alert alert-warning small">
                    Refund <strong>100%</strong> dari DP (dan pelunasan bila sudah dibayar). Pembayaran yang masih berstatus menunggu verifikasi dianggap sudah diterima dan ikut dikembalikan.
                </div>
                <label class="form-label" for="alasan">Alasan pembatalan</label>
                <textarea class="form-control" id="alasan" name="alasan" rows="3" minlength="5" maxlength="500" required></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Kembali</button>
                <button class="btn btn-danger">Batalkan Reservasi</button>
            </div>
        </form>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var m = document.getElementById('modalBatalAdmin');
    m.addEventListener('show.bs.modal', function (ev) {
        m.querySelector('[name=reservasi_id]').value = ev.relatedTarget.getAttribute('data-id');
        document.getElementById('admKode').textContent = ev.relatedTarget.getAttribute('data-kode');
    });
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
