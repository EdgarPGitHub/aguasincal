<?php
declare(strict_types=1);

namespace AguaSinCal;

/**
 * Token sin estado (sin cookies) para el formulario público: protege contra envíos
 * desde otras webs (CSRF) y contra bots que envían demasiado rápido o reutilizan formularios viejos.
 */
final class TokenFormulario
{
    public const MIN_SEGUNDOS = 3;
    public const MAX_SEGUNDOS = 7200;

    public function __construct(private readonly string $clave)
    {
    }

    public function emitir(int $ahora): string
    {
        return $ahora . '.' . $this->hmac((string) $ahora);
    }

    /** @return 'ok'|'invalido'|'rapido'|'caducado' */
    public function validar(string $token, int $ahora): string
    {
        if (!preg_match('/^(\d{10})\.([a-f0-9]{64})$/', $token, $m)) {
            return 'invalido';
        }
        if (!hash_equals($this->hmac($m[1]), $m[2])) {
            return 'invalido';
        }
        $edad = $ahora - (int) $m[1];
        if ($edad < self::MIN_SEGUNDOS) {
            return 'rapido';
        }
        if ($edad > self::MAX_SEGUNDOS) {
            return 'caducado';
        }
        return 'ok';
    }

    private function hmac(string $texto): string
    {
        return hash_hmac('sha256', 'formulario|' . $texto, $this->clave);
    }
}
