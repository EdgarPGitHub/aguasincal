<?php
declare(strict_types=1);

namespace AguaSinCal;

use PDO;

final class Db
{
    public static function conectar(string $dsn, ?string $usuario = null, ?string $clave = null): PDO
    {
        $pdo = new PDO($dsn, $usuario, $clave, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        if (str_starts_with($dsn, 'sqlite:')) {
            $pdo->exec('PRAGMA busy_timeout = 5000');
            $pdo->exec('PRAGMA foreign_keys = ON');
            if ($dsn !== 'sqlite::memory:') {
                $pdo->exec('PRAGMA journal_mode = WAL');
                $pdo->exec('PRAGMA synchronous = NORMAL');
            }
        }
        return $pdo;
    }

    /**
     * Aplica en orden los .sql de $dir que aún no constan en la tabla migraciones.
     *
     * @return list<string> migraciones aplicadas ahora
     */
    public static function migrar(PDO $pdo, string $dir): array
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS migraciones (version TEXT PRIMARY KEY, aplicada TEXT NOT NULL)');
        $hechas = $pdo->query('SELECT version FROM migraciones')->fetchAll(PDO::FETCH_COLUMN);
        $ficheros = glob(rtrim($dir, '/') . '/*.sql') ?: [];
        sort($ficheros);
        $aplicadas = [];
        foreach ($ficheros as $fichero) {
            $version = basename($fichero, '.sql');
            if (in_array($version, $hechas, true)) {
                continue;
            }
            $pdo->beginTransaction();
            try {
                $pdo->exec((string) file_get_contents($fichero));
                $st = $pdo->prepare('INSERT INTO migraciones (version, aplicada) VALUES (?, ?)');
                $st->execute([$version, gmdate('c')]);
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            $aplicadas[] = $version;
        }
        return $aplicadas;
    }

    public static function ahora(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
