<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
if (isset($pdo) && $pdo instanceof PDO) { return; }

/** Membuat koneksi PDO dengan zona waktu sesi yang sama dengan PHP agar perhitungan umur data konsisten. */
if (!function_exists('neo_connect')) {
function neo_connect(string $dbname): PDO
{
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', neo_config('db_host'), neo_config('db_port'), $dbname);
    $pdo = new PDO($dsn, (string)neo_config('db_user'), (string)neo_config('db_pass'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $offset = (new DateTime('now'))->format('P');
    $pdo->exec("SET time_zone = '" . $offset . "'");
    return $pdo;
}
}

if (!function_exists('neo_db_fail')) {
    function neo_db_fail(string $label, Throwable $e): never
    {
        http_response_code(500);
        $detail = neo_config('debug') ? '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>' : '';
        die('<!doctype html><meta charset="utf-8"><title>' . APP_NAME . '</title><body style="font-family:system-ui;max-width:560px;margin:12vh auto;padding:0 20px">'
            . '<h1>' . APP_NAME . '</h1><p><strong>' . htmlspecialchars($label) . '</strong></p>'
            . '<p>Periksa pengaturan di <code>config.php</code> / <code>config.local.php</code> (host, port, user, nama database) dan pastikan database sudah diimpor (lihat <code>sql/README_SQL.txt</code>).</p>'
            . $detail . '</body>');
    }
}

try {
    $pdo = neo_connect((string)neo_config('db_name'));
} catch (Throwable $e) {
    neo_db_fail('Koneksi database utama gagal.', $e);
}
$GLOBALS['pdo'] = $pdo;
