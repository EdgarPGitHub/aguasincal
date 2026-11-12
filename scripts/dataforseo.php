<?php
declare(strict_types=1);

/**
 * Cliente mínimo de la API de DataForSEO (v3): estudio de keywords, posiciones y empresas para el directorio.
 * Credenciales: variables de entorno DATAFORSEO_LOGIN y DATAFORSEO_PASSWORD (nunca en el repositorio). En el
 * entorno de Claude las añade el proxy de red, así que las variables pueden faltar.
 *
 *   php scripts/dataforseo.php volumen  docs/keywords/semillas.txt [es|ca|en] > docs/keywords/volumen.csv
 *   php scripts/dataforseo.php ideas    "descalcificador,osmosis inversa" [es]  > docs/keywords/ideas.csv
 *   php scripts/dataforseo.php serp     "descalcificador barcelona" [es]       > docs/keywords/serp-x.csv
 *   php scripts/dataforseo.php saldo
 *   php scripts/dataforseo.php categorias "water"                    # categorías de Google Maps que contienen el texto
 *   php scripts/dataforseo.php empresas 50000 > docs/empresas-candidatas.csv   # candidatas en ciudades de 50.000+ hab.
 *
 * Mercado: España (location_code 2724). Cada llamada muestra su coste en la salida de error.
 */
const API = 'https://api.dataforseo.com/v3/';
const ESPANA = 2724;

$orden = $argv[1] ?? '';
$idioma = $argv[3] ?? 'es';

try {
    switch ($orden) {
        case 'volumen':
            $keywords = leerLista((string) ($argv[2] ?? ''));
            $filas = [];
            foreach (array_chunk($keywords, 700) as $lote) {
                $r = llamar('keywords_data/google_ads/search_volume/live', [[
                    'keywords' => $lote, 'location_code' => ESPANA, 'language_code' => $idioma,
                ]]);
                foreach ($r['tasks'][0]['result'] ?? [] as $k) {
                    $filas[] = [$k['keyword'], $k['search_volume'] ?? 0, $k['competition'] ?? '', $k['cpc'] ?? ''];
                }
            }
            usort($filas, fn ($a, $b) => $b[1] <=> $a[1]);
            csv(['keyword', 'volumen_mensual', 'competencia', 'cpc_eur'], $filas);
            break;

        case 'ideas':
            $semillas = array_map('trim', explode(',', (string) ($argv[2] ?? '')));
            $r = llamar('dataforseo_labs/google/keyword_ideas/live', [[
                'keywords' => $semillas, 'location_code' => ESPANA, 'language_code' => $idioma, 'limit' => 1000,
                'filters' => [['keyword_info.search_volume', '>', 0]],
                'order_by' => ['keyword_info.search_volume,desc'],
            ]]);
            $filas = [];
            foreach ($r['tasks'][0]['result'][0]['items'] ?? [] as $i) {
                $filas[] = [
                    $i['keyword'],
                    $i['keyword_info']['search_volume'] ?? 0,
                    $i['keyword_properties']['keyword_difficulty'] ?? '',
                    $i['search_intent_info']['main_intent'] ?? '',
                    $i['keyword_info']['cpc'] ?? '',
                ];
            }
            csv(['keyword', 'volumen_mensual', 'dificultad', 'intencion', 'cpc_eur'], $filas);
            break;

        case 'serp':
            $keyword = (string) ($argv[2] ?? '');
            $r = llamar('serp/google/organic/live/advanced', [[
                'keyword' => $keyword, 'location_code' => ESPANA, 'language_code' => $idioma, 'depth' => 20,
            ]]);
            $filas = [];
            foreach ($r['tasks'][0]['result'][0]['items'] ?? [] as $i) {
                if (($i['type'] ?? '') === 'organic') {
                    $filas[] = [$keyword, $i['rank_group'], $i['domain'], $i['url'], $i['title'] ?? ''];
                }
            }
            csv(['keyword', 'posicion', 'dominio', 'url', 'titulo'], $filas);
            break;

        case 'categorias':
            $r = llamar('business_data/business_listings/categories', null);
            foreach ($r['tasks'][0]['result'] ?? [] as $c) {
                $nombre = is_array($c) ? ($c['category_name'] ?? $c['category'] ?? '') : (string) $c;
                if (stripos($nombre, (string) ($argv[2] ?? '')) !== false) {
                    fwrite(STDOUT, $nombre . "\n");
                }
            }
            break;

        case 'empresas':
            empresas((int) ($argv[2] ?? 50000));
            break;

        case 'saldo':
            $r = llamar('appendix/user_data', null);
            $d = $r['tasks'][0]['result'][0] ?? [];
            fwrite(STDOUT, 'Saldo: ' . ($d['money']['balance'] ?? '?') . " USD\n");
            break;

        default:
            fwrite(STDERR, "Uso: php scripts/dataforseo.php volumen <archivo> [idioma] | ideas \"a,b\" [idioma] | serp \"keyword\" [idioma] | saldo\n");
            exit(2);
    }
} catch (RuntimeException $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

function llamar(string $endpoint, ?array $cuerpo): array
{
    // Sin variables, se envía un valor de relleno: en el entorno de Claude el proxy pone la credencial real.
    $login = getenv('DATAFORSEO_LOGIN') ?: 'credencial-del-proxy';
    $clave = getenv('DATAFORSEO_PASSWORD') ?: 'credencial-del-proxy';
    $ch = curl_init(API . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $login . ':' . $clave,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 120,
    ]);
    if ($cuerpo !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($cuerpo, JSON_UNESCAPED_UNICODE));
    }
    $respuesta = curl_exec($ch);
    $codigo = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($respuesta === false) {
        throw new RuntimeException("Sin respuesta de DataForSEO: $error");
    }
    $datos = json_decode((string) $respuesta, true);
    if ($codigo !== 200 || !is_array($datos) || ($datos['status_code'] ?? 0) !== 20000) {
        throw new RuntimeException("DataForSEO respondió $codigo: " . ($datos['status_message'] ?? substr((string) $respuesta, 0, 200)));
    }
    $tarea = $datos['tasks'][0] ?? [];
    if (isset($tarea['status_code']) && $tarea['status_code'] !== 20000) {
        throw new RuntimeException('Tarea con error: ' . ($tarea['status_message'] ?? '?'));
    }
    fwrite(STDERR, sprintf("[%s] coste %.4f USD\n", $endpoint, (float) ($datos['cost'] ?? 0)));
    return $datos;
}

/** @return list<string> */
function leerLista(string $archivo): array
{
    if (!is_file($archivo)) {
        throw new RuntimeException("No existe $archivo");
    }
    $lineas = array_map('trim', file($archivo) ?: []);
    return array_values(array_unique(array_filter($lineas, fn ($l) => $l !== '' && $l[0] !== '#')));
}

function csv(array $cabecera, array $filas): void
{
    $out = fopen('php://output', 'w');
    fputcsv($out, $cabecera, ';', '"', '');
    foreach ($filas as $f) {
        fputcsv($out, $f, ';', '"', '');
    }
    fclose($out);
}

/**
 * Empresas candidatas para el directorio (fichas «auto») en las ciudades grandes de las provincias objetivo.
 * Criterio pedido: ni las más conocidas ni las peores, algo intermedio. Por ciudad: valoración >= 4 con al menos
 * 5 opiniones, se saltan las 2 con más opiniones y se toman las 3 siguientes. Salida para revisar a mano y,
 * después, pasar a data/empresas.csv.
 */
function empresas(int $poblacionMinima): void
{
    $categorias = ['water_softening_equipment_supplier', 'water_treatment_supplier', 'water_purification_company', 'water_filter_supplier'];
    $provincias = ['08', '17', '25', '43', '29', '03', '12', '46', '07'];
    $municipios = [];
    $f = fopen(dirname(__DIR__) . '/data/municipios.csv', 'r');
    $cab = fgetcsv($f, 0, ';', '"', '');
    while (($fila = fgetcsv($f, 0, ';', '"', '')) !== false) {
        $m = array_combine($cab, $fila);
        $municipios[$m['ine']] = $m;
    }
    $ciudades = array_filter($municipios, fn ($m) => in_array($m['provincia'], $provincias, true) && (int) $m['poblacion'] >= $poblacionMinima && $m['lat'] !== '');
    $vistas = [];
    $filas = [];
    foreach ($ciudades as $c) {
        $r = llamar('business_data/business_listings/search/live', [[
            'categories' => $categorias,
            'location_coordinate' => sprintf('%s,%s,8', $c['lat'], $c['lon']),
            'filters' => [['rating.votes_count', '>=', 5]],
            'order_by' => ['rating.votes_count,desc'],
            'limit' => 30,
        ]]);
        $items = $r['tasks'][0]['result'][0]['items'] ?? [];
        $elegidas = 0;
        foreach (array_slice($items, 2) as $i) {
            $id = (string) ($i['place_id'] ?? $i['cid'] ?? $i['title']);
            if (isset($vistas[$id]) || ($i['rating']['value'] ?? 0) < 4) {
                continue;
            }
            $vistas[$id] = true;
            $muni = municipioMasCercano($municipios, (float) ($i['latitude'] ?? $c['lat']), (float) ($i['longitude'] ?? $c['lon']), $c['provincia']);
            $filas[] = [
                $i['title'] ?? '', $muni['ine'], $muni['nombre'], $c['nombre'], $i['category'] ?? '', implode(',', $i['additional_categories'] ?? []),
                $i['rating']['value'] ?? '', $i['rating']['votes_count'] ?? '', $i['phone'] ?? '', $i['url'] ?? '', $i['address'] ?? '',
            ];
            if (++$elegidas >= 3) {
                break;
            }
        }
    }
    csv(['nombre', 'municipio_ine', 'municipio', 'ciudad_buscada', 'categoria', 'otras_categorias', 'valoracion', 'opiniones', 'telefono', 'web', 'direccion'], $filas);
}

function municipioMasCercano(array $municipios, float $lat, float $lon, string $provincia): array
{
    $mejor = null;
    $distancia = INF;
    foreach ($municipios as $m) {
        if ($m['provincia'] !== $provincia || $m['lat'] === '') {
            continue;
        }
        $d = ($m['lat'] - $lat) ** 2 + (($m['lon'] - $lon) * cos(deg2rad($lat))) ** 2;
        if ($d < $distancia) {
            [$mejor, $distancia] = [$m, $d];
        }
    }
    return $mejor;
}
