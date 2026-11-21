<?php
/**
 * Admin: kelola fasilitas tambahan (tambah / ubah / hapus).
 */
require __DIR__ . '/../includes/init.php';
$admin = require_role('admin');
$pdo   = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $aksi = post('aksi');
    $id   = (int)post('id');

    try {
        if ($aksi === 'simpan') {
            $nama   = post('nama_fasilitas');
            $harga  = filter_var(post('harga_tambahan'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000000000000]]);
            $satuan = post('satuan');

            if (mb_strlen($nama) < 3 || mb_strlen($nama) > 100) {
                throw new RuntimeException('Nama fasilitas harus 3–100 karakter.');
            }
            if ($harga === false) {
                throw new RuntimeException('Harga tambahan harus berupa angka (rupiah, tanpa titik).');
            }
            if (mb_strlen($satuan) < 1 || mb_strlen($satuan) > 30) {
                throw new RuntimeException('Satuan harus 1–30 karakter (mis. paket, unit, sesi).');
            }

            if ($id) {
                $pdo->prepare('UPDATE fasilitas SET nama_fasilitas = ?, harga_tambahan = ?, satuan = ? WHERE id = ?')
                    ->execute([$nama, $harga, $satuan, $id]);
                flash('success', 'Fasilitas diperbarui. Reservasi lama tetap memakai harga saat booking.');
            } else {
                $pdo->prepare('INSERT INTO fasilitas (nama_fasilitas, harga_tambahan, satuan) VALUES (?, ?, ?)')
                    ->execute([$nama, $harga, $satuan]);
                flash('success', 'Fasilitas ditambahkan.');
            }
        } elseif ($aksi === 'hapus') {
            $pdo->prepare('DELETE FROM fasilitas WHERE id = ?')->execute([$id]);
            flash('success', 'Fasilitas dihapus.');
        } else {
            throw new RuntimeException('Aksi tidak dikenal.');
        }
    } catch (RuntimeException $ex) {
        flash('danger', $ex->getMessage());
    } catch (PDOException $ex) {
        if ($ex->getCode() === '23000') {
            flash('danger', 'Fasilitas tidak dapat dihapus karena sudah dipakai pada data reservasi.');
        } else {
            error_log($ex->getMessage());
            flash('danger', 'Terjadi kesalahan saat menyimpan data.');
        }
    }
    redirect('admin/fasilitas.php');
}

$list = $pdo->query('SELECT * FROM fasilitas ORDER BY nama_fasilitas')->fetchAll();

$pageTitle = 'Fasilitas Tambahan';
include __DIR__ . '/../includes/header.php';
?>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">Fasilitas Tambahan</h1>
        <button class="btn btn-gold" data-bs-toggle="modal" data-bs-target="#modalFas" data-mode="baru"><i class="bi bi-plus-lg"></i> Tambah Fasilitas</button>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light"><tr><th>Nama fasilitas</th><th class="text-end">Harga tambahan</th><th>Satuan</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($list as $f): ?>
                    <tr>
                        <td><?= e($f['nama_fasilitas']) ?></td>
                        <td class="text-end"><?= rupiah($f['harga_tambahan']) ?></td>
                        <td><?= e($f['satuan']) ?></td>
                        <td class="text-end text-nowrap">
                            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modalFas" data-mode="ubah"
                                    data-id="<?= (int)$f['id'] ?>" data-nama="<?= e($f['nama_fasilitas']) ?>"
                                    data-harga="<?= (int)$f['harga_tambahan'] ?>" data-satuan="<?= e($f['satuan']) ?>">Ubah</button>
                            <form method="post" class="d-inline" data-confirm="Hapus fasilitas &quot;<?= e($f['nama_fasilitas']) ?>&quot;?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="aksi" value="hapus">
                                <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger">Hapus</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$list): ?><tr><td colspan="4" class="text-center text-muted py-4">Belum ada fasilitas.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="modalFas" tabindex="-1" aria-labelledby="modalFasLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="aksi" value="simpan">
            <input type="hidden" name="id" value="0">
            <div class="modal-header">
                <h2 class="modal-title h5" id="modalFasLabel">Fasilitas</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3"><label class="form-label">Nama fasilitas</label><input class="form-control" name="nama_fasilitas" maxlength="100" required></div>
                <div class="row g-3">
                    <div class="col-7"><label class="form-label">Harga tambahan (Rp)</label><input type="number" class="form-control" name="harga_tambahan" min="0" required></div>
                    <div class="col-5"><label class="form-label">Satuan</label><input class="form-control" name="satuan" maxlength="30" value="paket" required></div>
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
    var m = document.getElementById('modalFas');
    m.addEventListener('show.bs.modal', function (ev) {
        var b = ev.relatedTarget, f = m.querySelector('form'), ubah = b.getAttribute('data-mode') === 'ubah';
        m.querySelector('#modalFasLabel').textContent = ubah ? 'Ubah Fasilitas' : 'Tambah Fasilitas';
        f.reset();
        f.id.value = ubah ? b.getAttribute('data-id') : 0;
        if (ubah) {
            f.nama_fasilitas.value = b.getAttribute('data-nama');
            f.harga_tambahan.value = b.getAttribute('data-harga');
            f.satuan.value = b.getAttribute('data-satuan');
        }
    });
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
