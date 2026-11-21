<?php
require __DIR__ . '/includes/init.php';
$pdo   = db();
$halls = $pdo->query('SELECT * FROM halls ORDER BY harga_dasar DESC')->fetchAll();
$user  = current_user();

$pageTitle    = 'Beranda';
$extraScripts = [url('assets/js/kalender.js')];
include __DIR__ . '/includes/header.php';

function link_booking(?array $user, int $hallId): string
{
    $to = 'booking.php?hall_id=' . $hallId;
    return $user ? url($to) : url('login.php?next=' . urlencode(url($to)));
}
?>
<section class="hero">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-7">
                <h1 class="display-5">Sewa hall untuk acara impian Anda, tanpa jadwal bentrok.</h1>
                <p class="lead mt-3">Cek ketersediaan tanggal secara langsung, pilih fasilitas sesuai kebutuhan, lihat total biaya dan DP otomatis, lalu bayar bertahap dari DP hingga pelunasan.</p>
                <div class="mt-4 d-flex gap-2 flex-wrap">
                    <a href="#hall" class="btn btn-gold btn-lg">Lihat Hall</a>
                    <a href="#ketersediaan" class="btn btn-outline-light btn-lg">Cek Tanggal</a>
                </div>
            </div>
        </div>
    </div>
</section>

<div class="container">
    <section id="hall" class="pt-5">
        <h2 class="h3 mb-1">Pilihan Hall</h2>
        <p class="text-muted">Wedding, rapat, seminar, hingga gathering — semua tersedia.</p>
        <div class="row g-4">
            <?php foreach ($halls as $h): ?>
                <div class="col-md-6 col-lg-3">
                    <div class="card h-100">
                        <?php if ($h['foto']): ?>
                            <div class="hall-cover" style="background-image:url('<?= e(url('assets/uploads/halls/' . rawurlencode($h['foto']))) ?>')"></div>
                        <?php else: ?>
                            <div class="hall-cover"><i class="bi bi-building"></i></div>
                        <?php endif; ?>
                        <div class="card-body d-flex flex-column">
                            <h3 class="h5"><?= e($h['nama_hall']) ?></h3>
                            <div class="text-muted small mb-2"><i class="bi bi-people"></i> Hingga <?= number_format((int)$h['kapasitas'], 0, ',', '.') ?> tamu</div>
                            <p class="small text-muted flex-grow-1"><?= e($h['deskripsi']) ?></p>
                            <div class="hall-price mb-2"><?= rupiah($h['harga_dasar']) ?> <span class="small fw-normal text-muted">/ acara</span></div>
                            <a class="btn btn-brand w-100" href="<?= e(link_booking($user, (int)$h['id'])) ?>">Pesan Hall Ini</a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (!$halls): ?>
                <div class="col-12"><div class="alert alert-info">Belum ada data hall.</div></div>
            <?php endif; ?>
        </div>
    </section>

    <section id="ketersediaan" class="pt-5">
        <div class="row g-4">
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header"><i class="bi bi-calendar3 me-1"></i> Kalender Ketersediaan</div>
                    <div class="card-body">
                        <label class="form-label" for="pilihHall">Pilih hall</label>
                        <select id="pilihHall" class="form-select mb-3">
                            <?php foreach ($halls as $h): ?>
                                <option value="<?= (int)$h['id'] ?>"><?= e($h['nama_hall']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div id="kalenderPublik"></div>
                        <p class="small text-muted mt-3 mb-0">Tanggal ditandai terkunci setelah DP pelanggan terkonfirmasi oleh admin.</p>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><i class="bi bi-arrow-counterclockwise me-1"></i> Kebijakan Pembatalan &amp; Refund DP</div>
                    <div class="card-body">
                        <table class="table table-sm align-middle mb-3">
                            <thead><tr><th>Waktu pembatalan</th><th class="text-end">Refund DP</th></tr></thead>
                            <tbody>
                                <tr><td>Lebih dari 30 hari sebelum acara</td><td class="text-end fw-semibold">75%</td></tr>
                                <tr><td>14 – 30 hari sebelum acara</td><td class="text-end fw-semibold">50%</td></tr>
                                <tr><td>Kurang dari 14 hari sebelum acara</td><td class="text-end fw-semibold text-danger">0% (DP hangus)</td></tr>
                                <tr><td>Dibatalkan oleh pihak pengelola</td><td class="text-end fw-semibold text-success">100%</td></tr>
                            </tbody>
                        </table>
                        <h3 class="h6">Alur pemesanan</h3>
                        <ol class="timeline-policy small ps-3 mb-0">
                            <li>Daftar / masuk, pilih hall, tanggal, dan fasilitas.</li>
                            <li>Bayar DP minimal <?= (int)DP_PERSEN ?>% dan unggah bukti transfer.</li>
                            <li>Admin memverifikasi — tanggal Anda <strong>terkunci</strong>.</li>
                            <li>Lunasi sisa pembayaran sebelum hari acara.</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var sel = document.getElementById('pilihHall');
    if (!sel) { return; }
    var kal = new KalenderHall(document.getElementById('kalenderPublik'), {
        apiUrl: <?= json_encode(url('api/availability.php')) ?>,
        hallId: sel.value,
        selectable: false
    });
    sel.addEventListener('change', function () { kal.setHall(sel.value); });
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
