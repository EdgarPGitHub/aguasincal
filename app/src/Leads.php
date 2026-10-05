<?php
declare(strict_types=1);

namespace AguaSinCal;

use PDO;

final class Leads
{
    public const TIPOS_CLIENTE = [
        'particular' => 'Particular / vivienda',
        'empresa' => 'Empresa, oficina o local',
        'hosteleria' => 'Restaurante, bar u hotel',
        'comunidad' => 'Comunidad de vecinos / edificio',
    ];

    public const ESTADOS = [
        'nuevo' => 'Nuevo',
        'espera' => 'Lista de espera',
        'enviado' => 'Enviado al profesional',
        'valido' => 'Válido (facturable)',
        'invalido' => 'Inválido',
    ];

    public const MOTIVOS_INVALIDO = [
        'telefono' => 'Teléfono incorrecto o no contesta',
        'duplicado' => 'Duplicado',
        'fuera_zona' => 'Fuera de zona',
        'no_interesado' => 'No pidió presupuesto / broma',
        'profesional' => 'Es una empresa del sector',
        'otro' => 'Otro',
    ];

    public const MODALIDADES = ['compra' => 'Compra', 'alquiler' => 'Alquiler'];

    public function __construct(
        private readonly PDO $db,
        private readonly Provincias $provincias,
        private readonly array $servicios,
    ) {
    }

    /** Las comunidades de vecinos se enrutan como su propio servicio, sea cual sea el equipo pedido. */
    public static function servicioEfectivo(string $servicio, string $tipoCliente): string
    {
        return $tipoCliente === 'comunidad' ? 'comunidades' : $servicio;
    }

    /** Paso 1: servicio, CP y tipo de cliente. @return array{0: array, 1: array<string,string>} */
    public function validarPaso1(array $in): array
    {
        $datos = [
            'servicio' => (string) ($in['servicio'] ?? ''),
            'cp' => preg_replace('/\s+/', '', (string) ($in['cp'] ?? '')),
            'tipo_cliente' => (string) ($in['tipo'] ?? $in['tipo_cliente'] ?? 'particular'),
        ];
        $errores = [];
        if (!isset($this->servicios[$datos['servicio']])) {
            $errores['servicio'] = 'Elige qué necesitas.';
        }
        if (!isset(self::TIPOS_CLIENTE[$datos['tipo_cliente']])) {
            $errores['tipo_cliente'] = 'Elige el tipo de cliente.';
        }
        $provincia = $this->provincias->desdeCp($datos['cp']);
        if ($provincia === null) {
            $errores['cp'] = 'Escribe un código postal español de 5 cifras.';
        }
        $datos['provincia'] = $provincia;
        return [$datos, $errores];
    }

    /** Paso 2: datos de contacto. Incluye la validación del paso 1. @return array{0: array, 1: array<string,string>} */
    public function validarPaso2(array $in): array
    {
        [$datos, $errores] = $this->validarPaso1($in);
        $servicio = $this->servicios[$datos['servicio']] ?? null;

        $datos['nombre'] = self::linea($in['nombre'] ?? '', 80);
        $datos['telefono'] = self::normalizarTelefono((string) ($in['telefono'] ?? ''));
        $datos['email'] = mb_strtolower(trim((string) ($in['email'] ?? '')));
        $datos['municipio'] = self::linea($in['municipio'] ?? '', 80);
        $datos['mensaje'] = mb_substr(trim(str_replace("\r", '', (string) ($in['mensaje'] ?? ''))), 0, 1000);
        $datos['modalidad'] = (string) ($in['modalidad'] ?? '');
        $personas = trim((string) ($in['personas'] ?? ''));
        $datos['personas'] = $personas === '' ? null : (int) $personas;

        if (mb_strlen($datos['nombre']) < 2) {
            $errores['nombre'] = 'Indica tu nombre.';
        }
        if ($datos['telefono'] === null) {
            $errores['telefono'] = 'Escribe un teléfono válido (9 cifras).';
            $datos['telefono'] = trim((string) ($in['telefono'] ?? ''));
        }
        if ($datos['email'] !== '' && !filter_var($datos['email'], FILTER_VALIDATE_EMAIL)) {
            $errores['email'] = 'El email no es válido (puedes dejarlo vacío).';
        }
        if ($datos['modalidad'] !== '' && !in_array($datos['modalidad'], $servicio['modalidades'] ?? [], true)) {
            $datos['modalidad'] = '';
        }
        if ($datos['personas'] !== null && ($datos['personas'] < 1 || $datos['personas'] > 50)) {
            $errores['personas'] = 'Indica un número de personas entre 1 y 50.';
        }
        if (empty($in['acepto'])) {
            $errores['acepto'] = 'Necesitamos tu consentimiento para tramitar la solicitud.';
        }
        return [$datos, $errores];
    }

    /**
     * Guarda el lead. $cobertura viene de Cobertura::resolver() en el momento del envío.
     * $meta: consentimiento_version, pagina_origen, referrer, utm (array), ip_hash, origen.
     */
    public function crear(array $datos, array $cobertura, array $meta): int
    {
        $ahora = Db::ahora();
        $modo = $cobertura['modo'];
        $duplicado = $this->buscarDuplicado($datos['telefono'], $datos['servicio']);
        $notas = $duplicado ? 'Posible duplicado del lead #' . $duplicado . '.' : '';

        $st = $this->db->prepare('INSERT INTO leads (creado, actualizado, origen, servicio, modalidad, tipo_cliente, cp, provincia,
                municipio, personas, mensaje, nombre, telefono, email, modo, profesional_id, estado, notas,
                consentimiento_version, pagina_origen, referrer, utm, ip_hash)
            VALUES (:creado, :actualizado, :origen, :servicio, :modalidad, :tipo_cliente, :cp, :provincia,
                :municipio, :personas, :mensaje, :nombre, :telefono, :email, :modo, :profesional_id, :estado, :notas,
                :consentimiento_version, :pagina_origen, :referrer, :utm, :ip_hash)');
        $st->execute([
            'creado' => $ahora,
            'actualizado' => $ahora,
            'origen' => $meta['origen'] ?? 'web',
            'servicio' => $datos['servicio'],
            'modalidad' => $datos['modalidad'] ?? '',
            'tipo_cliente' => $datos['tipo_cliente'],
            'cp' => $datos['cp'],
            'provincia' => $datos['provincia'],
            'municipio' => $datos['municipio'] ?? '',
            'personas' => $datos['personas'] ?? null,
            'mensaje' => $datos['mensaje'] ?? '',
            'nombre' => $datos['nombre'],
            'telefono' => $datos['telefono'],
            'email' => $datos['email'] ?? '',
            'modo' => $modo,
            'profesional_id' => $cobertura['profesional']['id'] ?? null,
            'estado' => $modo === 'activo' ? 'nuevo' : 'espera',
            'notas' => $notas,
            'consentimiento_version' => $meta['consentimiento_version'] ?? '',
            'pagina_origen' => mb_substr((string) ($meta['pagina_origen'] ?? ''), 0, 300),
            'referrer' => mb_substr((string) ($meta['referrer'] ?? ''), 0, 300),
            'utm' => empty($meta['utm']) ? '' : json_encode($meta['utm'], JSON_UNESCAPED_UNICODE),
            'ip_hash' => $meta['ip_hash'] ?? '',
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function obtener(int $id): ?array
    {
        $st = $this->db->prepare('SELECT l.*, p.nombre_comercial AS profesional_nombre FROM leads l
            LEFT JOIN profesionales p ON p.id = l.profesional_id WHERE l.id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** @return list<array> */
    public function listar(array $filtros, int $pagina = 1, int $porPagina = 50): array
    {
        [$where, $params] = $this->filtrosSql($filtros);
        $offset = max(0, ($pagina - 1) * $porPagina);
        $st = $this->db->prepare('SELECT l.*, p.nombre_comercial AS profesional_nombre FROM leads l
            LEFT JOIN profesionales p ON p.id = l.profesional_id' . $where . ' ORDER BY l.id DESC LIMIT ' . (int) $porPagina . ' OFFSET ' . (int) $offset);
        $st->execute($params);
        return $st->fetchAll();
    }

    public function contar(array $filtros): int
    {
        [$where, $params] = $this->filtrosSql($filtros);
        $st = $this->db->prepare('SELECT COUNT(*) FROM leads l' . $where);
        $st->execute($params);
        return (int) $st->fetchColumn();
    }

    /** Recuento por estado del mes (AAAA-MM). @return array<string,int> */
    public function resumenMes(string $mes): array
    {
        $st = $this->db->prepare('SELECT estado, COUNT(*) AS n FROM leads WHERE substr(creado, 1, 7) = ? GROUP BY estado');
        $st->execute([$mes]);
        $resumen = array_fill_keys(array_keys(self::ESTADOS), 0);
        foreach ($st as $fila) {
            $resumen[$fila['estado']] = (int) $fila['n'];
        }
        return $resumen;
    }

    /** Leads en lista de espera de los últimos $dias días por provincia (señal de demanda). @return array<string,int> */
    public function demandaEnEspera(int $dias, int $ahora): array
    {
        $desde = gmdate('Y-m-d\TH:i:s\Z', $ahora - $dias * 86400);
        $st = $this->db->prepare("SELECT provincia, COUNT(*) AS n FROM leads WHERE modo = 'espera' AND creado >= ? GROUP BY provincia ORDER BY n DESC");
        $st->execute([$desde]);
        $res = [];
        foreach ($st as $fila) {
            $res[$fila['provincia']] = (int) $fila['n'];
        }
        return $res;
    }

    public function cambiarEstado(int $id, string $estado, string $motivo = ''): void
    {
        if (!isset(self::ESTADOS[$estado])) {
            throw new \InvalidArgumentException('Estado no válido');
        }
        if ($estado === 'invalido' && !isset(self::MOTIVOS_INVALIDO[$motivo])) {
            $motivo = 'otro';
        }
        $st = $this->db->prepare('UPDATE leads SET estado = ?, motivo_invalido = ?, actualizado = ? WHERE id = ?');
        $st->execute([$estado, $estado === 'invalido' ? $motivo : '', Db::ahora(), $id]);
    }

    public function marcarEnviado(int $id, int $profesionalId): void
    {
        $st = $this->db->prepare("UPDATE leads SET estado = 'enviado', profesional_id = ?, enviado = ?, actualizado = ? WHERE id = ?");
        $ahora = Db::ahora();
        $st->execute([$profesionalId, $ahora, $ahora, $id]);
    }

    public function guardarNotas(int $id, string $notas): void
    {
        $st = $this->db->prepare('UPDATE leads SET notas = ?, actualizado = ? WHERE id = ?');
        $st->execute([mb_substr($notas, 0, 4000), Db::ahora(), $id]);
    }

    /**
     * Anonimiza leads antiguos (RGPD): 24 meses en general y 12 meses los de lista de espera.
     * Conserva los datos no personales para estadísticas y facturación.
     */
    public function anonimizarAntiguos(int $ahora): int
    {
        $limiteGeneral = gmdate('Y-m-d\TH:i:s\Z', strtotime('-24 months', $ahora));
        $limiteEspera = gmdate('Y-m-d\TH:i:s\Z', strtotime('-12 months', $ahora));
        $st = $this->db->prepare("UPDATE leads SET nombre = '(anonimizado)', telefono = '', email = '', mensaje = '',
                municipio = '', notas = '', ip_hash = '', referrer = '', anonimizado = :ahora
            WHERE anonimizado IS NULL AND (creado < :general OR (modo = 'espera' AND estado = 'espera' AND creado < :espera))");
        $st->execute(['ahora' => Db::ahora(), 'general' => $limiteGeneral, 'espera' => $limiteEspera]);
        return $st->rowCount();
    }

    public function anonimizar(int $id): void
    {
        $st = $this->db->prepare("UPDATE leads SET nombre = '(anonimizado)', telefono = '', email = '', mensaje = '',
            municipio = '', notas = '', ip_hash = '', referrer = '', anonimizado = ? WHERE id = ?");
        $st->execute([Db::ahora(), $id]);
    }

    /** Filas para la exportación mensual de facturación. @return list<array> */
    public function exportar(string $mes, ?int $profesionalId): array
    {
        $sql = "SELECT l.id, l.creado, l.enviado, l.servicio, l.modalidad, l.tipo_cliente, l.cp, l.provincia, l.municipio,
                l.nombre, l.telefono, l.estado, l.motivo_invalido, p.nombre_comercial AS profesional, p.precio_lead
            FROM leads l LEFT JOIN profesionales p ON p.id = l.profesional_id
            WHERE l.estado IN ('enviado', 'valido', 'invalido') AND l.enviado IS NOT NULL AND substr(l.enviado, 1, 7) = ?";
        $params = [$mes];
        if ($profesionalId !== null) {
            $sql .= ' AND l.profesional_id = ?';
            $params[] = $profesionalId;
        }
        $st = $this->db->prepare($sql . ' ORDER BY l.enviado');
        $st->execute($params);
        return $st->fetchAll();
    }

    /** Normaliza teléfonos: españoles a 9 cifras; extranjeros con prefijo a +XXXXXXXX. */
    public static function normalizarTelefono(string $telefono): ?string
    {
        $limpio = preg_replace('/[\s.\-()\/]+/', '', $telefono);
        if (preg_match('/^(?:\+34|0034|34)?([6789]\d{8})$/', $limpio, $m)) {
            return $m[1];
        }
        if (preg_match('/^(?:\+|00)([1-9]\d{7,14})$/', $limpio, $m)) {
            return '+' . $m[1];
        }
        return null;
    }

    private function buscarDuplicado(string $telefono, string $servicio): ?int
    {
        $desde = gmdate('Y-m-d\TH:i:s\Z', time() - 30 * 86400);
        $st = $this->db->prepare('SELECT id FROM leads WHERE telefono = ? AND servicio = ? AND creado >= ? ORDER BY id DESC LIMIT 1');
        $st->execute([$telefono, $servicio, $desde]);
        $id = $st->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** @return array{0: string, 1: array} */
    private function filtrosSql(array $filtros): array
    {
        $cond = [];
        $params = [];
        foreach (['provincia', 'servicio', 'estado', 'modo'] as $campo) {
            if (!empty($filtros[$campo])) {
                $cond[] = "l.$campo = ?";
                $params[] = (string) $filtros[$campo];
            }
        }
        if (!empty($filtros['mes']) && preg_match('/^\d{4}-\d{2}$/', (string) $filtros['mes'])) {
            $cond[] = 'substr(l.creado, 1, 7) = ?';
            $params[] = $filtros['mes'];
        }
        if (!empty($filtros['q'])) {
            $cond[] = '(l.nombre LIKE ? OR l.telefono LIKE ? OR l.email LIKE ? OR l.cp LIKE ?)';
            $q = '%' . $filtros['q'] . '%';
            array_push($params, $q, $q, $q, $q);
        }
        return [$cond ? ' WHERE ' . implode(' AND ', $cond) : '', $params];
    }

    private static function linea(mixed $valor, int $max): string
    {
        $texto = trim(preg_replace('/\s+/u', ' ', (string) $valor));
        return mb_substr($texto, 0, $max);
    }
}
