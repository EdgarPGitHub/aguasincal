<?php
declare(strict_types=1);

/**
 * Cliente mínimo de la API de DataForSEO (v3) para el estudio de keywords y el seguimiento de posiciones.
 * Credenciales: variables de entorno DATAFORSEO_LOGIN y DATAFORSEO_PASSWORD (nunca en el repositorio).
 *
 *   php scripts/dataforseo.php volumen  docs/keywords/semillas.txt [es|ca|en] > docs/keywords/volumen.csv
 *   php scripts/dataforseo.php ideas    "descalcificador,osmosis inversa" [es]  > docs/keywords/ideas.csv
 *   php scripts/dataforseo.php serp     "descalcificador barcelona" [es]       > docs/keywords/serp-x.csv
 *   php scripts/dataforseo.php saldo
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
    $login = getenv('DATAFORSEO_LOGIN') ?: '';
    $clave = getenv('DATAFORSEO_PASSWORD') ?: '';
    if ($login === '' || $clave === '') {
        throw new RuntimeException('Faltan las variables de entorno DATAFORSEO_LOGIN y DATAFORSEO_PASSWORD.');
    }
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
