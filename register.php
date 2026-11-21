<?php
require __DIR__ . '/includes/init.php';

if (current_user()) {
    redirect(home_for_role(current_user()['role']));
}

$errors = [];
$old    = ['nama' => '', 'email' => '', 'no_hp' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $old['nama']  = post('nama');
    $old['email'] = strtolower(post('email'));
    $old['no_hp'] = preg_replace('/[\s\-]/', '', post('no_hp'));
    $pass  = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $pass2 = is_string($_POST['password2'] ?? null) ? $_POST['password2'] : '';

    if (mb_strlen($old['nama']) < 3 || mb_strlen($old['nama']) > 100) {
        $errors[] = 'Nama harus 3–100 karakter.';
    }
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL) || strlen($old['email']) > 150) {
        $errors[] = 'Format email tidak valid.';
    }
    if (!preg_match('/^(\+62|62|0)8[0-9]{8,12}$/', $old['no_hp'])) {
        $errors[] = 'Nomor HP tidak valid (contoh: 081234567890).';
    }
    if (strlen($pass) < 8 || !preg_match('/[A-Za-z]/', $pass) || !preg_match('/\d/', $pass)) {
        $errors[] = 'Password minimal 8 karakter dan mengandung huruf serta angka.';
    }
    if ($pass !== $pass2) {
        $errors[] = 'Konfirmasi password tidak sama.';
    }

    if (!$errors) {
        $pdo = db();
        $cek = $pdo->prepare('SELECT 1 FROM users WHERE email = ?');
        $cek->execute([$old['email']]);
        if ($cek->fetchColumn()) {
            $errors[] = 'Email sudah terdaftar.';
        } else {
            try {
                $pdo->prepare('INSERT INTO users (nama, email, password, no_hp, role) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$old['nama'], $old['email'], password_hash($pass, PASSWORD_DEFAULT), $old['no_hp'], 'customer']);
                flash('success', 'Registrasi berhasil. Silakan masuk.');
                redirect('login.php');
            } catch (PDOException $ex) {
                if ($ex->getCode() === '23000') {
                    $errors[] = 'Email sudah terdaftar.';
                } else {
                    error_log($ex->getMessage());
                    $errors[] = 'Terjadi kesalahan. Silakan coba lagi.';
                }
            }
        }
    }
}

$pageTitle = 'Daftar';
include __DIR__ . '/includes/header.php';
?>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6">
            <div class="card">
                <div class="card-body p-4">
                    <h1 class="h4 mb-1">Daftar Akun Pelanggan</h1>
                    <p class="text-muted small">Gratis dan hanya butuh satu menit.</p>
                    <?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?= e($er) ?></div><?php endforeach; ?>
                    <form method="post" novalidate>
                        <?= csrf_field() ?>
                        <div class="mb-3">
                            <label class="form-label" for="nama">Nama lengkap</label>
                            <input class="form-control" id="nama" name="nama" value="<?= e($old['nama']) ?>" maxlength="100" required>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label" for="email">Email</label>
                                <input type="email" class="form-control" id="email" name="email" value="<?= e($old['email']) ?>" maxlength="150" required autocomplete="username">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="no_hp">No. HP / WhatsApp</label>
                                <input class="form-control" id="no_hp" name="no_hp" value="<?= e($old['no_hp']) ?>" inputmode="tel" required>
                            </div>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label" for="password">Password</label>
                                <input type="password" class="form-control" id="password" name="password" minlength="8" required autocomplete="new-password">
                                <div class="form-text">Minimal 8 karakter, huruf dan angka.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="password2">Ulangi password</label>
                                <input type="password" class="form-control" id="password2" name="password2" required autocomplete="new-password">
                            </div>
                        </div>
                        <button class="btn btn-brand w-100" type="submit">Daftar</button>
                    </form>
                    <p class="small text-center mt-3 mb-0">Sudah punya akun? <a href="<?= e(url('login.php')) ?>">Masuk</a></p>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
