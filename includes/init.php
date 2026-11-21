<?php
/**
 * Bootstrap aplikasi: konfigurasi, session aman, dan fungsi bantu.
 * Wajib di-require di baris pertama setiap halaman.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

date_default_timezone_set('Asia/Jakarta');

if (session_status() === PHP_SESSION_NONE) {
    session_name('HALLSESS');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

/* =====================================================================
 *  Output, URL, input
 * ===================================================================== */
function e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

function post(string $key): string
{
    $v = $_POST[$key] ?? '';
    return is_string($v) ? trim($v) : '';
}

function get_str(string $key): string
{
    $v = $_GET[$key] ?? '';
    return is_string($v) ? trim($v) : '';
}

/** Hanya izinkan redirect ke path internal aplikasi (cegah open redirect). */
function safe_next(?string $next): ?string
{
    if (!$next || strpos($next, BASE_URL . '/') !== 0) {
        return null;
    }
    if (substr($next, 0, 2) === '//' || preg_match('/[\r\n\\\\]/', $next)) {
        return null;
    }
    return $next;
}

/* =====================================================================
 *  Format
 * ===================================================================== */
function rupiah($n): string
{
    return 'Rp ' . number_format((int)$n, 0, ',', '.');
}

function tgl_indo(?string $tgl, bool $hari = false): string
{
    if (!$tgl) {
        return '-';
    }
    $t = strtotime($tgl);
    if ($t === false) {
        return '-';
    }
    $bln = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $hr  = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    $s   = date('j', $t) . ' ' . $bln[(int)date('n', $t) - 1] . ' ' . date('Y', $t);
    return $hari ? $hr[(int)date('w', $t)] . ', ' . $s : $s;
}

function waktu_indo(?string $dt): string
{
    if (!$dt) {
        return '-';
    }
    return tgl_indo($dt) . ' ' . date('H:i', strtotime($dt));
}

function badge_status(string $s): string
{
    $map = [
        'Pending'          => 'bg-warning text-dark',
        'DP Terkonfirmasi' => 'bg-info text-dark',
        'Lunas'            => 'bg-success',
        'Dibatalkan'       => 'bg-secondary',
    ];
    return '<span class="badge ' . ($map[$s] ?? 'bg-light text-dark') . '">' . e($s) . '</span>';
}

function badge_verif(string $s): string
{
    $map = [
        'Menunggu'      => 'bg-warning text-dark',
        'Terverifikasi' => 'bg-success',
        'Ditolak'       => 'bg-danger',
    ];
    return '<span class="badge ' . ($map[$s] ?? 'bg-light text-dark') . '">' . e($s) . '</span>';
}

/* =====================================================================
 *  Flash message & CSRF
 * ===================================================================== */
function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function render_flash(): string
{
    $out = '';
    foreach ($_SESSION['flash'] ?? [] as $f) {
        $out .= '<div class="alert alert-' . e($f['type']) . ' alert-dismissible fade show" role="alert">'
              . e($f['msg'])
              . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button></div>';
    }
    unset($_SESSION['flash']);
    return $out;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): void
{
    $t = $_POST['_csrf'] ?? '';
    if (!is_string($t) || !hash_equals($_SESSION['csrf'] ?? '', $t)) {
        http_response_code(419);
        exit('Sesi formulir kedaluwarsa. Silakan kembali dan muat ulang halaman.');
    }
}

/* =====================================================================
 *  Autentikasi & otorisasi
 * ===================================================================== */
function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function login_user(array $u): void
{
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id'    => (int)$u['id'],
        'nama'  => $u['nama'],
        'email' => $u['email'],
        'role'  => $u['role'],
    ];
    unset($_SESSION['csrf']);
}

function home_for_role(string $role): string
{
    switch ($role) {
        case 'admin': return 'admin/dashboard.php';
        case 'owner': return 'owner/laporan.php';
        default:      return 'customer/riwayat.php';
    }
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        flash('warning', 'Silakan login terlebih dahulu.');
        $next = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' ? ($_SERVER['REQUEST_URI'] ?? '') : '';
        redirect('login.php' . ($next ? '?next=' . urlencode($next) : ''));
    }
    return $u;
}

function require_role(string ...$roles): array
{
    $u = require_login();
    if (!in_array($u['role'], $roles, true)) {
        http_response_code(403);
        exit('Akses ditolak.');
    }
    return $u;
}

/* =====================================================================
 *  Upload gambar aman (bukti transfer & foto hall)
 * ===================================================================== */
/**
 * @return array [bool sukses, string namaFile|pesanError]
 */
function upload_image(array $file, string $dir, string $prefix = 'img'): array
{
    if (!isset($file['error']) || is_array($file['error'])) {
        return [false, 'Unggahan tidak valid.'];
    }
    switch ($file['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_NO_FILE:
            return [false, 'File belum dipilih.'];
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return [false, 'Ukuran file melebihi batas ' . (MAX_UPLOAD_BYTES / 1048576) . ' MB.'];
        default:
            return [false, 'Gagal mengunggah file.'];
    }
    if ($file['size'] > MAX_UPLOAD_BYTES) {
        return [false, 'Ukuran file melebihi batas ' . (MAX_UPLOAD_BYTES / 1048576) . ' MB.'];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return [false, 'Unggahan tidak valid.'];
    }

    $finfo   = new finfo(FILEINFO_MIME_TYPE);
    $mime    = $finfo->file($file['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) {
        return [false, 'Format file harus JPG, PNG, atau WEBP.'];
    }
    $info = @getimagesize($file['tmp_name']);
    if ($info === false || $info[0] < 1 || $info[1] < 1 || ($info[0] * $info[1]) > 40000000) {
        return [false, 'File bukan gambar yang valid.'];
    }

    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return [false, 'Folder penyimpanan tidak dapat dibuat.'];
    }
    $name = $prefix . '_' . bin2hex(random_bytes(12)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        return [false, 'Gagal menyimpan file.'];
    }
    @chmod($dir . '/' . $name, 0644);
    return [true, $name];
}

function hapus_file(string $dir, ?string $name): void
{
    if ($name) {
        $path = $dir . '/' . basename($name);
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

/* =====================================================================
 *  Logika bisnis
 * ===================================================================== */
/** Apakah tanggal sudah terkunci (DP Terkonfirmasi / Lunas) pada hall tersebut? */
function tanggal_terkunci(PDO $pdo, int $hallId, string $tgl, ?int $kecuali = null): bool
{
    $sql = "SELECT 1 FROM reservasi
            WHERE hall_id = ? AND tanggal_acara = ? AND status IN ('DP Terkonfirmasi','Lunas')";
    $p = [$hallId, $tgl];
    if ($kecuali) {
        $sql .= ' AND id <> ?';
        $p[] = $kecuali;
    }
    $st = $pdo->prepare($sql . ' LIMIT 1');
    $st->execute($p);
    return (bool)$st->fetchColumn();
}

function min_dp(int $total): int
{
    return intdiv($total * DP_PERSEN + 99, 100); // pembulatan ke atas
}

/** Selisih hari (bisa negatif) dari tanggal $dari ke $ke. */
function selisih_hari(string $dari, string $ke): int
{
    $a = new DateTimeImmutable($dari);
    $b = new DateTimeImmutable($ke);
    return (int)$a->diff($b)->format('%r%a');
}

/** Persentase refund DP sesuai kebijakan. */
function persen_refund_dp(int $selisihHari, string $oleh = 'Customer'): int
{
    if ($oleh === 'Pengelola') {
        return 100;
    }
    if ($selisihHari > 30) {
        return 75;
    }
    if ($selisihHari >= 14) {
        return 50;
    }
    return 0;
}

/**
 * Hitung refund. DP mengikuti kebijakan; pelunasan (jika sudah dibayar)
 * dikembalikan sebesar REFUND_PELUNASAN_PERSEN (100% jika dibatalkan pengelola).
 */
function hitung_refund(int $dpTerbayar, int $pelunasanTerbayar, int $selisihHari, string $oleh = 'Customer'): array
{
    $persen = persen_refund_dp($selisihHari, $oleh);
    $rDp    = intdiv($dpTerbayar * $persen, 100);
    $pp     = $oleh === 'Pengelola' ? 100 : REFUND_PELUNASAN_PERSEN;
    $rPel   = intdiv($pelunasanTerbayar * $pp, 100);
    return [
        'persen'           => $persen,
        'refund_dp'        => $rDp,
        'refund_pelunasan' => $rPel,
        'total'            => $rDp + $rPel,
        'dp_hangus'        => $dpTerbayar - $rDp,
    ];
}

/** Total pembayaran yang SUDAH terverifikasi untuk sebuah reservasi. */
function total_terbayar(PDO $pdo, int $reservasiId): array
{
    $st = $pdo->prepare(
        "SELECT jenis_transaksi, COALESCE(SUM(nominal),0) AS total
         FROM pembayaran_pembatalan
         WHERE reservasi_id = ? AND jenis_transaksi IN ('DP','Pelunasan') AND status_verifikasi = 'Terverifikasi'
         GROUP BY jenis_transaksi"
    );
    $st->execute([$reservasiId]);
    $out = ['dp' => 0, 'pelunasan' => 0];
    foreach ($st->fetchAll() as $row) {
        $out[$row['jenis_transaksi'] === 'DP' ? 'dp' : 'pelunasan'] = (int)$row['total'];
    }
    return $out;
}

function generate_kode_booking(): string
{
    return 'HL' . date('ymd') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
}
