<?php
declare(strict_types=1);

namespace AguaSinCal;

use PDO;

final class Profesionales
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function obtener(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM profesionales WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** @return list<array> */
    public function todos(bool $soloActivos = false): array
    {
        $sql = 'SELECT * FROM profesionales' . ($soloActivos ? ' WHERE activo = 1' : '') . ' ORDER BY activo DESC, nombre_comercial';
        return $this->db->query($sql)->fetchAll();
    }

    /** Valida y normaliza los datos del formulario del panel. @return array{0: array, 1: array<string,string>} */
    public static function validar(array $in): array
    {
        $datos = [
            'nombre_comercial' => trim((string) ($in['nombre_comercial'] ?? '')),
            'razon_social' => trim((string) ($in['razon_social'] ?? '')),
            'email' => trim((string) ($in['email'] ?? '')),
            'whatsapp' => preg_replace('/\D+/', '', (string) ($in['whatsapp'] ?? '')),
            'precio_lead' => (float) str_replace(',', '.', (string) ($in['precio_lead'] ?? '0')),
            'notas' => trim((string) ($in['notas'] ?? '')),
            'activo' => !empty($in['activo']) ? 1 : 0,
        ];
        $errores = [];
        if ($datos['nombre_comercial'] === '' || mb_strlen($datos['nombre_comercial']) > 120) {
            $errores['nombre_comercial'] = 'Indica el nombre comercial.';
        }
        if ($datos['email'] !== '' && !filter_var($datos['email'], FILTER_VALIDATE_EMAIL)) {
            $errores['email'] = 'El email no es válido.';
        }
        if ($datos['whatsapp'] !== '' && !preg_match('/^\d{9,15}$/', $datos['whatsapp'])) {
            $errores['whatsapp'] = 'Escribe el número con prefijo de país, por ejemplo 34600000000.';
        }
        if ($datos['precio_lead'] < 0) {
            $errores['precio_lead'] = 'El precio no puede ser negativo.';
        }
        return [$datos, $errores];
    }

    public function guardar(?int $id, array $datos): int
    {
        if ($id === null) {
            $st = $this->db->prepare('INSERT INTO profesionales (nombre_comercial, razon_social, email, whatsapp, precio_lead, notas, activo, creado)
                VALUES (:nombre_comercial, :razon_social, :email, :whatsapp, :precio_lead, :notas, :activo, :creado)');
            $st->execute($datos + ['creado' => Db::ahora()]);
            return (int) $this->db->lastInsertId();
        }
        $st = $this->db->prepare('UPDATE profesionales SET nombre_comercial = :nombre_comercial, razon_social = :razon_social,
            email = :email, whatsapp = :whatsapp, precio_lead = :precio_lead, notas = :notas, activo = :activo WHERE id = :id');
        $st->execute($datos + ['id' => $id]);
        return $id;
    }
}
