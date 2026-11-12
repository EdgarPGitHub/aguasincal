<?php
declare(strict_types=1);

namespace AguaSinCal;

use PDO;

/**
 * Directorio público de empresas. Cada empresa es una fila de "profesionales" con ficha pública:
 * la misma ficha sirve para el directorio y para recibir leads.
 *
 * Orden en un municipio: primero las destacadas vigentes y luego el resto; dentro de cada grupo, de más cerca
 * a más lejos (la prioridad interna del panel, 0 por defecto, puede subir o bajar una ficha en su grupo).
 * Cada empresa aparece en los municipios dentro de su radio.
 */
final class Directorio
{
    public const RADIOS = [10, 25, 50];
    /** Empresas mínimas para que la página de un municipio sea indexable. */
    public const MIN_INDEXABLE = 3;
    /** Descripción mínima (caracteres) para indexar la ficha de una empresa. */
    public const MIN_DESCRIPCION_INDEXABLE = 120;
    public const ORIGENES = ['auto' => 'Añadida por AguaSinCal', 'empresa' => 'Alta de la empresa', 'propia' => 'Propia'];
    public const FICHAS = ['pendiente' => 'Pendiente de revisión', 'publicada' => 'Publicada', 'oculta' => 'Oculta'];

    public function __construct(private readonly PDO $db, private readonly Municipios $municipios, private readonly array $servicios)
    {
    }

    public function porId(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM profesionales WHERE id = ?');
        $st->execute([$id]);
        $fila = $st->fetch();
        return $fila ? $this->completar($fila) : null;
    }

    /** Ficha publicada por slug (o null). */
    public function publicada(string $slug): ?array
    {
        $st = $this->db->prepare("SELECT * FROM profesionales WHERE slug = ? AND ficha = 'publicada' AND activo = 1");
        $st->execute([$slug]);
        $fila = $st->fetch();
        return $fila ? $this->completar($fila) : null;
    }

    /** @return list<array> fichas con ese email */
    public function porEmail(string $email): array
    {
        $st = $this->db->prepare('SELECT * FROM profesionales WHERE lower(email) = lower(?) AND slug IS NOT NULL');
        $st->execute([trim($email)]);
        return array_map(fn ($f) => $this->completar($f), $st->fetchAll());
    }

    /** @return list<array> todas las fichas publicadas */
    public function publicadas(): array
    {
        $filas = $this->db->query("SELECT * FROM profesionales WHERE ficha = 'publicada' AND activo = 1 AND slug IS NOT NULL ORDER BY nombre_comercial")->fetchAll();
        return array_map(fn ($f) => $this->completar($f), $filas);
    }

    /**
     * Empresas que trabajan en el municipio $m, ordenadas para mostrar.
     * @return list<array> cada una con 'distancia' (km) y 'destacada' (bool)
     */
    public function para(array $m, string $hoy, ?string $servicio = null, ?array $publicadas = null): array
    {
        $lista = [];
        foreach ($publicadas ?? $this->publicadas() as $e) {
            if ($e['base'] === null || ($servicio !== null && !in_array($servicio, $e['servicios'], true))) {
                continue;
            }
            $distancia = $e['base']['ine'] === $m['ine'] ? 0.0 : Municipios::distanciaKm($m, $e['base']);
            if ($distancia > min((int) $e['radio_km'], max(self::RADIOS))) {
                continue;
            }
            $e['distancia'] = round($distancia);
            $e['destacada'] = self::destacada($e, $hoy);
            $lista[] = $e;
        }
        usort($lista, fn ($a, $b) => [$a['destacada'] ? 0 : 1, -$a['prioridad'], $a['distancia'], $a['nombre_comercial']]
            <=> [$b['destacada'] ? 0 : 1, -$b['prioridad'], $b['distancia'], $b['nombre_comercial']]);
        return $lista;
    }

    /**
     * Municipios donde tienen su base las empresas publicadas, por provincia (para el índice del directorio).
     * @return array<string, list<array{m: array, n: int}>>
     */
    public function bases(): array
    {
        $cuenta = [];
        foreach ($this->publicadas() as $e) {
            if ($e['base'] !== null) {
                $cuenta[$e['base']['ine']] = ($cuenta[$e['base']['ine']] ?? 0) + 1;
            }
        }
        $porProvincia = [];
        foreach ($cuenta as $ine => $n) {
            $m = $this->municipios->obtener((string) $ine);
            $porProvincia[$m['provincia']][] = ['m' => $m, 'n' => $n];
        }
        foreach ($porProvincia as &$lista) {
            usort($lista, fn ($a, $b) => strcoll($a['m']['nombre'], $b['m']['nombre']));
        }
        ksort($porProvincia);
        return $porProvincia;
    }

    public static function destacada(array $e, string $hoy): bool
    {
        return !empty($e['destacada_hasta']) && $e['destacada_hasta'] >= $hoy;
    }

    public static function indexable(array $e): bool
    {
        return $e['ficha'] === 'publicada' && mb_strlen(trim((string) $e['descripcion'])) >= self::MIN_DESCRIPCION_INDEXABLE;
    }

    /**
     * Valida los datos públicos de una ficha (alta, edición por la empresa y panel).
     * @return array{0: array, 1: array<string,string>, 2: list<array>} datos, errores y municipios sugeridos
     */
    public function validarFicha(array $in, bool $emailObligatorio): array
    {
        // Solo texto: un parámetro enviado como lista (campo[]=…) cuenta como vacío.
        $marcados = is_array($in['servicios'] ?? null) ? array_filter($in['servicios'], 'is_string') : [];
        $in = array_map(fn ($v) => is_array($v) ? '' : $v, $in);
        $servicios = array_values(array_intersect(array_keys($this->servicios), $marcados));
        $web = trim((string) ($in['web'] ?? ''));
        if ($web !== '' && !preg_match('#^https?://#i', $web)) {
            $web = 'https://' . $web;
        }
        $descripcion = trim((string) preg_replace('/[ \t]+/', ' ', strip_tags((string) ($in['descripcion'] ?? ''))));
        $datos = [
            'nombre_comercial' => self::linea((string) ($in['nombre_comercial'] ?? '')),
            'razon_social' => self::linea((string) ($in['razon_social'] ?? '')),
            'email' => trim((string) ($in['email'] ?? '')),
            'telefono' => preg_replace('/[^\d+]/', '', (string) ($in['telefono'] ?? '')),
            'web' => $web,
            'descripcion' => (string) preg_replace("/\n{3,}/", "\n\n", str_replace("\r", '', $descripcion)),
            'servicios' => $servicios,
            'radio_km' => in_array((int) ($in['radio_km'] ?? 25), self::RADIOS, true) ? (int) ($in['radio_km'] ?? 25) : 25,
            'municipio_ine' => '',
            'municipio_texto' => trim((string) ($in['municipio'] ?? '')),
            'provincia' => sprintf('%02d', (int) ($in['provincia'] ?? 0)),
        ];
        $errores = [];
        $sugerencias = [];
        if ($datos['nombre_comercial'] === '' || mb_strlen($datos['nombre_comercial']) > 80) {
            $errores['nombre_comercial'] = 'Indica el nombre de la empresa (máximo 80 caracteres).';
        }
        if (mb_strlen($datos['razon_social']) > 120) {
            $errores['razon_social'] = 'La razón social es demasiado larga.';
        }
        foreach (['nombre_comercial', 'razon_social'] as $campo) {
            if (preg_match('#https?:|www\.|@#i', $datos[$campo])) {
                $errores[$campo] = 'Sin enlaces ni emails en el nombre.';
            }
        }
        if (($emailObligatorio || $datos['email'] !== '') && !filter_var($datos['email'], FILTER_VALIDATE_EMAIL)) {
            $errores['email'] = 'Escribe un email válido: ahí te llegarán los clientes y el enlace para editar la ficha.';
        }
        if ($datos['telefono'] !== '' && !preg_match('/^\+?\d{9,15}$/', $datos['telefono'])) {
            $errores['telefono'] = 'El teléfono no parece válido.';
        }
        if ($web !== '' && (!filter_var($web, FILTER_VALIDATE_URL) || mb_strlen($web) > 200)) {
            $errores['web'] = 'La web no parece válida.';
        }
        if (mb_strlen($datos['descripcion']) > 1200) {
            $errores['descripcion'] = 'La descripción puede tener como máximo 1.200 caracteres.';
        } elseif (preg_match('#https?://|www\.#i', $datos['descripcion'])) {
            $errores['descripcion'] = 'No incluyas enlaces en la descripción: pon tu web en su campo.';
        }
        if (!$servicios) {
            $errores['servicios'] = 'Marca al menos un servicio.';
        }
        $m = null;
        if (preg_match('/^\d{5}$/', (string) ($in['municipio_ine'] ?? ''))) {
            $m = $this->municipios->obtener((string) $in['municipio_ine']);
        }
        if ($m === null && $datos['municipio_texto'] !== '') {
            $sugerencias = $this->municipios->buscar($datos['municipio_texto'], $datos['provincia'] !== '00' ? $datos['provincia'] : null, 6);
            $exacta = array_values(array_filter($sugerencias, fn ($s) => Municipios::normalizar($s['nombre']) === Municipios::normalizar($datos['municipio_texto'])
                || in_array(Municipios::normalizar($datos['municipio_texto']), array_map([Municipios::class, 'normalizar'], explode('/', $s['nombre'])), true)));
            $m = count($exacta) === 1 ? $exacta[0] : (count($sugerencias) === 1 ? $sugerencias[0] : null);
        }
        if ($m === null) {
            $errores['municipio'] = $sugerencias ? 'Elige tu municipio en la lista.' : 'No encontramos ese municipio. Revisa el nombre y la provincia.';
        } else {
            $datos['municipio_ine'] = $m['ine'];
            $datos['municipio_texto'] = $m['nombre'];
            $datos['provincia'] = $m['provincia'];
            $sugerencias = [];
        }
        return [$datos, $errores, $sugerencias];
    }

    /** Alta pública: queda pendiente de revisión. */
    public function alta(array $datos): int
    {
        $st = $this->db->prepare("INSERT INTO profesionales (nombre_comercial, razon_social, email, whatsapp, precio_lead, notas, activo, creado,
                slug, municipio_ine, servicios, descripcion, web, telefono, radio_km, origen, ficha, verificada, actualizado)
            VALUES (:nombre_comercial, :razon_social, :email, '', 0, '', 1, :ahora,
                :slug, :municipio_ine, :servicios, :descripcion, :web, :telefono, :radio_km, 'empresa', 'pendiente', 0, :ahora)");
        $st->execute($this->columnas($datos) + ['email' => $datos['email'], 'slug' => $this->slugUnico($datos), 'ahora' => Db::ahora()]);
        return (int) $this->db->lastInsertId();
    }

    /** Edición de la ficha por la propia empresa (o desde el panel). */
    public function actualizarFicha(int $id, array $datos): void
    {
        $st = $this->db->prepare('UPDATE profesionales SET nombre_comercial = :nombre_comercial, razon_social = :razon_social,
            municipio_ine = :municipio_ine, servicios = :servicios, descripcion = :descripcion, web = :web, telefono = :telefono,
            radio_km = :radio_km, actualizado = :ahora WHERE id = :id');
        $st->execute($this->columnas($datos) + ['ahora' => Db::ahora(), 'id' => $id]);
        $actual = $this->porId($id);
        if ($actual !== null && $actual['slug'] === null) {
            $this->db->prepare('UPDATE profesionales SET slug = ? WHERE id = ?')->execute([$this->slugUnico($datos, $id), $id]);
        }
    }

    /** Campos que solo cambia el titular desde el panel. */
    public function guardarAdmin(int $id, array $admin): void
    {
        $st = $this->db->prepare('UPDATE profesionales SET origen = :origen, ficha = :ficha, destacada_hasta = :destacada_hasta,
            prioridad = :prioridad, verificada = :verificada, actualizado = :ahora WHERE id = :id');
        $st->execute($admin + ['ahora' => Db::ahora(), 'id' => $id]);
        if ($admin['ficha'] !== 'oculta' && $this->porId($id)['slug'] === null) {
            throw new \RuntimeException('Una ficha publicada necesita municipio y servicios.');
        }
    }

    public function cambiarFicha(int $id, string $ficha): void
    {
        if (!isset(self::FICHAS[$ficha])) {
            throw new \InvalidArgumentException('Estado de ficha no válido');
        }
        $this->db->prepare('UPDATE profesionales SET ficha = ?, actualizado = ? WHERE id = ?')->execute([$ficha, Db::ahora(), $id]);
    }

    public function marcarVerificada(int $id): void
    {
        $this->db->prepare('UPDATE profesionales SET verificada = 1, actualizado = ? WHERE id = ?')->execute([Db::ahora(), $id]);
    }

    /** Nuevo email: hasta que lo confirme (guardando con su enlace), la ficha deja de estar verificada. */
    public function asignarEmail(int $id, string $email): void
    {
        $this->db->prepare('UPDATE profesionales SET email = ?, verificada = 0, actualizado = ? WHERE id = ?')->execute([$email, Db::ahora(), $id]);
    }

    /** Huella del email para los enlaces de edición: si el email cambia, los enlaces anteriores dejan de valer. */
    public static function huellaEmail(string $email): string
    {
        return substr(hash('sha256', mb_strtolower(trim($email))), 0, 16);
    }

    /**
     * Importa data/empresas.csv (fichas añadidas por nosotros). Solo crea las que no existen (por slug):
     * nunca pisa lo que la empresa o el panel hayan cambiado. Se ejecuta solo cuando el archivo cambia.
     * Columnas: slug;nombre;razon_social;municipio_ine;servicios;descripcion;web;telefono;email;radio_km
     */
    public function importar(string $archivo): int
    {
        if (!is_file($archivo)) {
            return 0;
        }
        $huella = sha1_file($archivo);
        $st = $this->db->prepare("SELECT valor FROM ajustes WHERE clave = 'empresas_csv'");
        $st->execute();
        if ($st->fetchColumn() === $huella) {
            return 0;
        }
        $f = fopen($archivo, 'r');
        $cab = array_map(fn ($c) => trim((string) $c, "\xEF\xBB\xBF \t"), fgetcsv($f, 0, ';', '"', '') ?: []);
        $faltan = array_diff(['slug', 'nombre', 'municipio_ine', 'servicios'], $cab);
        if ($faltan) {
            fclose($f);
            throw new \RuntimeException('Faltan columnas en empresas.csv: ' . implode(', ', $faltan));
        }
        $nuevas = 0;
        $existe = $this->db->prepare('SELECT 1 FROM profesionales WHERE slug = ?');
        $insertar = $this->db->prepare("INSERT INTO profesionales (nombre_comercial, razon_social, email, whatsapp, precio_lead, notas, activo, creado,
                slug, municipio_ine, servicios, descripcion, web, telefono, radio_km, origen, ficha, verificada, actualizado)
            VALUES (:nombre, :razon_social, :email, '', 0, '', 1, :ahora, :slug, :municipio_ine, :servicios, :descripcion, :web, :telefono,
                :radio_km, 'auto', 'publicada', 0, :ahora)");
        // Si algo falla, se deshace todo: nunca debe quedar una transacción abierta que se lleve por delante
        // lo que guarde después la misma petición (un lead, un alta).
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            while (($fila = fgetcsv($f, 0, ';', '"', '')) !== false) {
                if (count($fila) !== count($cab)) {
                    continue;
                }
                $e = array_combine($cab, array_map('trim', $fila));
                if (!preg_match('/^[a-z0-9-]{1,90}$/', $e['slug']) || $this->municipios->obtener($e['municipio_ine']) === null) {
                    continue;
                }
                $existe->execute([$e['slug']]);
                if ($existe->fetchColumn()) {
                    continue;
                }
                $insertar->execute([
                    'nombre' => self::linea($e['nombre']), 'razon_social' => self::linea($e['razon_social'] ?? ''),
                    'email' => filter_var($e['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: '', 'ahora' => Db::ahora(),
                    'slug' => $e['slug'], 'municipio_ine' => $e['municipio_ine'],
                    'servicios' => json_encode(array_values(array_intersect(array_keys($this->servicios), explode(',', $e['servicios'])))),
                    'descripcion' => strip_tags($e['descripcion'] ?? ''),
                    'web' => preg_match('#^https?://[^\s"<>]+$#i', $e['web'] ?? '') ? $e['web'] : '',
                    'telefono' => (string) preg_replace('/[^\d+]/', '', $e['telefono'] ?? ''),
                    'radio_km' => in_array((int) ($e['radio_km'] ?? 25), self::RADIOS, true) ? (int) $e['radio_km'] : 25,
                ]);
                $nuevas++;
            }
            $this->db->prepare("INSERT INTO ajustes (clave, valor) VALUES ('empresas_csv', ?) ON CONFLICT (clave) DO UPDATE SET valor = excluded.valor")
                ->execute([$huella]);
            $this->db->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        } finally {
            fclose($f);
        }
        return $nuevas;
    }

    /** Texto de una línea, sin etiquetas, saltos de línea ni caracteres de control. */
    private static function linea(string $texto): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags($texto)));
    }

    /** Añade a la fila los datos derivados: servicios como lista y municipio base. */
    public function completar(array $fila): array
    {
        $fila['servicios'] = json_decode((string) ($fila['servicios'] ?? '[]'), true) ?: [];
        $fila['base'] = ($fila['municipio_ine'] ?? '') !== '' ? $this->municipios->obtener((string) $fila['municipio_ine']) : null;
        $fila['prioridad'] = (int) ($fila['prioridad'] ?? 0);
        $fila['verificada'] = (int) ($fila['verificada'] ?? 0);
        return $fila;
    }

    private function columnas(array $datos): array
    {
        return [
            'nombre_comercial' => $datos['nombre_comercial'],
            'razon_social' => $datos['razon_social'],
            'municipio_ine' => $datos['municipio_ine'],
            'servicios' => json_encode(array_values($datos['servicios'])),
            'descripcion' => $datos['descripcion'],
            'web' => $datos['web'],
            'telefono' => $datos['telefono'],
            'radio_km' => $datos['radio_km'],
        ];
    }

    /** "Aguas Pepe" en Sabadell → aguas-pepe-sabadell (y -2, -3… si ya existe). */
    private function slugUnico(array $datos, ?int $excepto = null): string
    {
        $m = $this->municipios->obtener($datos['municipio_ine']);
        $nombre = Municipios::normalizar($datos['nombre_comercial']);
        $lugar = Municipios::normalizar(explode('/', (string) ($m['nombre'] ?? ''))[0]);
        $base = str_contains($nombre, $lugar) ? $nombre : trim($nombre . ' ' . $lugar);
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', $base), '-') ?: 'empresa';
        $base = substr($base, 0, 70);
        $st = $this->db->prepare('SELECT 1 FROM profesionales WHERE slug = ? AND id IS NOT ?');
        for ($i = 1; ; $i++) {
            $slug = $i === 1 ? $base : $base . '-' . $i;
            $st->execute([$slug, $excepto]);
            if (!$st->fetchColumn()) {
                return $slug;
            }
        }
    }
}
