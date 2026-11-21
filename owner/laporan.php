<?php
/**
 * Owner: rekapitulasi reservasi & laporan keuangan (DP, pelunasan, refund).
 */
require __DIR__ . '/../includes/init.php';
$owner = require_role('owner');
$pdo   = db();

function valid_date(string $d): bool
{
    $x = DateTimeImmutable::createFromFormat('!Y-m-d', $d);
    return $x && $x->format('Y-m-d') === $d;
}

$dari   = get_str('dari');
$sampai = get_str('sampai');
if (!valid_date($dari))   { $dari   = date('Y-m-01'); }
if (!valid_date($sampai)) { $sampai = date('Y-m-t'); }
if ($dari > $sampai)      { [$dari, $sampai] = [$sampai, $dari]; }

// Tanggal transaksi yang dipakai laporan = tanggal verifikasi (uang resmi tercatat)
$tglTrx = 'DATE(COALESCE(p.tanggal_verifikasi, p.tanggal_transaksi))';

/* ---- Ringkasan & grafik bulanan ---- */
$st = $pdo->prepare(
    "SELECT DATE_FORMAT(COALESCE(p.tanggal_verifikasi, p.tanggal_transaksi), '%Y-%m') AS ym,
            SUM(CASE WHEN p.jenis_transaksi = 'DP' THEN p.nominal ELSE 0 END) AS dp,
            SUM(CASE WHEN p.jenis_transaksi = 'Pelunasan' THEN p.nominal ELSE 0 END) AS pelunasan,
            SUM(CASE WHEN p.jenis_transaksi = 'Refund' THEN p.nominal ELSE 0 END) AS refund
     FROM pembayaran_pembatalan p
     WHERE p.status_verifikasi = 'Terverifikasi' AND $tglTrx BETWEEN ? AND ?
     GROUP BY ym ORDER BY ym"
);
$st->execute([$dari, $sampai]);
$bulanan = $st->fetchAll();

$tot = ['dp' => 0, 'pelunasan' => 0, 'refund' => 0];
foreach ($bulanan as $b) {
    $tot['dp']        += (int)$b['dp'];
    $tot['pelunasan'] += (int)$b['pelunasan'];
    $tot['refund']    += (int)$b['refund'];
}
$bersih = $tot['dp'] + $tot['pelunasan'] - $tot['refund'];

/* ---- Rekap reservasi (berdasarkan tanggal acara) ---- */
$st = $pdo->prepare(
    "SELECT status, COUNT(*) AS jml, COALESCE(SUM(total_harga),0) AS nilai
     FROM reservasi WHERE tanggal_acara BETWEEN ? AND ? GROUP BY status"
);
$st->execute([$dari, $sampai]);
$rekap = ['Pending' => [0, 0], 'DP Terkonfirmasi' => [0, 0], 'Lunas' => [0, 0], 'Dibatalkan' => [0, 0]];
foreach ($st->fetchAll() as $row) {
    $rekap[$row['status']] = [(int)$row['jml'], (int)$row['nilai']];
}
$jmlReservasi = array_sum(array_column($rekap, 0));

$st = $pdo->prepare(
    "SELECT r.kode_booking, r.tanggal_acara, r.jenis_acara, r.total_harga, r.status, h.nama_hall, u.nama
     FROM reservasi r JOIN halls h ON h.id = r.hall_id JOIN users u ON u.id = r.user_id
     WHERE r.tanggal_acara BETWEEN ? AND ? ORDER BY r.tanggal_acara, r.id"
);
$st->execute([$dari, $sampai]);
$reservasi = $st->fetchAll();

/* ---- Transaksi terverifikasi ---- */
$st = $pdo->prepare(
    "SELECT p.jenis_transaksi, p.nominal, p.persen_refund, p.dibatalkan_oleh,
            COALESCE(p.tanggal_verifikasi, p.tanggal_transaksi) AS tgl, r.kode_booking, u.nama
     FROM pembayaran_pembatalan p JOIN reservasi r ON r.id = p.reservasi_id JOIN users u ON u.id = r.user_id
     WHERE p.status_verifikasi = 'Terverifikasi' AND $tglTrx BETWEEN ? AND ? AND p.nominal > 0
     ORDER BY tgl, p.id"
);
$st->execute([$dari, $sampai]);
$transaksi = $st->fetchAll();

/* ---- Ekspor CSV ---- */
if (get_str('export') === 'csv') {
    $safe = function ($v) {
        $v = (string)$v;
        return ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) ? "'" . $v : $v;
    };
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="laporan-keuangan-' . $dari . '_' . $sampai . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Tanggal', 'Kode Booking', 'Pelanggan', 'Jenis', 'Nominal', 'Arah', 'Keterangan']);
    foreach ($transaksi as $t) {
        $isRefund = $t['jenis_transaksi'] === 'Refund';
        fputcsv($out, [
            date('Y-m-d', strtotime($t['tgl'])), $t['kode_booking'], $safe($t['nama']), $t['jenis_transaksi'],
            (int)$t['nominal'], $isRefund ? 'Keluar' : 'Masuk',
            $isRefund ? 'Refund ' . (int)$t['persen_refund'] . '% DP (' . $t['dibatalkan_oleh'] . ')' : '',
        ]);
    }
    fclose($out);
    exit;
}

$pageTitle    = 'Laporan';
$extraScripts = ['https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js'];
include __DIR__ . '/../includes/header.php';
?>
<div class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h3 mb-0">Laporan Reservasi &amp; Keuangan</h1>
        <div class="no-print d-flex gap-2">
            <a class="btn btn-outline-secondary btn-sm" href="?dari=<?= e($dari) ?>&amp;sampai=<?= e($sampai) ?>&amp;export=csv"><i class="bi bi-filetype-csv"></i> Ekspor CSV</a>
            <button class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Cetak</button>
        </div>
    </div>

    <form class="card card-body mb-4 no-print" method="get">
        <div class="row g-2 align-items-end">
            <div class="col-sm-4"><label class="form-label small mb-0">Dari tanggal</label><input type="date" class="form-control" name="dari" value="<?= e($dari) ?>"></div>
            <div class="col-sm-4"><label class="form-label small mb-0">Sampai tanggal</label><input type="date" class="form-control" name="sampai" value="<?= e($sampai) ?>"></div>
            <div class="col-sm-4"><button class="btn btn-brand w-100">Terapkan</button></div>
        </div>
    </form>
    <p class="text-muted small">Periode <strong><?= e(tgl_indo($dari)) ?></strong> s.d. <strong><?= e(tgl_indo($sampai)) ?></strong>.
        Keuangan dihitung dari transaksi yang <em>sudah terverifikasi</em> (berdasarkan tanggal verifikasi); rekap reservasi berdasarkan tanggal acara.</p>

    <h2 class="h5">Keuangan</h2>
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3"><div class="card stat"><div class="card-body"><div class="text-muted small">Pemasukan DP</div><div class="fs-5 fw-bold text-primary"><?= rupiah($tot['dp']) ?></div></div></div></div>
        <div class="col-6 col-lg-3"><div class="card stat"><div class="card-body"><div class="text-muted small">Pemasukan Pelunasan</div><div class="fs-5 fw-bold text-success"><?= rupiah($tot['pelunasan']) ?></div></div></div></div>
        <div class="col-6 col-lg-3"><div class="card stat"><div class="card-body"><div class="text-muted small">Potongan Refund</div><div class="fs-5 fw-bold text-danger">&minus; <?= rupiah($tot['refund']) ?></div></div></div></div>
        <div class="col-6 col-lg-3"><div class="card stat"><div class="card-body"><div class="text-muted small">Pendapatan Bersih</div><div class="fs-5 fw-bold"><?= rupiah($bersih) ?></div></div></div></div>
    </div>

    <?php if ($bulanan): ?>
    <div class="card mb-4">
        <div class="card-header">Arus Kas per Bulan</div>
        <div class="card-body"><canvas id="grafik" height="90" aria-label="Grafik pemasukan dan refund per bulan" role="img"></canvas></div>
    </div>
    <?php endif; ?>

    <h2 class="h5">Rekapitulasi Reservasi <span class="text-muted fw-normal">(<?= $jmlReservasi ?> reservasi)</span></h2>
    <div class="row g-3 mb-3">
        <?php foreach ($rekap as $s => [$jml, $nilai]): ?>
            <div class="col-6 col-lg-3">
                <div class="card"><div class="card-body">
                    <div><?= badge_status($s) ?></div>
                    <div class="fs-4 fw-bold"><?= $jml ?></div>
                    <div class="small text-muted">Nilai kontrak <?= rupiah($nilai) ?></div>
                </div></div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card mb-4">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 small">
                <thead class="table-light"><tr><th>Tanggal acara</th><th>Kode</th><th>Pelanggan</th><th>Hall</th><th>Jenis</th><th class="text-end">Total</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($reservasi as $r): ?>
                    <tr>
                        <td><?= e(tgl_indo($r['tanggal_acara'])) ?></td><td><?= e($r['kode_booking']) ?></td><td><?= e($r['nama']) ?></td>
                        <td><?= e($r['nama_hall']) ?></td><td><?= e($r['jenis_acara']) ?></td>
                        <td class="text-end"><?= rupiah($r['total_harga']) ?></td><td><?= badge_status($r['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$reservasi): ?><tr><td colspan="7" class="text-center text-muted py-3">Tidak ada reservasi pada periode ini.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <h2 class="h5">Rincian Transaksi Terverifikasi</h2>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 small">
                <thead class="table-light"><tr><th>Tanggal</th><th>Kode</th><th>Pelanggan</th><th>Jenis</th><th class="text-end">Nominal</th></tr></thead>
                <tbody>
                <?php foreach ($transaksi as $t): $ref = $t['jenis_transaksi'] === 'Refund'; ?>
                    <tr>
                        <td><?= e(tgl_indo($t['tgl'])) ?></td><td><?= e($t['kode_booking']) ?></td><td><?= e($t['nama']) ?></td>
                        <td><?= e($t['jenis_transaksi']) ?><?= $ref ? ' ' . (int)$t['persen_refund'] . '% DP · ' . e((string)$t['dibatalkan_oleh']) : '' ?></td>
                        <td class="text-end <?= $ref ? 'text-danger' : '' ?>"><?= $ref ? '− ' : '' ?><?= rupiah($t['nominal']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$transaksi): ?><tr><td colspan="5" class="text-center text-muted py-3">Tidak ada transaksi pada periode ini.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($bulanan): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var data = <?= json_encode($bulanan, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    new Chart(document.getElementById('grafik'), {
        type: 'bar',
        data: {
            labels: data.map(function (d) { return d.ym; }),
            datasets: [
                { label: 'DP', data: data.map(function (d) { return +d.dp; }), backgroundColor: '#2f6fdb' },
                { label: 'Pelunasan', data: data.map(function (d) { return +d.pelunasan; }), backgroundColor: '#2e9e5b' },
                { label: 'Refund', data: data.map(function (d) { return +d.refund; }), backgroundColor: '#d64545' }
            ]
        },
        options: {
            responsive: true,
            scales: { y: { beginAtZero: true, ticks: { callback: function (v) { return 'Rp ' + new Intl.NumberFormat('id-ID').format(v); } } } },
            plugins: { tooltip: { callbacks: { label: function (c) { return c.dataset.label + ': Rp ' + new Intl.NumberFormat('id-ID').format(c.parsed.y); } } } }
        }
    });
});
</script>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
