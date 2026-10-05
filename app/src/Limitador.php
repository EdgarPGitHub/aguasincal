<?php
declare(strict_types=1);

namespace AguaSinCal;

use PDO;

/** Límite de intentos por clave (p. ej. "login:ip:<hash>") en una ventana de tiempo. */
final class Limitador
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function superado(string $clave, int $maximo, int $ventanaSegundos, int $ahora): bool
    {
        $st = $this->db->prepare('SELECT COUNT(*) FROM intentos WHERE clave = ? AND momento > ?');
        $st->execute([$clave, $ahora - $ventanaSegundos]);
        return (int) $st->fetchColumn() >= $maximo;
    }

    public function registrar(string $clave, int $ahora): void
    {
        $this->db->prepare('INSERT INTO intentos (clave, momento) VALUES (?, ?)')->execute([$clave, $ahora]);
    }

    public function limpiar(string $clave): void
    {
        $this->db->prepare('DELETE FROM intentos WHERE clave = ?')->execute([$clave]);
    }

    public function purgar(int $antesDe): int
    {
        $st = $this->db->prepare('DELETE FROM intentos WHERE momento < ?');
        $st->execute([$antesDe]);
        return $st->rowCount();
    }
}
