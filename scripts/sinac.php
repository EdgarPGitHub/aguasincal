<?php
declare(strict_types=1);

/**
 * Descarga del SINAC (Ministerio de Sanidad, consulta ciudadana) los datos públicos del agua de cada municipio:
 * redes de abastecimiento, población abastecida, gestor, origen, tratamiento, precio y los últimos valores de
 * los parámetros (dureza, calcio, magnesio, nitratos, pH...). Escribe data/sinac.jsonl (una línea por municipio).
 *
 *   php scripts/sinac.php                  # actualiza primero lo que falta y luego lo más antiguo, sin límite
 *   php scripts/sinac.php --minutos=300    # para a los 300 minutos (la siguiente ejecución sigue donde lo dejó)
 *   php scripts/sinac.php --ine=08015      # solo esos municipios (separados por comas), para probar
 *
 * Va sin prisa: una petición cada --pausa segundos (1 por defecto). Las redes grandes tardan hasta ~90 s en
 * responder. Guarda cada 10 municipios; si uno falla, conserva sus datos anteriores.
 */

const BASE = 'https://sinac.sanidad.gob.es/CiudadanoWeb/ciudadano/';
/** Municipios incluidos: provincias objetivo desde 1.000 habitantes y el resto de España desde 10.000. */
const ALCANCE = ['provincias' => ['08', '17', '25', '43', '03', '12', '46', '07', '29'], 'min_objetivo' => 1000, 'min_resto' => 10000];
/** Parámetros que se guardan (código SINAC => clave). */
const PARAMETROS = [
    '065' => 'dureza', '064' => 'calcio', '066' => 'magnesio', '067' => 'potasio', '052' => 'sodio',
    '026' => 'nitrato', '051' => 'ph', '047' => 'conductividad', '046' => 'cloruro', '053' => 'sulfato',
    '045' => 'cloro_libre', '037' => 'thm', '054' => 'turbidez', '022' => 'fluoruro', '055' => 'langelier',
    '029' => 'plomo', '007' => 'arsenico', '048' => 'hierro', '041' => 'aluminio', '018' => 'cobre', '034' => 'pfas',
];

$op = getopt('', ['ine::', 'pausa::', 'minutos::', 'municipios::', 'salida::']);
$raiz = dirname(__DIR__);
$salida = $op['salida'] ?? $raiz . '/data/sinac.jsonl';
$pausa = (float) ($op['pausa'] ?? 1);
$hasta = isset($op['minutos']) ? time() + 60 * (int) $op['minutos'] : PHP_INT_MAX;

// Municipios del alcance (o los pedidos con --ine).
$f = fopen($op['municipios'] ?? $raiz . '/data/municipios.csv', 'r');
$cab = fgetcsv($f, 0, ';', '"', '');
$lista = [];
while (($fila = fgetcsv($f, 0, ';', '"', '')) !== false) {
    $m = array_combine($cab, $fila);
    $objetivo = in_array($m['provincia'], ALCANCE['provincias'], true);
    if (isset($op['ine']) || (int) $m['poblacion'] >= ($objetivo ? ALCANCE['min_objetivo'] : ALCANCE['min_resto'])) {
        $lista[(string) $m['ine']] = [$m['nombre'], (int) $m['poblacion']];
    }
}
fclose($f);
if (isset($op['ine'])) {
    $lista = array_intersect_key($lista, array_flip(explode(',', $op['ine'])));
}

// Orden: primero los que no tienen datos, luego los consultados hace más tiempo; a igualdad, los más poblados.
$datos = leerJsonl($salida);
$pendientes = array_map('strval', array_keys($lista));
usort($pendientes, fn ($a, $b) => [$datos[$a]['consultado'] ?? '', -$lista[$a][1]] <=> [$datos[$b]['consultado'] ?? '', -$lista[$b][1]]);

$hechos = $errores = 0;
foreach ($pendientes as $i => $ine) {
    if (time() >= $hasta) {
        fwrite(STDERR, "Tiempo agotado: quedan " . (count($pendientes) - $i) . " para la próxima ejecución.\n");
        break;
    }
    try {
        $datos[$ine] = ['ine' => $ine, 'consultado' => date('Y-m-d'), 'redes' => municipio($ine, $pausa)];
        $hechos++;
        fwrite(STDERR, sprintf("[%d/%d] %s %s: %d redes\n", $i + 1, count($pendientes), $ine, $lista[$ine][0], count($datos[$ine]['redes'])));
    } catch (Throwable $e) {
        $errores++;
        fwrite(STDERR, sprintf("[%d/%d] %s %s: ERROR %s\n", $i + 1, count($pendientes), $ine, $lista[$ine][0], $e->getMessage()));
    }
    if ($hechos % 10 === 0) {
        guardar($salida, $datos);
    }
}
guardar($salida, $datos);
fwrite(STDERR, "Hecho: $hechos actualizados, $errores errores, " . count($datos) . " municipios en total.\n");
exit($errores > max(5, $hechos / 10) ? 1 : 0);

// ---------------------------------------------------------------------------------------------------------------

function guardar(string $archivo, array $datos): void
{
    ksort($datos, SORT_STRING);
    $tmp = $archivo . '.tmp';
    file_put_contents($tmp, implode('', array_map(fn ($l) => json_encode($l, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", $datos)));
    rename($tmp, $archivo);
}

function leerJsonl(string $archivo): array
{
    $datos = [];
    if (is_file($archivo)) {
        foreach (file($archivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
            $d = json_decode($l, true);
            if (is_array($d) && isset($d['ine'])) {
                $datos[(string) $d['ine']] = $d;
            }
        }
    }
    return $datos;
}

function pedir(string $accion, array $campos, float $pausa): string
{
    static $ch = null;
    for ($intento = 1; ; $intento++) {
        usleep((int) ($pausa * 1e6));
        // Un único manejador con cookies: el detalle de una red exige la sesión abierta al listar las redes.
        $ch ??= curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => BASE . $accion,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($campos),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 240,
            CURLOPT_COOKIEFILE => '',
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; AguaSinCal-datos/1.0)',
        ]);
        $html = curl_exec($ch);
        $codigo = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if (is_string($html) && $codigo === 200 && str_contains($html, '</html>')) {
            return $html;
        }
        if ($intento >= 3) {
            throw new RuntimeException("$accion devolvió $codigo" . (is_string($html) ? ' (' . strlen($html) . ' bytes)' : ''));
        }
        curl_close($ch);
        $ch = null;
        sleep(5 * $intento);
    }
}

/** @return list<array> redes que abastecen a población (las industriales, aeropuertos, etc. tienen 0 y se descartan) */
function municipio(string $ine, float $pausa): array
{
    $html = pedir('informacionRedes.do', ['codMunicipio' => (string) (int) $ine], $pausa);
    preg_match_all('/eleccionRedDistribucion\((\d+)\)/', $html, $m);
    $redes = [];
    foreach (array_unique($m[1]) as $id) {
        $red = red((int) $id, pedir('informacionAbastecimientoActionDetalleRed.do', ['idRed' => $id], $pausa));
        if ($red['poblacion'] > 0) {
            $redes[] = $red;
        }
    }
    usort($redes, fn ($a, $b) => $b['poblacion'] <=> $a['poblacion']);
    return $redes;
}

function texto(string $html): string
{
    $t = preg_replace('/<br\s*\/?>/i', "\n", $html);
    $t = html_entity_decode(strip_tags((string) $t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim((string) preg_replace('/[ \t\r\x{00A0}]+/u', ' ', $t));
}

/** @return array<string, string> secciones de la página por título (legend) */
function secciones(string $html): array
{
    $partes = preg_split('/<legend>(.*?)<\/legend>/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    $sec = [];
    $actual = '';
    for ($i = 1; $i < count($partes) - 1; $i += 2) {
        // Las leyendas vacías (bloques internos) no abren sección: su contenido sigue en la anterior.
        $titulo = trim(preg_replace('/\s+/', ' ', texto($partes[$i])));
        $actual = $titulo !== '' ? $titulo : $actual;
        $sec[$actual] = ($sec[$actual] ?? '') . $partes[$i + 1];
    }
    return $sec;
}

/** @return list<list<string>> filas de tabla como celdas de texto */
function filas(string $html): array
{
    preg_match_all('/<tr[^>]*>(.*?)<\/tr>/s', $html, $tr);
    $filas = [];
    foreach ($tr[1] as $fila) {
        preg_match_all('/<t[dh][^>]*>(.*?)<\/t[dh]>/s', $fila, $td);
        $filas[] = array_map('texto', $td[1]);
    }
    return $filas;
}

function numero(string $v): ?float
{
    $v = trim($v);
    if (!preg_match('/^-?[\d.,]+$/', $v)) {
        return null;
    }
    // El SINAC usa punto decimal en parámetros ("0.6") y punto de miles en población ("121.320").
    return (float) $v;
}

function fecha(string $v): ?string
{
    return preg_match('#^(\d{2})/(\d{2})/(\d{4})#', trim($v), $m) ? "$m[3]-$m[2]-$m[1]" : null;
}

function red(int $id, string $html): array
{
    $sec = secciones($html);
    $buscar = function (string $prefijo) use ($sec): string {
        foreach ($sec as $titulo => $contenido) {
            if (str_starts_with($titulo, $prefijo)) {
                return $contenido;
            }
        }
        return '';
    };
    preg_match('/<legend>\s*(?:&gt;\s*)+(.*?)<\/legend>/s', $html, $t);
    $migas = explode('>', texto($t[1] ?? ''));
    $red = ['id' => $id, 'nombre' => trim((string) end($migas)), 'gestor' => '', 'poblacion' => 0, 'localidades' => [],
        'dotacion' => null, 'origen' => [], 'tratamiento' => [], 'precio_m3' => null, 'parametros' => [], 'control' => [], 'completo' => []];

    foreach (filas($buscar('Red')) as $f) {
        if (($f[0] ?? '') === 'Gestor') {
            $red['gestor'] = $f[1] ?? '';
        }
    }
    foreach (filas($buscar('Suministro')) as $f) {
        if (count($f) === 3 && preg_match('/^[\d.]+$/', $f[1])) {
            $pob = (int) str_replace('.', '', $f[1]);
            $red['localidades'][] = [$f[0], $pob, (int) $f[2]];
            $red['poblacion'] += $pob;
        } elseif (($f[0] ?? '') === 'DOTACION' && preg_match('/(\d+)/', implode(' ', $f), $d)) {
            $red['dotacion'] = (int) $d[1];
        }
    }
    preg_match_all('/<label class="literalMsg">(.*?)\((\d+)\)<\/label>/s', $buscar('Origen del Agua'), $o, PREG_SET_ORDER);
    foreach ($o as $x) {
        $red['origen'][texto($x[1])] = (int) $x[2];
    }
    $procesos = [];
    foreach (filas($buscar('Tratamiento de potabiliz')) as $f) {
        if (!str_starts_with($f[0] ?? '', 'TRATAMIENTO')) {
            continue;
        }
        foreach (explode("\n", $f[1] ?? '') as $p) {
            $p = trim($p);
            if ($p !== '') {
                $procesos[$p] = true;
            }
        }
    }
    $red['tratamiento'] = array_keys($procesos);
    foreach (filas($buscar('Contenido de la factura')) as $f) {
        foreach ($f as $i => $celda) {
            if ($celda === 'Euros/metro cúbico:' && isset($f[$i + 1])) {
                $v = (float) str_replace(',', '.', $f[$i + 1]);
                $red['precio_m3'] = $v > 0 ? $v : null;
            }
        }
    }
    foreach (['control' => 'Análisis de control', 'completo' => 'Análisis de completo'] as $clave => $prefijo) {
        foreach (filas($buscar($prefijo)) as $f) {
            if (($fecha = fecha($f[0] ?? '')) && isset($f[1])) {
                $red[$clave][] = [$fecha, str_starts_with($f[1], 'AGUA APTA') ? 1 : (str_contains($f[1], 'NO APTA') ? 0 : null)];
            }
        }
        $red[$clave] = array_slice($red[$clave], 0, 5);
    }
    foreach (filas($buscar('Últimos valor notificado')) as $f) {
        $clave = PARAMETROS[$f[0] ?? ''] ?? null;
        if ($clave !== null && count($f) >= 5 && ($v = numero($f[2])) !== null && ($fe = fecha($f[4]))) {
            $red['parametros'][$clave] = [$v, $f[3], $fe];
        }
    }
    return $red;
}
