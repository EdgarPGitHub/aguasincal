<?php
declare(strict_types=1);

/**
 * Genera data/municipios.csv (ine;nombre;slug;provincia;poblacion;lat;lon;capital) con fuentes oficiales:
 *  - Población: INE, Cifras oficiales del Padrón (tabla 29005, último año) y capitales (tabla 2911).
 *  - Coordenadas: centro del término municipal, calculado con los límites municipales del IGN
 *    (Equipamiento Geográfico de Referencia Nacional, CC BY 4.0) que publica el paquete npm es-atlas.
 *
 *   php scripts/municipios.php > data/municipios.csv
 *
 * Se ejecuta una vez al año (cuando el INE publica el padrón). No toca nada más.
 */

const ATLAS = 'https://registry.npmjs.org/es-atlas/-/es-atlas-0.6.0.tgz';
const INE = 'https://servicios.ine.es/wstempus/js/ES/DATOS_TABLA/';

function descargar(string $url): string
{
    $ctx = stream_context_create(['http' => ['timeout' => 300, 'header' => "User-Agent: aguasincal-datos\r\n"]]);
    $datos = @file_get_contents($url, false, $ctx);
    if ($datos === false || $datos === '') {
        fwrite(STDERR, "No se pudo descargar $url\n");
        exit(1);
    }
    return $datos;
}

/** "Hospitalet de Llobregat, L'" → "L'Hospitalet de Llobregat"; "Alacant/Alicante" se queda con las dos formas. */
function nombreComun(string $oficial): string
{
    $partes = [];
    foreach (explode('/', $oficial) as $nombre) {
        $nombre = trim($nombre);
        if (preg_match("/^(.*), (el|la|los|las|l'|els|les|a|o|as|os|es|sa|ses|lo|s')$/iu", $nombre, $m)) {
            $articulo = mb_strtoupper(mb_substr($m[2], 0, 1)) . mb_substr($m[2], 1);
            $nombre = str_ends_with($articulo, "'") ? $articulo . $m[1] : $articulo . ' ' . $m[1];
        }
        $partes[] = $nombre;
    }
    return implode('/', $partes);
}

function slug(string $texto): string
{
    $t = mb_strtolower($texto);
    $t = strtr($t, ['l·l' => 'll', 'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'ï' => 'i', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'ú' => 'u', 'ü' => 'u',
        'ñ' => 'n', 'ç' => 'c']);
    return trim((string) preg_replace('/[^a-z0-9]+/', '-', $t), '-');
}

/** Centro (centroide del polígono mayor) de cada municipio a partir del TopoJSON cuantizado. */
function centros(array $topo): array
{
    [$sx, $sy] = $topo['transform']['scale'];
    [$tx, $ty] = $topo['transform']['translate'];
    $arcos = [];
    foreach ($topo['arcs'] as $arco) {
        $x = $y = 0;
        $puntos = [];
        foreach ($arco as [$dx, $dy]) {
            $x += $dx;
            $y += $dy;
            $puntos[] = [$x * $sx + $tx, $y * $sy + $ty];
        }
        $arcos[] = $puntos;
    }
    $anillo = function (array $indices) use ($arcos): array {
        $puntos = [];
        foreach ($indices as $i) {
            $tramo = $i >= 0 ? $arcos[$i] : array_reverse($arcos[~$i]);
            if ($puntos) {
                array_shift($tramo);
            }
            array_push($puntos, ...$tramo);
        }
        return $puntos;
    };
    $centros = [];
    foreach ($topo['objects']['municipalities']['geometries'] as $g) {
        $poligonos = match ($g['type'] ?? '') {
            'Polygon' => [$g['arcs']],
            'MultiPolygon' => $g['arcs'],
            default => [],
        };
        $mejor = null;
        foreach ($poligonos as $p) {
            $pts = $anillo($p[0]);
            $a = $cx = $cy = 0.0;
            for ($i = 0, $n = count($pts) - 1; $i < $n; $i++) {
                [$x0, $y0] = $pts[$i];
                [$x1, $y1] = $pts[$i + 1];
                $f = $x0 * $y1 - $x1 * $y0;
                $a += $f;
                $cx += ($x0 + $x1) * $f;
                $cy += ($y0 + $y1) * $f;
            }
            if ($a != 0.0 && ($mejor === null || abs($a) > $mejor[0])) {
                $mejor = [abs($a), $cy / (3 * $a), $cx / (3 * $a)];
            }
        }
        if ($mejor !== null) {
            $centros[(string) $g['id']] = [round($mejor[1], 4), round($mejor[2], 4)];
        }
    }
    return $centros;
}

// 1. Límites municipales (IGN) → centros.
$tgz = tempnam(sys_get_temp_dir(), 'atlas') . '.tgz';
file_put_contents($tgz, descargar(ATLAS));
$phar = new PharData($tgz);
$topo = json_decode((string) file_get_contents($phar['package/es/municipalities.json']->getPathname()), true, 512, JSON_THROW_ON_ERROR);
unlink($tgz);
$centros = centros($topo);

// 2. Población (INE, último año) y capitales de provincia.
$poblacion = [];
foreach (json_decode(descargar(INE . '29005?nult=1&tip=AM'), true, 512, JSON_THROW_ON_ERROR) as $serie) {
    $meta = array_column($serie['MetaData'], null, 'T3_Variable');
    if (($meta['Sexo']['Codigo'] ?? '') !== '0' || ($meta['Tamaño de los municipios']['Codigo'] ?? '') !== '0') {
        continue;
    }
    $codigo = (string) ($meta['Municipios']['Codigo'] ?? '');
    if (strlen($codigo) === 5 && isset($serie['Data'][0]['Valor'])) {
        $poblacion[$codigo] = [$meta['Municipios']['Nombre'], (int) $serie['Data'][0]['Valor']];
    }
}
$capitales = [];
foreach (json_decode(descargar(INE . '2911?nult=1&tip=AM'), true, 512, JSON_THROW_ON_ERROR) as $serie) {
    foreach ($serie['MetaData'] as $m) {
        if (preg_match('/^\d{5}$/', (string) ($m['Codigo'] ?? ''))) {
            $capitales[$m['Codigo']] = true;
        }
    }
}
$capitales['51001'] = $capitales['52001'] = true; // Ceuta y Melilla: ciudades autónomas de un solo municipio
if (count($poblacion) < 8000 || count($capitales) < 52) {
    fwrite(STDERR, 'Datos del INE incompletos: ' . count($poblacion) . ' municipios, ' . count($capitales) . " capitales\n");
    exit(1);
}

// 3. Salida, ordenada por código INE; slugs únicos dentro de cada provincia.
ksort($poblacion, SORT_STRING);
$usados = [];
$out = fopen('php://output', 'w');
fputcsv($out, ['ine', 'nombre', 'slug', 'provincia', 'poblacion', 'lat', 'lon', 'capital'], ';', '"', '');
$sinCentro = 0;
foreach ($poblacion as $ine => [$oficial, $habitantes]) {
    $ine = (string) $ine;
    $nombre = nombreComun($oficial);
    $provincia = substr($ine, 0, 2);
    $slug = slug($nombre);
    if (isset($usados[$provincia][$slug])) {
        $slug .= '-' . $ine;
    }
    $usados[$provincia][$slug] = true;
    [$lat, $lon] = $centros[$ine] ?? ['', ''];
    $sinCentro += $lat === '' ? 1 : 0;
    fputcsv($out, [$ine, $nombre, $slug, $provincia, $habitantes, $lat, $lon, isset($capitales[$ine]) ? '1' : '0'], ';', '"', '');
}
fwrite(STDERR, count($poblacion) . ' municipios, ' . count($capitales) . " capitales, $sinCentro sin coordenadas\n");
