<?php
declare(strict_types=1);

/**
 * Normaliza datos de calidad del agua al formato de data/agua.csv:
 *   ine;zona;parametro;valor;unidad;fecha;fuente
 *
 * Entrada: un CSV (separado por ; o ,) con columnas ine, zona, parametro, valor, unidad, fecha
 * — por ejemplo, el resultado del sondeo del SINAC o los datos publicados por una empresa de aguas.
 *
 *   php scripts/importar_agua.php entrada.csv "SINAC" >> data/agua.csv
 *
 * Conversiones: dureza en mg/L de CaCO3 → °fH (÷10); en °dH → °fH (×1,78); en mmol/L → °fH (×10).
 * Se descartan filas sin valor numérico o con fecha no válida.
 *
 * NOTA (Fase 0): el sondeo del SINAC se programa cuando el entorno tenga acceso a sinac.sanidad.gob.es,
 * para ver el formato real de sus respuestas antes de escribir el lector.
 */
$archivo = $argv[1] ?? '';
$fuente = $argv[2] ?? 'SINAC';
if (!is_file($archivo)) {
    fwrite(STDERR, "Uso: php scripts/importar_agua.php entrada.csv [fuente]\n");
    exit(2);
}

$parametros = [
    'dureza' => 'dureza', 'dureza total' => 'dureza', 'calcio' => 'calcio', 'magnesio' => 'magnesio',
    'conductividad' => 'conductividad', 'conductividad a 20' => 'conductividad', 'nitrato' => 'nitratos', 'nitratos' => 'nitratos',
    'sodio' => 'sodio', 'cloruro' => 'cloruro', 'cloruros' => 'cloruro', 'sulfato' => 'sulfato', 'sulfatos' => 'sulfato',
    'fluoruro' => 'fluoruro', 'fluoruros' => 'fluoruro', 'ph' => 'ph',
];

$f = fopen($archivo, 'r');
$primera = (string) fgets($f);
$sep = substr_count($primera, ';') >= substr_count($primera, ',') ? ';' : ',';
$cabecera = array_map(fn ($c) => mb_strtolower(trim($c, "\xEF\xBB\xBF \t\"")), str_getcsv(trim($primera), $sep, '"', ''));
$salida = fopen('php://output', 'w');
$leidas = $escritas = 0;
while (($fila = fgetcsv($f, 0, $sep, '"', '')) !== false) {
    $leidas++;
    if (count($fila) !== count($cabecera)) {
        continue;
    }
    $r = array_combine($cabecera, array_map('trim', $fila));
    $ine = str_pad(preg_replace('/\D/', '', (string) ($r['ine'] ?? '')), 5, '0', STR_PAD_LEFT);
    $parametro = $parametros[mb_strtolower((string) ($r['parametro'] ?? ''))] ?? null;
    $valor = str_replace(',', '.', (string) ($r['valor'] ?? ''));
    $fecha = date_create((string) ($r['fecha'] ?? ''));
    if ($parametro === null || !is_numeric($valor) || $fecha === false || strlen($ine) !== 5) {
        continue;
    }
    $valor = (float) $valor;
    $unidad = (string) ($r['unidad'] ?? '');
    if ($parametro === 'dureza') {
        $u = mb_strtolower($unidad);
        $valor = match (true) {
            str_contains($u, 'mg') => $valor / 10,
            str_contains($u, 'dh') || str_contains($u, '°d') => $valor * 1.78,
            str_contains($u, 'mmol') => $valor * 10,
            default => $valor,
        };
        $unidad = '°fH';
    }
    fputcsv($salida, [$ine, $r['zona'] ?? '', $parametro, round($valor, 2), $unidad, $fecha->format('Y-m-d'), $fuente], ';', '"', '');
    $escritas++;
}
fwrite(STDERR, "Filas leídas: $leidas · escritas: $escritas\n");
