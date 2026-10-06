<?php
declare(strict_types=1);

/**
 * Tareas diarias (opcional): la web ya las ejecuta sola una vez al día (AguaSinCal\Mantenimiento).
 * Útil si prefieres un cron del hosting o forzarlas a mano:
 *
 *   php ~/aguasincal-app/scripts/cron-diario.php
 */
$app = require dirname(__DIR__) . '/app/bootstrap.php';
$r = (new AguaSinCal\Mantenimiento($app))->ejecutar(time());
echo gmdate('c') . " copia={$r['copia']} anonimizados={$r['anonimizados']} intentos_purgados={$r['intentos_purgados']}\n";
