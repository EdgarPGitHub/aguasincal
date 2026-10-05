<?php
declare(strict_types=1);

namespace AguaSinCal;

use PDO;

/**
 * Quién atiende cada provincia × servicio. Sin fila = lista de espera.
 * Un modo "activo" sin profesional activo se trata como espera (nunca se cede a nadie por error).
 */
final class Cobertura
{
    public const MODOS = ['activo', 'espera'];

    public function __construct(private readonly PDO $db, private readonly array $servicios)
    {
    }

    /** @return array{modo: string, profesional: ?array} */
    public function resolver(string $provincia, string $servicio): array
    {
        $st = $this->db->prepare('SELECT c.modo, p.* FROM cobertura c
            LEFT JOIN profesionales p ON p.id = c.profesional_id AND p.activo = 1
            WHERE c.provincia = ? AND c.servicio = ?');
        $st->execute([$provincia, $servicio]);
        $fila = $st->fetch();
        if (!$fila || $fila['modo'] !== 'activo' || empty($fila['id'])) {
            return ['modo' => 'espera', 'profesional' => null];
        }
        $modo = $fila['modo'];
        unset($fila['modo']);
        return ['modo' => $modo, 'profesional' => $fila];
    }

    public function guardar(string $provincia, string $servicio, string $modo, ?int $profesionalId): void
    {
        if (!in_array($modo, self::MODOS, true) || !isset($this->servicios[$servicio])) {
            throw new \InvalidArgumentException('Modo o servicio no válido');
        }
        if ($modo === 'activo' && $profesionalId === null) {
            throw new \InvalidArgumentException('Una zona activa necesita un profesional');
        }
        $st = $this->db->prepare('INSERT INTO cobertura (provincia, servicio, modo, profesional_id, actualizado)
            VALUES (?, ?, ?, ?, ?)
            ON CONFLICT (provincia, servicio) DO UPDATE SET modo = excluded.modo,
                profesional_id = excluded.profesional_id, actualizado = excluded.actualizado');
        $st->execute([$provincia, $servicio, $modo, $profesionalId, Db::ahora()]);
    }

    /** @return array<string, array<string, array>> [provincia][servicio] => fila */
    public function mapa(): array
    {
        $mapa = [];
        foreach ($this->db->query('SELECT * FROM cobertura') as $fila) {
            $mapa[$fila['provincia']][$fila['servicio']] = $fila;
        }
        return $mapa;
    }
}
