<?php
/**
 * Menjalankan siklus inferensi terjadwal dari CLI.
 *   php es_cron.php            -> semua domain
 *   php es_cron.php TPS,MIS    -> domain tertentu
 * Contoh cron (setiap 30 menit):  *\/30 * * * * php /path/neo_apparel/es_cron.php >> /var/log/neo_es.log 2>&1
 * Windows Task Scheduler: php.exe C:\xampp\htdocs\neo_apparel\es_cron.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Hanya dapat dijalankan dari CLI.'); }
chdir(__DIR__);
require_once 'es_engine.php';
$doms = isset($argv[1]) && $argv[1] !== '' ? array_filter(array_map('trim', explode(',', strtoupper($argv[1])))) : null;
try {
    $r = es_run($pdo, ['trigger' => 'schedule', 'label' => 'cron', 'domains' => $doms ? array_merge($doms, ['STYLE']) : null]);
    echo date('c'), " OK run#{$r['run_id']} entitas={$r['entities']} fakta={$r['facts']} kesimpulan={$r['inferences']} baru={$r['new']} selesai={$r['resolved']} tindakan={$r['actions']} {$r['duration_ms']}ms\n";
} catch (Throwable $e) {
    fwrite(STDERR, date('c') . ' GAGAL: ' . $e->getMessage() . "\n");
    exit(1);
}
