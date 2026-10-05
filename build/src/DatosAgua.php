<?php
declare(strict_types=1);

namespace AguaSinCal\Build;

use AguaSinCal\Agua;

/**
 * Datos de municipios (INE) y calidad del agua (SINAC) desde data/*.csv.
 *
 * data/municipios.csv: ine;nombre;slug;provincia;poblacion;lat;lon;capital
 * data/agua.csv:       ine;zona;parametro;valor;unidad;fecha;fuente
 *   parametro "dureza" siempre en °fH (los importadores convierten desde mg/L de CaCO3).
 */
final class DatosAgua
{
    /** Valores paramétricos del RD 3/2023 que se muestran como referencia. */
    public const PARAMETROS = [
        'conductividad' => ['nombre' => 'Conductividad', 'limite' => '2.500 µS/cm'],
        'nitratos' => ['nombre' => 'Nitratos', 'limite' => '50 mg/L'],
        'sodio' => ['nombre' => 'Sodio', 'limite' => '200 mg/L'],
        'cloruro' => ['nombre' => 'Cloruros', 'limite' => '250 mg/L'],
        'sulfato' => ['nombre' => 'Sulfatos', 'limite' => '250 mg/L'],
        'fluoruro' => ['nombre' => 'Fluoruros', 'limite' => '1,5 mg/L'],
        'calcio' => ['nombre' => 'Calcio', 'limite' => null],
        'magnesio' => ['nombre' => 'Magnesio', 'limite' => null],
        'ph' => ['nombre' => 'pH', 'limite' => '6,5 – 9,5'],
    ];

    /** @var array<string, array> municipios por código INE */
    private array $municipios = [];

    public function __construct(private readonly string $dir)
    {
        $this->cargar();
    }

    /** @return array<string, array> */
    public function municipios(): array
    {
        return $this->municipios;
    }

    /** @return list<array> municipios de una provincia con datos de dureza, ordenados por nombre */
    public function deProvincia(string $provincia): array
    {
        $lista = array_values(array_filter($this->municipios, fn ($m) => $m['provincia'] === $provincia && $m['dureza_fh'] !== null));
        usort($lista, fn ($a, $b) => strcoll($a['nombre'], $b['nombre']));
        return $lista;
    }

    public function municipio(string $ine): ?array
    {
        return $this->municipios[$ine] ?? null;
    }

    /** @return list<array> los $n municipios con datos más cercanos (misma provincia) */
    public function cercanos(array $m, int $n, callable $filtro): array
    {
        if ($m['lat'] === null) {
            return [];
        }
        $candidatos = [];
        foreach ($this->municipios as $otro) {
            if ($otro['ine'] === $m['ine'] || $otro['lat'] === null || $otro['dureza_fh'] === null || !$filtro($otro)) {
                continue;
            }
            $candidatos[] = [$this->distancia($m, $otro), $otro];
        }
        usort($candidatos, fn ($a, $b) => $a[0] <=> $b[0]);
        return array_map(fn ($c) => $c[1], array_slice($candidatos, 0, $n));
    }

    private function cargar(): void
    {
        foreach ($this->leerCsv($this->dir . '/municipios.csv') as $f) {
            $this->municipios[$f['ine']] = [
                'ine' => $f['ine'],
                'nombre' => $f['nombre'],
                'slug' => $f['slug'],
                'provincia' => $f['provincia'],
                'poblacion' => (int) $f['poblacion'],
                'lat' => $f['lat'] !== '' ? (float) $f['lat'] : null,
                'lon' => $f['lon'] !== '' ? (float) $f['lon'] : null,
                'capital' => ($f['capital'] ?? '0') === '1',
                'zonas' => [],
                'parametros' => [],
                'dureza_fh' => null,
                'min_fh' => null,
                'max_fh' => null,
                'fecha' => null,
            ];
        }
        $valores = [];
        foreach ($this->leerCsv($this->dir . '/agua.csv') as $f) {
            if (!isset($this->municipios[$f['ine']]) || $f['valor'] === '') {
                continue;
            }
            $valores[$f['ine']][$f['parametro']][$f['zona']] = ['valor' => (float) str_replace(',', '.', $f['valor']), 'unidad' => $f['unidad'], 'fecha' => $f['fecha']];
        }
        foreach ($valores as $ine => $porParametro) {
            $m = &$this->municipios[$ine];
            foreach ($porParametro['dureza'] ?? [] as $zona => $v) {
                $m['zonas'][] = ['nombre' => $zona, 'dureza_fh' => $v['valor'], 'fecha' => $v['fecha']];
            }
            if ($m['zonas']) {
                $durezas = array_column($m['zonas'], 'dureza_fh');
                $m['dureza_fh'] = round(array_sum($durezas) / count($durezas), 1);
                $m['min_fh'] = round(min($durezas), 1);
                $m['max_fh'] = round(max($durezas), 1);
                $m['fecha'] = max(array_column($m['zonas'], 'fecha'));
                usort($m['zonas'], fn ($a, $b) => $b['dureza_fh'] <=> $a['dureza_fh']);
            }
            foreach (self::PARAMETROS as $clave => $def) {
                if (empty($porParametro[$clave])) {
                    continue;
                }
                $vals = array_column($porParametro[$clave], 'valor');
                $m['parametros'][] = [
                    'clave' => $clave,
                    'nombre' => $def['nombre'],
                    'valor' => array_sum($vals) / count($vals),
                    'unidad' => array_values($porParametro[$clave])[0]['unidad'],
                    'limite' => $def['limite'],
                ];
            }
            $m['recomendacion'] = Agua::recomendacion($m['dureza_fh']);
            unset($m);
        }
        foreach ($this->municipios as &$m) {
            $m['recomendacion'] ??= Agua::recomendacion(null);
        }
    }

    /** @return list<array<string,string>> */
    private function leerCsv(string $archivo): array
    {
        if (!is_file($archivo)) {
            return [];
        }
        $f = fopen($archivo, 'r');
        $cabecera = fgetcsv($f, 0, ';', '"', '');
        if (!$cabecera) {
            return [];
        }
        $cabecera = array_map(fn ($c) => trim((string) $c, "\xEF\xBB\xBF \t"), $cabecera);
        $filas = [];
        while (($fila = fgetcsv($f, 0, ';', '"', '')) !== false) {
            if ($fila === [null] || count($fila) !== count($cabecera)) {
                continue;
            }
            $filas[] = array_combine($cabecera, array_map('trim', $fila));
        }
        fclose($f);
        return $filas;
    }

    private function distancia(array $a, array $b): float
    {
        $rad = M_PI / 180;
        $dLat = ($b['lat'] - $a['lat']) * $rad;
        $dLon = ($b['lon'] - $a['lon']) * $rad;
        $h = sin($dLat / 2) ** 2 + cos($a['lat'] * $rad) * cos($b['lat'] * $rad) * sin($dLon / 2) ** 2;
        return 6371 * 2 * asin(min(1, sqrt($h)));
    }
}
