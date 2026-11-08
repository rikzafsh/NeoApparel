<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
if (isset($pdoKms) && $pdoKms instanceof PDO) { return; }

try {
    $pdoKms = neo_connect((string)neo_config('db_kms_name'));
} catch (Throwable $e) {
    neo_db_fail('Koneksi database KMS gagal.', $e);
}
$GLOBALS['pdoKms'] = $pdoKms;
