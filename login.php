<?php
require __DIR__ . '/includes/init.php';

if (current_user()) {
    redirect(home_for_role(current_user()['role']));
}

$errors = [];
$email  = '';
$next   = safe_next($_POST['next'] ?? ($_GET['next'] ?? null));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email = strtolower(post('email'));
    $pass  = $_POST['password'] ?? '';
    $pass  = is_string($pass) ? $pass : '';

    // Pembatasan sederhana: maksimal 8 percobaan gagal per 10 menit per sesi
    $now = time();
    $_SESSION['login_fail'] = array_values(array_filter($_SESSION['login_fail'] ?? [], fn($t) => $t > $now - 600));
    if (count($_SESSION['login_fail']) >= 8) {
        $errors[] = 'Terlalu banyak percobaan login. Coba lagi beberapa menit lagi.';
    } else {
        $st = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $st->execute([$email]);
        $u = $st->fetch();

        // Selalu jalankan password_verify agar waktu respons tidak membocorkan keberadaan akun
        $dummy = '$2y$10$xUneqrKVujG.2MDY8HpJju5R1clgfvPdQeWkAPp9sZsPTo7DWy9xq';
        $ok    = password_verify($pass, $u['password'] ?? $dummy);

        if ($u && $ok) {
            unset($_SESSION['login_fail']);
            if (password_needs_rehash($u['password'], PASSWORD_DEFAULT)) {
                db()->prepare('UPDATE users SET password = ? WHERE id = ?')
                    ->execute([password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
            }
            login_user($u);
            if ($next && $u['role'] === 'customer') {
                header('Location: ' . $next);
                exit;
            }
            redirect(home_for_role($u['role']));
        }
        $_SESSION['login_fail'][] = $now;
        $errors[] = 'Email atau password salah.';
    }
}

$pageTitle = 'Masuk';
include __DIR__ . '/includes/header.php';
?>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="card">
                <div class="card-body p-4">
                    <h1 class="h4 mb-1">Masuk</h1>
                    <p class="text-muted small">Masuk untuk memesan hall dan melihat riwayat reservasi.</p>
                    <?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?= e($er) ?></div><?php endforeach; ?>
                    <form method="post" novalidate>
                        <?= csrf_field() ?>
                        <input type="hidden" name="next" value="<?= e($next ?? '') ?>">
                        <div class="mb-3">
                            <label class="form-label" for="email">Email</label>
                            <input type="email" class="form-control" id="email" name="email" value="<?= e($email) ?>" required autofocus autocomplete="username">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="password">Password</label>
                            <input type="password" class="form-control" id="password" name="password" required autocomplete="current-password">
                        </div>
                        <button class="btn btn-brand w-100" type="submit">Masuk</button>
                    </form>
                    <p class="small text-center mt-3 mb-0">Belum punya akun? <a href="<?= e(url('register.php')) ?>">Daftar di sini</a></p>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
