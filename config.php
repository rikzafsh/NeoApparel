<?php
declare(strict_types=1);
/**
 * Neo Apparel - konfigurasi pusat.
 * Untuk mengubah pengaturan lokal, salin config.local.example.php menjadi config.local.php
 * (file tersebut tidak perlu diubah di repositori). Variabel lingkungan NEO_* juga didukung.
 */
const APP_NAME = 'Neo Apparel';
const APP_TAGLINE = 'Fashion · Operasi · Insight';
const APP_VERSION = '2.0.0';

function neo_config(string $key, mixed $default = null): mixed
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = [
            'db_host'      => getenv('NEO_DB_HOST') ?: 'localhost',
            'db_port'      => getenv('NEO_DB_PORT') ?: '3307',
            'db_name'      => getenv('NEO_DB_NAME') ?: 'db_neo_apparel',
            'db_kms_name'  => getenv('NEO_DB_KMS_NAME') ?: 'db_neo_apparel_kms',
            'db_user'      => getenv('NEO_DB_USER') ?: 'root',
            'db_pass'      => getenv('NEO_DB_PASS') ?: '',
            'timezone'     => getenv('NEO_TIMEZONE') ?: 'Asia/Jakarta',
            'shipping_flat'=> 15000,
            'debug'        => false,
        ];
        $local = __DIR__ . '/config.local.php';
        if (is_file($local)) {
            $over = require $local;
            if (is_array($over)) {
                $cfg = array_replace($cfg, $over);
            }
        }
        date_default_timezone_set((string)$cfg['timezone']);
    }
    return $cfg[$key] ?? $default;
}
neo_config('timezone'); // pastikan zona waktu PHP diset sedini mungkin
