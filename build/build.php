<?php
declare(strict_types=1);

/**
 * Genera la web estática.
 *
 *   php build/build.php [--salida=dist] [--app=..] [--panel=gestion] [--protegido --htpasswd=/ruta/.htpasswd] [--hoy=AAAA-MM-DD]
 *
 *  --app       ruta del código de la app RELATIVA a la carpeta pública (en el servidor: ../aguasincal-app)
 *  --panel     carpeta del panel de gestión (debe coincidir con panel_ruta de config.local.php)
 *  --protegido pide usuario y contraseña en toda la web y la marca como noindex (antes del lanzamiento)
 *  --datos     carpeta con municipios.csv y agua.csv (por defecto data/; los tests usan tests/fixtures/data)
 *
 * Sale con código 1 si alguna comprobación SEO falla.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

$op = getopt('', ['salida::', 'app::', 'panel::', 'protegido', 'htpasswd::', 'hoy::', 'datos::']);
$raiz = dirname(__DIR__);
$app = new AguaSinCal\App($raiz, $raiz . '/var', ['entorno' => 'build']);

$salida = $op['salida'] ?? $raiz . '/dist';
if (!str_starts_with($salida, '/')) {
    $salida = getcwd() . '/' . $salida;
}
$opciones = [
    'salida' => rtrim($salida, '/'),
    'app' => $op['app'] ?? '..',
    'panel' => $op['panel'] ?? 'gestion',
    'protegido' => isset($op['protegido']),
    'htpasswd' => $op['htpasswd'] ?? '',
    'hoy' => $op['hoy'] ?? date('Y-m-d'),
    'datos' => $op['datos'] ?? $raiz . '/data',
];
if ($opciones['protegido'] && $opciones['htpasswd'] === '') {
    fwrite(STDERR, "--protegido necesita --htpasswd=/ruta/absoluta/.htpasswd\n");
    exit(2);
}
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
