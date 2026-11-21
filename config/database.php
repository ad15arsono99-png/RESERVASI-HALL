<?php
/**
 * Koneksi database (PDO, prepared statements asli, exception mode).
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            error_log('Koneksi DB gagal: ' . $e->getMessage());
            http_response_code(500);
            exit('Tidak dapat terhubung ke database. Periksa pengaturan di config/config.php.');
        }
    }
    return $pdo;
}
