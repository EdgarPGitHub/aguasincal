<?php
declare(strict_types=1);

/**
 * Arranque común de los puntos de entrada PHP (formulario, acciones, panel, eventos).
 * Devuelve la instancia de App.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

$app = \AguaSinCal\App::desdeEntorno(dirname(__DIR__));

date_default_timezone_set('Europe/Madrid');
if ($app->esProduccion()) {
    $logs = $app->datos . '/logs';
    if (!is_dir($logs)) {
        mkdir($logs, 0750, true);
    }
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', $logs . '/php-error.log');
}

set_exception_handler(static function (\Throwable $e): void {
    error_log('[aguasincal] ' . $e::class . ': ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    echo 'Ha ocurrido un error. Inténtalo de nuevo en unos minutos o escríbenos.';
});

return $app;
