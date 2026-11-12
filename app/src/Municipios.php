<?php
declare(strict_types=1);

namespace AguaSinCal;

/**
 * Municipios de España (data/municipios.csv: INE + centro del término municipal del IGN).
 * Se lee una vez y se guarda como PHP en la caché de datos para que cada petición lo cargue al instante.
 */
final class Municipios
{
    /** @var array<string, array{ine: string, nombre: string, slug: string, provincia: string, poblacion: int, lat: ?float, lon: ?float}>|null */
    private ?array $todos = null;

    public function __construct(private readonly string $archivo, private readonly ?string $dirCache = null)
    {
    }

    /** @return array<string, array> por código INE */
    public function todos(): array
    {
        if ($this->todos !== null) {
            return $this->todos;
        }
        if (!is_file($this->archivo)) {
            return $this->todos = [];
        }
        $cache = $this->dirCache ? $this->dirCache . '/municipios-' . filemtime($this->archivo) . '.php' : null;
        if ($cache && is_file($cache)) {
            return $this->todos = require $cache;
        }
        $todos = [];
        $f = fopen($this->archivo, 'r');
        $cab = fgetcsv($f, 0, ';', '"', '');
        while (($fila = fgetcsv($f, 0, ';', '"', '')) !== false) {
            if (count($fila) !== count($cab)) {
                continue;
            }
            $m = array_combine($cab, $fila);
            $todos[(string) $m['ine']] = [
                'ine' => (string) $m['ine'],
                'nombre' => $m['nombre'],
                'slug' => $m['slug'],
                'provincia' => $m['provincia'],
                'poblacion' => (int) $m['poblacion'],
                'lat' => $m['lat'] !== '' ? (float) $m['lat'] : null,
                'lon' => $m['lon'] !== '' ? (float) $m['lon'] : null,
            ];
        }
        fclose($f);
        if ($cache) {
            if (!is_dir($this->dirCache)) {
                mkdir($this->dirCache, 0750, true);
            }
            $temporal = $cache . '.' . bin2hex(random_bytes(4));
            file_put_contents($temporal, '<?php return ' . var_export($todos, true) . ';');
            rename($temporal, $cache);
            // Cada despliegue cambia la fecha del CSV: se borran las cachés anteriores.
            foreach (glob($this->dirCache . '/municipios-*.php') ?: [] as $viejo) {
                if ($viejo !== $cache) {
                    @unlink($viejo);
                }
            }
        }
        return $this->todos = $todos;
    }

    public function obtener(string $ine): ?array
    {
        return $this->todos()[$ine] ?? null;
    }

    public function porSlug(string $provincia, string $slug): ?array
    {
        foreach ($this->todos() as $m) {
            if ($m['provincia'] === $provincia && $m['slug'] === $slug) {
                return $m;
            }
        }
        return null;
    }

    /**
     * Busca por nombre (sin tildes ni mayúsculas): primero coincidencias exactas, luego por el principio y luego
     * dentro del nombre; a igualdad, los más poblados.
     * @return list<array>
     */
    public function buscar(string $texto, ?string $provincia = null, int $max = 10): array
    {
        $q = self::normalizar($texto);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $encontrados = [];
        foreach ($this->todos() as $m) {
            if ($provincia !== null && $m['provincia'] !== $provincia) {
                continue;
            }
            $nivel = null;
            foreach (explode('/', $m['nombre']) as $variante) {
                $n = self::normalizar($variante);
                $sinArticulo = (string) preg_replace("/^(el|la|los|las|l'|els|les|a|o|es|sa|ses) ?/", '', $n);
                $nivel = match (true) {
                    $n === $q || $sinArticulo === $q => 0,
                    str_starts_with($n, $q) || str_starts_with($sinArticulo, $q) => min($nivel ?? 1, 1),
                    str_contains($n, $q) => min($nivel ?? 2, 2),
                    default => $nivel,
                };
            }
            if ($nivel !== null) {
                $encontrados[] = [$nivel, -$m['poblacion'], $m];
            }
        }
        usort($encontrados, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        return array_map(fn ($e) => $e[2], array_slice($encontrados, 0, $max));
    }

    public static function normalizar(string $texto): string
    {
        $t = mb_strtolower(trim($texto));
        $t = strtr($t, ['l·l' => 'll', 'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'ï' => 'i', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'ú' => 'u', 'ü' => 'u',
            'ñ' => 'n', 'ç' => 'c', '’' => "'"]);
        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace("/[^a-z0-9' ]+/", ' ', $t)));
    }

    public static function distanciaKm(array $a, array $b): float
    {
        if ($a['lat'] === null || $b['lat'] === null) {
            return INF;
        }
        $rad = M_PI / 180;
        $dLat = ($b['lat'] - $a['lat']) * $rad;
        $dLon = ($b['lon'] - $a['lon']) * $rad;
        $h = sin($dLat / 2) ** 2 + cos($a['lat'] * $rad) * cos($b['lat'] * $rad) * sin($dLon / 2) ** 2;
        return 6371 * 2 * asin(min(1, sqrt($h)));
    }
}
