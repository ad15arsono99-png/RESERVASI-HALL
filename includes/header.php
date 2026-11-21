<?php
/**
 * Header + navbar. Set $pageTitle sebelum meng-include file ini.
 */
$u          = current_user();
$pageTitle  = $pageTitle ?? APP_NAME;
$scriptName = basename($_SERVER['SCRIPT_NAME']);
$adminBadge = 0;
if ($u && $u['role'] === 'admin') {
    $adminBadge = (int)db()->query(
        "SELECT COUNT(*) FROM pembayaran_pembatalan WHERE status_verifikasi = 'Menunggu'"
    )->fetchColumn();
}
function nav_active(string $file): string
{
    global $scriptName;
    return $scriptName === $file ? ' active' : '';
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> · <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= e(url('assets/css/style.css')) ?>" rel="stylesheet">
</head>
<body class="d-flex flex-column min-vh-100">
<nav class="navbar navbar-expand-lg navbar-dark bg-brand sticky-top no-print">
    <div class="container">
        <a class="navbar-brand fw-semibold" href="<?= e(url('index.php')) ?>">
            <i class="bi bi-building me-1 text-gold"></i><?= e(APP_NAME) ?>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav" aria-label="Menu">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="nav">
            <ul class="navbar-nav me-auto">
                <?php if (!$u || $u['role'] === 'customer'): ?>
                    <li class="nav-item"><a class="nav-link<?= nav_active('index.php') ?>" href="<?= e(url('index.php')) ?>">Beranda</a></li>
                <?php endif; ?>
                <?php if ($u && $u['role'] === 'customer'): ?>
                    <li class="nav-item"><a class="nav-link<?= nav_active('booking.php') ?>" href="<?= e(url('booking.php')) ?>">Buat Reservasi</a></li>
                    <li class="nav-item"><a class="nav-link<?= nav_active('riwayat.php') ?>" href="<?= e(url('customer/riwayat.php')) ?>">Riwayat</a></li>
                <?php elseif ($u && $u['role'] === 'admin'): ?>
                    <li class="nav-item"><a class="nav-link<?= nav_active('dashboard.php') ?>" href="<?= e(url('admin/dashboard.php')) ?>">Dashboard</a></li>
                    <li class="nav-item">
                        <a class="nav-link<?= nav_active('verifikasi.php') ?>" href="<?= e(url('admin/verifikasi.php')) ?>">
                            Verifikasi
                            <?php if ($adminBadge > 0): ?><span class="badge rounded-pill bg-danger"><?= $adminBadge ?></span><?php endif; ?>
                        </a>
                    </li>
                    <li class="nav-item"><a class="nav-link<?= nav_active('halls.php') ?>" href="<?= e(url('admin/halls.php')) ?>">Data Hall</a></li>
                    <li class="nav-item"><a class="nav-link<?= nav_active('fasilitas.php') ?>" href="<?= e(url('admin/fasilitas.php')) ?>">Fasilitas</a></li>
                <?php elseif ($u && $u['role'] === 'owner'): ?>
                    <li class="nav-item"><a class="nav-link<?= nav_active('laporan.php') ?>" href="<?= e(url('owner/laporan.php')) ?>">Laporan</a></li>
                <?php endif; ?>
            </ul>
            <ul class="navbar-nav align-items-lg-center">
                <?php if ($u): ?>
                    <li class="nav-item me-lg-2 text-white-50 small">
                        <i class="bi bi-person-circle"></i> <?= e($u['nama']) ?>
                        <span class="badge bg-light text-dark ms-1"><?= e(ucfirst($u['role'])) ?></span>
                    </li>
                    <li class="nav-item">
                        <form method="post" action="<?= e(url('logout.php')) ?>" class="d-inline">
                            <?= csrf_field() ?>
                            <button class="btn btn-sm btn-outline-light" type="submit">Keluar</button>
                        </form>
                    </li>
                <?php else: ?>
                    <li class="nav-item"><a class="nav-link" href="<?= e(url('login.php')) ?>">Masuk</a></li>
                    <li class="nav-item"><a class="btn btn-sm btn-gold ms-lg-2" href="<?= e(url('register.php')) ?>">Daftar</a></li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>
<main class="flex-grow-1">
<?php $flashHtml = render_flash(); if ($flashHtml !== ''): ?>
    <div class="container mt-3"><?= $flashHtml ?></div>
<?php endif; ?>
