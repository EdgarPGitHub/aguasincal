<?php
declare(strict_types=1);

namespace AguaSinCal\Build;

use AguaSinCal\Agua;

/**
 * Datos de municipios (INE + IGN) y del agua de cada red de abastecimiento (SINAC).
 *
 * data/municipios.csv: ine;nombre;slug;provincia;poblacion;lat;lon;capital   (scripts/municipios.php)
 * data/sinac.jsonl:    una línea JSON por municipio con sus redes            (scripts/sinac.php)
 *
 * Por municipio: dureza media ponderada por la población que abastece cada red (°fH), la de cada zona,
 * el resto de parámetros (media ponderada), gestor, origen del agua, precio y el último análisis de control.
 */
final class DatosAgua
{
    /** Parámetros que se muestran, con su valor paramétrico del RD 3/2023 cuando lo tiene. */
    public const PARAMETROS = [
        'calcio' => ['nombre' => 'Calcio', 'limite' => null],
        'magnesio' => ['nombre' => 'Magnesio', 'limite' => null],
        'sodio' => ['nombre' => 'Sodio', 'limite' => '200 mg/L'],
        'nitrato' => ['nombre' => 'Nitratos', 'limite' => '50 mg/L'],
        'cloruro' => ['nombre' => 'Cloruros', 'limite' => '250 mg/L'],
        'sulfato' => ['nombre' => 'Sulfatos', 'limite' => '250 mg/L'],
        'fluoruro' => ['nombre' => 'Fluoruros', 'limite' => '1,5 mg/L'],
        'conductividad' => ['nombre' => 'Conductividad', 'limite' => '2.500 µS/cm'],
        'ph' => ['nombre' => 'pH', 'limite' => '6,5 – 9,5'],
        'cloro_libre' => ['nombre' => 'Cloro libre residual', 'limite' => '1 mg/L'],
        'thm' => ['nombre' => 'Trihalometanos (suma)', 'limite' => '100 µg/L'],
        'plomo' => ['nombre' => 'Plomo', 'limite' => '10 µg/L'],
        'arsenico' => ['nombre' => 'Arsénico', 'limite' => '10 µg/L'],
        'hierro' => ['nombre' => 'Hierro', 'limite' => '200 µg/L'],
        'aluminio' => ['nombre' => 'Aluminio', 'limite' => '200 µg/L'],
        'pfas' => ['nombre' => 'PFAS (suma de 20)', 'limite' => '0,1 µg/L'],
    ];

    /** Tipos de captación del SINAC agrupados en lenguaje claro. */
    private const ORIGENES = [
        'Río/Embalse/Canal' => 'superficial', 'Lago/Laguna' => 'superficial',
        'Pozo Entubado' => 'subterránea', 'Pozo Excavado' => 'subterránea', 'Galería' => 'subterránea', 'Manantial' => 'subterránea',
        'Costera/Mar/Puerto' => 'desalada',
    ];

    /** Precio del agua creíble (€/m³); fuera de este rango el dato del SINAC suele ser un error de carga. */
    private const PRECIO_MIN = 0.2;
    private const PRECIO_MAX = 6.0;

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

    /** @return list<array> los $n municipios con datos más cercanos que cumplen $filtro */
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
            $candidatos[] = [self::distanciaKm($m, $otro), $otro];
        }
        usort($candidatos, fn ($a, $b) => $a[0] <=> $b[0]);
        return array_map(fn ($c) => $c[1], array_slice($candidatos, 0, $n));
    }

    public static function distanciaKm(array $a, array $b): float
    {
        $rad = M_PI / 180;
        $dLat = ($b['lat'] - $a['lat']) * $rad;
        $dLon = ($b['lon'] - $a['lon']) * $rad;
        $h = sin($dLat / 2) ** 2 + cos($a['lat'] * $rad) * cos($b['lat'] * $rad) * sin($dLon / 2) ** 2;
        return 6371 * 2 * asin(min(1, sqrt($h)));
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
                'agua' => null,
                'recomendacion' => Agua::recomendacion(null),
            ];
        }
        $archivo = $this->dir . '/sinac.jsonl';
        if (!is_file($archivo)) {
            return;
        }
        foreach (file($archivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
            $d = json_decode($linea, true);
            if (is_array($d) && isset($this->municipios[(string) ($d['ine'] ?? '')])) {
                $this->combinar($this->municipios[(string) $d['ine']], $d);
            }
        }
    }

    /** Resume las redes del SINAC de un municipio. */
    private function combinar(array &$m, array $d): void
    {
        $redes = array_values(array_filter($d['redes'] ?? [], fn ($r) => ($r['poblacion'] ?? 0) > 0));
        if (!$redes) {
            return;
        }
        foreach ($redes as $r) {
            $dureza = self::durezaMgL($r['parametros'] ?? [], $d['consultado'] ?? null);
            if ($dureza !== null) {
                $m['zonas'][] = [
                    'nombre' => self::nombreRed($r['nombre']),
                    'dureza_fh' => round($dureza['mg'] / 10, 1),
                    'fecha' => $dureza['fecha'],
                    'calculada' => $dureza['calculada'],
                    'poblacion' => (int) $r['poblacion'],
                    'gestor' => self::nombreGestor($r['gestor'] ?? ''),
                ];
            }
        }
        if ($m['zonas']) {
            $m['dureza_fh'] = round(self::ponderada(array_map(fn ($z) => [$z['dureza_fh'], $z['poblacion']], $m['zonas'])), 1);
            $durezas = array_column($m['zonas'], 'dureza_fh');
            $m['min_fh'] = min($durezas);
            $m['max_fh'] = max($durezas);
            $m['fecha'] = max(array_column($m['zonas'], 'fecha'));
            $m['recomendacion'] = Agua::recomendacion($m['dureza_fh']);
        }

        foreach (self::PARAMETROS as $clave => $def) {
            $valores = [];
            $unidad = '';
            foreach ($redes as $r) {
                if (isset($r['parametros'][$clave])) {
                    [$v, $u] = $r['parametros'][$clave];
                    $valores[] = [(float) $v, (int) $r['poblacion']];
                    $unidad = $u;
                }
            }
            if ($valores) {
                $unidad = ['Unidades pH' => '', 'µS/cm a 20ºC' => 'µS/cm', 'µS/cm a 20°C' => 'µS/cm'][$unidad] ?? $unidad;
                $m['parametros'][] = ['clave' => $clave, 'nombre' => $def['nombre'], 'valor' => self::ponderada($valores), 'unidad' => $unidad, 'limite' => $def['limite']];
            }
        }

        // Datos de la red principal (la que abastece a más población) y del conjunto.
        $principal = $redes[0];
        foreach ($redes as $r) {
            $principal = $r['poblacion'] > $principal['poblacion'] ? $r : $principal;
        }
        $origenes = [];
        $osmosis = false;
        foreach ($redes as $r) {
            foreach (array_keys($r['origen'] ?? []) as $tipo) {
                $origenes[self::ORIGENES[$tipo] ?? 'otros'] = true;
            }
            foreach ($r['tratamiento'] ?? [] as $proceso) {
                $osmosis = $osmosis || str_contains($proceso, 'Ósmosis inversa');
            }
        }
        $precios = [];
        foreach ($redes as $r) {
            $p = $r['precio_m3'] ?? null;
            if ($p !== null && $p >= self::PRECIO_MIN && $p <= self::PRECIO_MAX) {
                $precios[] = [(float) $p, (int) $r['poblacion']];
            }
        }
        $control = $principal['control'][0] ?? null;
        $m['agua'] = [
            'redes' => count($redes),
            'poblacion_abastecida' => array_sum(array_column($redes, 'poblacion')),
            'gestor' => self::nombreGestor($principal['gestor'] ?? ''),
            'origenes' => array_values(array_intersect(['superficial', 'subterránea', 'desalada'], array_keys($origenes))),
            'osmosis_planta' => $osmosis,
            'precio_m3' => $precios ? round(self::ponderada($precios), 2) : null,
            'control' => $control ? ['fecha' => $control[0], 'apta' => $control[1]] : null,
            // Un 0 exacto suele ser «sin dato» en el SINAC: solo se muestra si hay valor.
            'langelier' => !empty($principal['parametros']['langelier'][0]) ? (float) $principal['parametros']['langelier'][0] : null,
            'consultado' => $d['consultado'] ?? null,
        ];
    }

    /**
     * Dureza total en mg/L de CaCO3 de una red, con comprobación de coherencia.
     * Si hay calcio, se contrasta con la dureza calculada (2,497·Ca + 4,118·Mg): algunas empresas publican la
     * dureza en °fH aunque la etiqueten en mg/L (se multiplica por 10) y otras cargan valores que no cuadran
     * (se usa el dato más reciente; a igual fecha, el calculado). Se descartan muestras de hace más de 5 años.
     * @return array{mg: float, fecha: string, calculada: bool}|null
     */
    public static function durezaMgL(array $p, ?string $consultado = null): ?array
    {
        $limite = date('Y-m-d', strtotime(($consultado ?: date('Y-m-d')) . ' -5 years'));
        $directa = isset($p['dureza']) && $p['dureza'][0] > 0 && $p['dureza'][2] >= $limite
            ? ['mg' => (float) $p['dureza'][0], 'fecha' => (string) $p['dureza'][2], 'calculada' => false] : null;
        $calculada = null;
        if (isset($p['calcio']) && $p['calcio'][0] > 0 && $p['calcio'][2] >= $limite) {
            $mg = round(2.497 * $p['calcio'][0] + 4.118 * ($p['magnesio'][0] ?? 0));
            $calculada = ['mg' => $mg, 'fecha' => (string) $p['calcio'][2], 'calculada' => true];
        }
        $elegida = match (true) {
            $directa === null => $calculada,
            $calculada === null => $directa,
            $directa['mg'] >= 0.6 * $calculada['mg'] && $directa['mg'] <= 1.6 * $calculada['mg'] => $directa,
            $directa['mg'] * 10 >= 0.6 * $calculada['mg'] && $directa['mg'] * 10 <= 1.6 * $calculada['mg'] => ['mg' => $directa['mg'] * 10] + $directa,
            $directa['fecha'] > $calculada['fecha'] => $directa,
            default => $calculada,
        };
        return $elegida !== null && $elegida['mg'] >= 5 && $elegida['mg'] <= 1500 ? $elegida : null;
    }

    /** "XARXA TER CARDEDEU (ALELLA)" → "Xarxa Ter Cardedeu (Alella)" */
    private static function nombreRed(string $nombre): string
    {
        $t = mb_convert_case(mb_strtolower(trim($nombre)), MB_CASE_TITLE);
        $t = (string) preg_replace_callback("/\\b(De|Del|La|Las|El|Los|Y|En|D'|L')\\b/u", fn ($x) => mb_strtolower($x[1]), $t);
        // l'Aigua, d'Aigües; números romanos (Canal de Isabel II).
        $t = (string) preg_replace_callback("/\\b([DdLl])'(\\p{L})/u", fn ($x) => mb_strtolower($x[1]) . "'" . mb_strtoupper($x[2]), $t);
        $t = (string) preg_replace_callback('/\\b(Ii|Iii|Iv|Vi|Vii|Viii|Ix|Xi|Xii)\\b/u', fn ($x) => mb_strtoupper($x[1]), $t);
        // Formas jurídicas en mayúsculas: S.A., S.L.U., etc.
        return (string) preg_replace_callback('/\\b(?:[A-Za-z]\\.){2,4}/u', fn ($x) => mb_strtoupper($x[0]), $t);
    }

    private static function nombreGestor(string $gestor): string
    {
        return $gestor === '' ? '' : self::nombreRed($gestor);
    }

    /** @param list<array{0: float, 1: int}> $valores [valor, peso] */
    private static function ponderada(array $valores): float
    {
        $suma = $pesos = 0.0;
        foreach ($valores as [$v, $peso]) {
            $peso = max(1, $peso);
            $suma += $v * $peso;
            $pesos += $peso;
        }
        return $suma / $pesos;
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
}
