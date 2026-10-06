<?php
declare(strict_types=1);

namespace AguaSinCal;

use PDO;

/**
 * Tareas diarias sin necesidad de cron:
 *  1. Copia de seguridad de la base de datos en datos/backups (se conservan 14 días).
 *  2. Anonimización de leads antiguos (24 meses; lista de espera 12 meses).
 *  3. Purga de los registros de límite de intentos.
 *
 * siToca() se llama al final de cada petición PHP y solo trabaja una vez cada 24 h.
 * scripts/cron-diario.php sigue disponible por si se prefiere un cron del hosting.
 */
final class Mantenimiento
{
    public const INTERVALO = 86400;
    public const DIAS_COPIAS = 14;

    public function __construct(private readonly App $app)
    {
    }

    /** Ejecuta las tareas si han pasado 24 h desde la última vez. Nunca lanza excepciones. */
    public function siToca(int $ahora): ?array
    {
        $marca = $this->app->datos . '/mantenimiento.marca';
        if (is_file($marca) && filemtime($marca) > $ahora - self::INTERVALO) {
            return null;
        }
        if (!is_dir($this->app->datos)) {
            return null;
        }
        $f = @fopen($marca, 'c+');
        if ($f === false) {
            return null;
        }
        try {
            if (!flock($f, LOCK_EX | LOCK_NB)) {
                return null; // otra petición lo está haciendo ahora
            }
            $ultima = (int) stream_get_contents($f);
            if ($ultima > $ahora - self::INTERVALO) {
                return null;
            }
            $resultado = $this->ejecutar($ahora);
            ftruncate($f, 0);
            rewind($f);
            fwrite($f, (string) $ahora);
            fflush($f);
            touch($marca, $ahora);
            return $resultado;
        } catch (\Throwable $e) {
            error_log('[mantenimiento] ' . $e->getMessage());
            return null;
        } finally {
            flock($f, LOCK_UN);
            fclose($f);
        }
    }

    /** @return array{copia: string, anonimizados: int, intentos_purgados: int} */
    public function ejecutar(int $ahora): array
    {
        $db = $this->app->db();
        $copia = '';
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $dir = $this->app->datos . '/backups';
            if (!is_dir($dir)) {
                mkdir($dir, 0700, true);
            }
            $copia = $dir . '/aguasincal-' . gmdate('Y-m-d', $ahora) . '.sqlite';
            if (!is_file($copia)) {
                $db->exec('VACUUM INTO ' . $db->quote($copia));
                @chmod($copia, 0600);
            }
            // La fecha va en el nombre del archivo (no se usa la fecha de modificación, que cambia al restaurar o copiar).
            foreach (glob($dir . '/aguasincal-*.sqlite') ?: [] as $f) {
                $fecha = strtotime(substr(basename($f, '.sqlite'), strlen('aguasincal-')) . ' 00:00:00 UTC');
                if ($fecha !== false && $fecha < $ahora - self::DIAS_COPIAS * 86400) {
                    unlink($f);
                }
            }
        }
        return [
            'copia' => basename($copia),
            'anonimizados' => $this->app->leads()->anonimizarAntiguos($ahora),
            'intentos_purgados' => $this->app->limitador()->purgar($ahora - 86400),
        ];
    }
}
