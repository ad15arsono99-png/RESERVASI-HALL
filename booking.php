<?php
require __DIR__ . '/includes/init.php';
$user = require_role('customer');
$pdo  = db();

$halls     = $pdo->query('SELECT id, nama_hall, kapasitas, harga_dasar, deskripsi FROM halls ORDER BY harga_dasar')->fetchAll();
$fasilitas = $pdo->query('SELECT id, nama_fasilitas, harga_tambahan, satuan FROM fasilitas ORDER BY nama_fasilitas')->fetchAll();
$hallMap   = [];
foreach ($halls as $h) {
    $hallMap[(int)$h['id']] = $h;
}

$tglGet = get_str('tanggal');
$old = [
    'hall_id'       => (int)(get_str('hall_id') ?: ($halls[0]['id'] ?? 0)),
    'tanggal_acara' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $tglGet) ? $tglGet : '',
    'jenis_acara'   => '',
    'jumlah_tamu'   => '',
    'fasilitas'     => [],
    'nominal_dp'    => '',
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $old['hall_id']       = (int)post('hall_id');
    $old['tanggal_acara'] = post('tanggal_acara');
    $old['jenis_acara']   = post('jenis_acara');
    $old['jumlah_tamu']   = post('jumlah_tamu');
    $old['nominal_dp']    = post('nominal_dp');
    $old['fasilitas']     = array_values(array_unique(array_map('intval', is_array($_POST['fasilitas'] ?? null) ? $_POST['fasilitas'] : [])));

    // --- Validasi input ---
    $hall = $hallMap[$old['hall_id']] ?? null;
    if (!$hall) {
        $errors[] = 'Hall tidak valid.';
    }

    $tgl   = DateTimeImmutable::createFromFormat('!Y-m-d', $old['tanggal_acara']);
    $today = new DateTimeImmutable('today');
    if (!$tgl || $tgl->format('Y-m-d') !== $old['tanggal_acara']) {
        $errors[] = 'Tanggal acara belum dipilih atau tidak valid.';
    } elseif ($tgl <= $today) {
        $errors[] = 'Tanggal acara minimal besok (H+1).';
    } elseif ($tgl > $today->modify('+2 years')) {
        $errors[] = 'Tanggal acara maksimal 2 tahun ke depan.';
    }

    if (!in_array($old['jenis_acara'], JENIS_ACARA, true)) {
        $errors[] = 'Jenis acara tidak valid.';
    }

    $tamu = filter_var($old['jumlah_tamu'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($tamu === false) {
        $errors[] = 'Jumlah tamu harus berupa angka positif.';
    } elseif ($hall && $tamu > (int)$hall['kapasitas']) {
        $errors[] = 'Jumlah tamu melebihi kapasitas hall (' . (int)$hall['kapasitas'] . ' orang).';
    }

    $dp = filter_var($old['nominal_dp'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($dp === false) {
        $errors[] = 'Nominal DP harus berupa angka.';
    }

    // Fasilitas yang dipilih harus ada di database; harga diambil dari database (bukan dari klien)
    $fasMap = [];
    foreach ($fasilitas as $f) {
        $fasMap[(int)$f['id']] = $f;
    }
    $dipilih = [];
    foreach ($old['fasilitas'] as $fid) {
        if (!isset($fasMap[$fid])) {
            $errors[] = 'Ada fasilitas yang tidak valid.';
            break;
        }
        $dipilih[] = $fasMap[$fid];
    }

    $total = 0;
    $minDp = 0;
    if ($hall) {
        $total = (int)$hall['harga_dasar'] + array_sum(array_map(fn($f) => (int)$f['harga_tambahan'], $dipilih));
        $minDp = min_dp($total);
        if ($dp !== false && ($dp < $minDp || $dp > $total)) {
            $errors[] = 'Nominal DP harus antara ' . rupiah($minDp) . ' dan ' . rupiah($total) . '.';
        }
    }

    // --- Simpan ---
    if (!$errors) {
        try {
            $pdo->beginTransaction();

            // Kunci baris hall agar pengecekan jadwal & penyimpanan berjalan berurutan
            $pdo->prepare('SELECT id FROM halls WHERE id = ? FOR UPDATE')->execute([$hall['id']]);

            if (tanggal_terkunci($pdo, (int)$hall['id'], $old['tanggal_acara'])) {
                throw new RuntimeException('Maaf, tanggal tersebut baru saja dikunci oleh pelanggan lain. Silakan pilih tanggal lain.');
            }

            $cap = $pdo->prepare("SELECT COUNT(*) FROM reservasi WHERE user_id = ? AND status = 'Pending'");
            $cap->execute([$user['id']]);
            if ((int)$cap->fetchColumn() >= 5) {
                throw new RuntimeException('Anda memiliki 5 reservasi berstatus Pending. Selesaikan atau batalkan salah satunya terlebih dahulu.');
            }

            $pdo->prepare(
                "INSERT INTO reservasi
                 (kode_booking, user_id, hall_id, tanggal_acara, jenis_acara, jumlah_tamu, total_harga, nominal_dp, sisa_bayar, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')"
            )->execute([
                generate_kode_booking(), $user['id'], $hall['id'], $old['tanggal_acara'],
                $old['jenis_acara'], $tamu, $total, $dp, $total - $dp,
            ]);
            $rid = (int)$pdo->lastInsertId();

            $ins = $pdo->prepare('INSERT INTO detail_fasilitas_reservasi (reservasi_id, fasilitas_id, harga_saat_booking) VALUES (?, ?, ?)');
            foreach ($dipilih as $f) {
                $ins->execute([$rid, $f['id'], $f['harga_tambahan']]);
            }

            $pdo->commit();
            flash('success', 'Reservasi dibuat. Silakan bayar DP dan unggah bukti transfer agar tanggal Anda dikunci.');
            redirect('customer/bayar.php?id=' . $rid);
        } catch (RuntimeException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $ex->getMessage();
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Booking gagal: ' . $ex->getMessage());
            $errors[] = 'Terjadi kesalahan saat menyimpan reservasi. Silakan coba lagi.';
        }
    }
}

$pageTitle    = 'Buat Reservasi';
$extraScripts = [url('assets/js/kalender.js'), url('assets/js/booking.js')];
include __DIR__ . '/includes/header.php';
?>
<div class="container py-4">
    <h1 class="h3 mb-1">Buat Reservasi Hall</h1>
    <p class="text-muted">Pilih hall dan tanggal, lengkapi detail acara, lalu pilih fasilitas tambahan. Total biaya dan DP dihitung otomatis.</p>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <strong>Reservasi belum dapat disimpan:</strong>
            <ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <?php if (!$halls): ?>
        <div class="alert alert-info">Belum ada hall yang tersedia.</div>
    <?php else: ?>
    <form method="post" id="formBooking" data-api="<?= e(url('api/availability.php')) ?>" data-dp-persen="<?= (int)DP_PERSEN ?>" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="tanggal_acara" id="tanggal_acara" value="<?= e($old['tanggal_acara']) ?>">
        <div class="row g-4">
            <div class="col-lg-8">
                <div class="card mb-4">
                    <div class="card-header">1. Hall &amp; Tanggal Acara</div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label" for="hall_id">Hall</label>
                            <select class="form-select" id="hall_id" name="hall_id" required>
                                <?php foreach ($halls as $h): ?>
                                    <option value="<?= (int)$h['id'] ?>"
                                            data-nama="<?= e($h['nama_hall']) ?>"
                                            data-harga="<?= (int)$h['harga_dasar'] ?>"
                                            data-kapasitas="<?= (int)$h['kapasitas'] ?>"
                                            <?= (int)$h['id'] === (int)$old['hall_id'] ? 'selected' : '' ?>>
                                        <?= e($h['nama_hall']) ?> — <?= rupiah($h['harga_dasar']) ?> (maks. <?= (int)$h['kapasitas'] ?> tamu)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-2">Tanggal dipilih: <strong id="tanggalTeks">Belum dipilih</strong></div>
                        <div id="kalender"></div>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header">2. Detail Acara</div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="jenis_acara">Jenis acara</label>
                                <select class="form-select" id="jenis_acara" name="jenis_acara" required>
                                    <option value="">— pilih —</option>
                                    <?php foreach (JENIS_ACARA as $j): ?>
                                        <option <?= $old['jenis_acara'] === $j ? 'selected' : '' ?>><?= e($j) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="jumlah_tamu">Perkiraan jumlah tamu</label>
                                <input type="number" class="form-control" id="jumlah_tamu" name="jumlah_tamu" min="1" value="<?= e($old['jumlah_tamu']) ?>" required>
                                <div class="form-text" id="hintKapasitas"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header">3. Fasilitas Tambahan (opsional)</div>
                    <div class="card-body">
                        <?php if (!$fasilitas): ?>
                            <p class="text-muted mb-0">Belum ada fasilitas tambahan.</p>
                        <?php endif; ?>
                        <div class="row g-2">
                            <?php foreach ($fasilitas as $f): ?>
                                <div class="col-md-6">
                                    <label class="form-check border rounded p-2 ps-5 w-100 d-block">
                                        <input class="form-check-input cb-fasilitas" type="checkbox" name="fasilitas[]"
                                               value="<?= (int)$f['id'] ?>"
                                               data-nama="<?= e($f['nama_fasilitas']) ?>"
                                               data-harga="<?= (int)$f['harga_tambahan'] ?>"
                                               <?= in_array((int)$f['id'], $old['fasilitas'], true) ? 'checked' : '' ?>>
                                        <span class="d-block fw-semibold"><?= e($f['nama_fasilitas']) ?></span>
                                        <span class="small text-muted"><?= rupiah($f['harga_tambahan']) ?> / <?= e($f['satuan']) ?></span>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">4. Nominal DP</div>
                    <div class="card-body">
                        <label class="form-label" for="nominal_dp">DP yang akan Anda bayar (Rp)</label>
                        <div class="input-group">
                            <span class="input-group-text">Rp</span>
                            <input type="number" class="form-control" id="nominal_dp" name="nominal_dp" step="1" value="<?= e($old['nominal_dp']) ?>" required>
                            <button class="btn btn-outline-secondary" type="button" id="btnDpMin">DP minimal</button>
                            <button class="btn btn-outline-secondary" type="button" id="btnDpFull">Bayar penuh</button>
                        </div>
                        <div class="form-text" id="hintDp"></div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card sticky-side">
                    <div class="card-header">Ringkasan Biaya</div>
                    <div class="card-body">
                        <div class="small text-muted">Hall</div>
                        <div class="fw-semibold mb-2" id="sumHall">-</div>
                        <div class="small text-muted">Tanggal acara</div>
                        <div class="fw-semibold mb-3" id="sumTanggal">-</div>

                        <div class="sum-row"><span>Harga dasar hall</span><span id="sumHargaDasar">Rp 0</span></div>
                        <div class="sum-row"><span>Fasilitas tambahan</span><span id="sumFasilitas">Rp 0</span></div>
                        <div class="small text-muted mb-1" id="sumFasilitasList"></div>
                        <div class="sum-row sum-total"><span>Total Harga</span><span id="sumTotal">Rp 0</span></div>
                        <hr>
                        <div class="sum-row"><span>Minimal DP (<?= (int)DP_PERSEN ?>%)</span><span id="sumMinDp">Rp 0</span></div>
                        <div class="sum-row"><span>DP yang dibayar</span><span id="sumDp" class="fw-semibold">Rp 0</span></div>
                        <div class="sum-row"><span>Sisa bayar (pelunasan)</span><span id="sumSisa">Rp 0</span></div>
                        <button type="submit" class="btn btn-gold w-100 mt-3">Buat Reservasi</button>
                        <p class="small text-muted mt-2 mb-0">Tanggal baru terkunci setelah DP Anda diverifikasi admin.</p>
                    </div>
                </div>
            </div>
        </div>
    </form>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
