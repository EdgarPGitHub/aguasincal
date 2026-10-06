<?php
declare(strict_types=1);

/**
 * Genera la web estática.
 *
 *   php build/build.php [--salida=dist] [--app=..] [--panel=gestion] [--noindex] [--hoy=AAAA-MM-DD] [--datos=data]
 *
 *  --app       ruta del código de la app RELATIVA a la carpeta pública (en el servidor: ../aguasincal-app)
 *  --panel     carpeta del panel de gestión (debe coincidir con panel_ruta de config.local.php)
 *  --noindex   web visible pero fuera de Google (antes del lanzamiento): noindex en todas las páginas y sin sitemap en robots.txt
 *  --datos     carpeta con municipios.csv y agua.csv (por defecto data/; los tests usan tests/fixtures/data)
 *
 * Variables de entorno opcionales: TELEFONO y WHATSAPP (p. ej. "600 12 34 56"); vacías = sin botones de contacto.
 *
 * Sale con código 1 si alguna comprobación SEO falla.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

$op = getopt('', ['salida::', 'app::', 'panel::', 'noindex', 'hoy::', 'datos::']);
$raiz = dirname(__DIR__);
$app = new AguaSinCal\App($raiz, $raiz . '/var', [
    'entorno' => 'build',
    'contacto' => ['telefono' => (string) getenv('TELEFONO'), 'whatsapp' => (string) getenv('WHATSAPP')],
]);
foreach (['TELEFONO', 'WHATSAPP'] as $variable) {
    $valor = trim((string) getenv($variable));
    if ($valor !== '' && AguaSinCal\App::numeroInternacional($valor) === null) {
        fwrite(STDERR, "$variable no es un número de teléfono válido: \"$valor\"\n");
        exit(2);
    }
}

$salida = $op['salida'] ?? $raiz . '/dist';
if (!str_starts_with($salida, '/')) {
    $salida = getcwd() . '/' . $salida;
}
$opciones = [
    'salida' => rtrim($salida, '/'),
    'app' => $op['app'] ?? '..',
    'panel' => $op['panel'] ?? 'gestion',
    'noindex' => isset($op['noindex']),
    'hoy' => $op['hoy'] ?? date('Y-m-d'),
    'datos' => $op['datos'] ?? $raiz . '/data',
];
if (!preg_match('/^[a-z0-9][a-z0-9-]{2,40}$/', $opciones['panel'])) {
    fwrite(STDERR, "--panel debe ser un nombre de carpeta en minúsculas (letras, números y guiones)\n");
    exit(2);
}

$informe = (new AguaSinCal\Build\Generador($app, $opciones))->generar();

foreach ($informe->resumen as $clave => $valor) {
    echo str_pad($clave, 22) . $valor . "\n";
}
if ($informe->avisos) {
    echo "\nAvisos (" . count($informe->avisos) . "):\n";
    foreach ($informe->avisos as $a) {
        echo "  · $a\n";
    }
}
if (!$informe->ok()) {
    fwrite(STDERR, "\nERRORES (" . count($informe->errores) . "):\n");
    foreach ($informe->errores as $e) {
        fwrite(STDERR, "  ✗ $e\n");
    }
    exit(1);
}
echo "\nWeb generada en {$opciones['salida']}\n";
