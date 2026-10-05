<?php
declare(strict_types=1);

/**
 * Tarea diaria (cron del hosting):
 *  1. Copia de seguridad de la base de datos en backups/ (se conservan 14 días).
 *  2. Anonimiza leads antiguos (24 meses; lista de espera 12 meses).
 *  3. Purga los registros de límite de intentos.
 *
 *   php ~/aguasincal-app/scripts/cron-diario.php
 */
$app = require dirname(__DIR__) . '/app/bootstrap.php';
$db = $app->db();
$ahora = time();

$dir = $app->datos . '/backups';
if (!is_dir($dir)) {
    mkdir($dir, 0700, true);
}
$copia = $dir . '/aguasincal-' . gmdate('Y-m-d') . '.sqlite';
if (!is_file($copia) && $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
    $db->exec('VACUUM INTO ' . $db->quote($copia));
    chmod($copia, 0600);
}
foreach (glob($dir . '/aguasincal-*.sqlite') ?: [] as $f) {
    if (filemtime($f) < $ahora - 14 * 86400) {
        unlink($f);
    }
}

$anonimizados = $app->leads()->anonimizarAntiguos($ahora);
$purgados = $app->limitador()->purgar($ahora - 86400);
echo gmdate('c') . " copia=" . basename($copia) . " anonimizados=$anonimizados intentos_purgados=$purgados\n";
