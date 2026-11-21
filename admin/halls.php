<?php
/**
 * Admin: kelola data hall (tambah / ubah / hapus + foto).
 */
require __DIR__ . '/../includes/init.php';
$admin = require_role('admin');
$pdo   = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $aksi = post('aksi');
    $id   = (int)post('id');
    $baru = null;

    try {
        if ($aksi === 'simpan') {
            $nama      = post('nama_hall');
            $kapasitas = filter_var(post('kapasitas'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
            $harga     = filter_var(post('harga_dasar'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000000000000]]);
            $deskripsi = post('deskripsi');

            if (mb_strlen($nama) < 3 || mb_strlen($nama) > 100) {
                throw new RuntimeException('Nama hall harus 3–100 karakter.');
            }
            if ($kapasitas === false) {
                throw new RuntimeException('Kapasitas harus angka 1–100.000.');
            }
            if ($harga === false) {
                throw new RuntimeException('Harga dasar harus berupa angka (rupiah, tanpa titik).');
            }
            if (mb_strlen($deskripsi) > 2000) {
                throw new RuntimeException('Deskripsi maksimal 2000 karakter.');
            }

            $fotoLama = null;
            if ($id) {
                $st = $pdo->prepare('SELECT foto FROM halls WHERE id = ?');
                $st->execute([$id]);
                $row = $st->fetch();
                if (!$row) {
                    throw new RuntimeException('Hall tidak ditemukan.');
                }
                $fotoLama = $row['foto'];
            }

            $adaFile = isset($_FILES['foto']) && ($_FILES['foto']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            if ($adaFile) {
                [$ok, $res] = upload_image($_FILES['foto'], HALL_DIR, 'hall');
                if (!$ok) {
                    throw new RuntimeException('Foto: ' . $res);
                }
                $baru = $res;
            }

            if ($id) {
                $pdo->prepare('UPDATE halls SET nama_hall = ?, kapasitas = ?, harga_dasar = ?, deskripsi = ?, foto = ? WHERE id = ?')
                    ->execute([$nama, $kapasitas, $harga, $deskripsi, $baru ?? $fotoLama, $id]);
                if ($baru) {
                    hapus_file(HALL_DIR, $fotoLama);
                }
                flash('success', 'Data hall diperbarui.');
            } else {
                $pdo->prepare('INSERT INTO halls (nama_hall, kapasitas, harga_dasar, deskripsi, foto) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$nama, $kapasitas, $harga, $deskripsi, $baru]);
                flash('success', 'Hall baru ditambahkan.');
            }
        } elseif ($aksi === 'hapus') {
            $st = $pdo->prepare('SELECT foto FROM halls WHERE id = ?');
            $st->execute([$id]);
            $row = $st->fetch();
            if (!$row) {
                throw new RuntimeException('Hall tidak ditemukan.');
            }
            $pdo->prepare('DELETE FROM halls WHERE id = ?')->execute([$id]);
            hapus_file(HALL_DIR, $row['foto']);
            flash('success', 'Hall dihapus.');
        } else {
            throw new RuntimeException('Aksi tidak dikenal.');
        }
    } catch (RuntimeException $ex) {
        hapus_file(HALL_DIR, $baru);
        flash('danger', $ex->getMessage());
    } catch (PDOException $ex) {
        hapus_file(HALL_DIR, $baru);
        if ($ex->getCode() === '23000') {
            flash('danger', 'Hall tidak dapat dihapus karena sudah dipakai pada data reservasi.');
        } else {
            error_log($ex->getMessage());
            flash('danger', 'Terjadi kesalahan saat menyimpan data.');
        }
    }
    redirect('admin/halls.php');
}

$halls = $pdo->query('SELECT * FROM halls ORDER BY nama_hall')->fetchAll();

$pageTitle = 'Data Hall';
include __DIR__ . '/../includes/header.php';
?>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">Data Hall</h1>
        <button class="btn btn-gold" data-bs-toggle="modal" data-bs-target="#modalHall" data-mode="baru"><i class="bi bi-plus-lg"></i> Tambah Hall</button>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light"><tr><th style="width:90px">Foto</th><th>Nama</th><th class="text-end">Kapasitas</th><th class="text-end">Harga dasar</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($halls as $h): ?>
                    <tr>
                        <td>
                            <?php if ($h['foto']): ?>
                                <img class="bukti-thumb" src="<?= e(url('assets/uploads/halls/' . rawurlencode($h['foto']))) ?>" alt="">
                            <?php else: ?>
                                <div class="bukti-thumb d-flex align-items-center justify-content-center bg-light text-muted"><i class="bi bi-image"></i></div>
                            <?php endif; ?>
                        </td>
                        <td><strong><?= e($h['nama_hall']) ?></strong><div class="small text-muted"><?= e(mb_strimwidth((string)$h['deskripsi'], 0, 90, '…')) ?></div></td>
                        <td class="text-end"><?= number_format((int)$h['kapasitas'], 0, ',', '.') ?></td>
                        <td class="text-end"><?= rupiah($h['harga_dasar']) ?></td>
                        <td class="text-end text-nowrap">
                            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modalHall" data-mode="ubah"
                                    data-id="<?= (int)$h['id'] ?>" data-nama="<?= e($h['nama_hall']) ?>" data-kapasitas="<?= (int)$h['kapasitas'] ?>"
                                    data-harga="<?= (int)$h['harga_dasar'] ?>" data-deskripsi="<?= e((string)$h['deskripsi']) ?>">Ubah</button>
                            <form method="post" class="d-inline" data-confirm="Hapus hall &quot;<?= e($h['nama_hall']) ?>&quot;?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="aksi" value="hapus">
                                <input type="hidden" name="id" value="<?= (int)$h['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger">Hapus</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$halls): ?><tr><td colspan="5" class="text-center text-muted py-4">Belum ada hall.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="modalHall" tabindex="-1" aria-labelledby="modalHallLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="aksi" value="simpan">
            <input type="hidden" name="id" value="0">
            <div class="modal-header">
                <h2 class="modal-title h5" id="modalHallLabel">Hall</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3"><label class="form-label">Nama hall</label><input class="form-control" name="nama_hall" maxlength="100" required></div>
                <div class="row g-3 mb-3">
                    <div class="col-6"><label class="form-label">Kapasitas (tamu)</label><input type="number" class="form-control" name="kapasitas" min="1" required></div>
                    <div class="col-6"><label class="form-label">Harga dasar (Rp)</label><input type="number" class="form-control" name="harga_dasar" min="0" required></div>
                </div>
                <div class="mb-3"><label class="form-label">Deskripsi</label><textarea class="form-control" name="deskripsi" rows="3" maxlength="2000"></textarea></div>
                <div class="mb-1">
                    <label class="form-label">Foto (JPG/PNG/WEBP, maks. 2 MB)</label>
                    <input type="file" class="form-control" name="foto" accept="image/jpeg,image/png,image/webp" data-maxmb="2">
                    <div class="form-text" id="fotoHint"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
                <button class="btn btn-brand">Simpan</button>
            </div>
        </form>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var m = document.getElementById('modalHall');
    m.addEventListener('show.bs.modal', function (ev) {
        var b = ev.relatedTarget, f = m.querySelector('form'), ubah = b.getAttribute('data-mode') === 'ubah';
        m.querySelector('#modalHallLabel').textContent = ubah ? 'Ubah Hall' : 'Tambah Hall';
        f.reset();
        f.id.value = ubah ? b.getAttribute('data-id') : 0;
        if (ubah) {
            f.nama_hall.value = b.getAttribute('data-nama');
            f.kapasitas.value = b.getAttribute('data-kapasitas');
            f.harga_dasar.value = b.getAttribute('data-harga');
            f.deskripsi.value = b.getAttribute('data-deskripsi');
        }
        document.getElementById('fotoHint').textContent = ubah ? 'Kosongkan jika tidak ingin mengganti foto.' : '';
    });
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
