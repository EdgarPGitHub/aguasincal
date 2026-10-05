<?php
declare(strict_types=1);

namespace AguaSinCal;

/**
 * Tokens firmados (HMAC-SHA256) para los enlaces de acción de los emails.
 * Formato: base64url(json) . "." . base64url(hmac). Incluyen caducidad ("exp").
 */
final class Firma
{
    public function __construct(private readonly string $clave)
    {
    }

    public function firmar(array $datos, int $caduca): string
    {
        $datos['exp'] = $caduca;
        $cuerpo = self::b64(json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return $cuerpo . '.' . self::b64($this->hmac($cuerpo));
    }

    /** Devuelve los datos si la firma es válida y no ha caducado; null en caso contrario. */
    public function verificar(string $token, int $ahora): ?array
    {
        $partes = explode('.', $token);
        if (count($partes) !== 2) {
            return null;
        }
        [$cuerpo, $firma] = $partes;
        if (!hash_equals(self::b64($this->hmac($cuerpo)), $firma)) {
            return null;
        }
        $datos = json_decode(self::desb64($cuerpo), true);
        if (!is_array($datos) || !isset($datos['exp']) || (int) $datos['exp'] < $ahora) {
            return null;
        }
        return $datos;
    }

    private function hmac(string $texto): string
    {
        return hash_hmac('sha256', 'accion|' . $texto, $this->clave, true);
    }

    private static function b64(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private static function desb64(string $txt): string
    {
        return (string) base64_decode(strtr($txt, '-_', '+/'), true);
    }
}
