<?php
declare(strict_types=1);

namespace AguaSinCal;

final class Http
{
    /** Cabeceras de seguridad para respuestas PHP (las estáticas las pone .htaccess). */
    public static function cabeceras(bool $panel = false): void
    {
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-Frame-Options: DENY');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
        $csp = "default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'";
        header('Content-Security-Policy: ' . $csp);
        header('Cache-Control: no-store');
        if ($panel) {
            header('X-Robots-Tag: noindex, nofollow');
        }
    }

    /** Hash diario de la IP: sirve para limitar abusos sin guardar la IP real. */
    public static function ipHash(string $clave): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        return substr(hash_hmac('sha256', $ip . '|' . gmdate('Y-m-d'), $clave), 0, 32);
    }

    public static function esPost(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }

    public static function redirigir(string $url, int $codigo = 303): never
    {
        header('Location: ' . $url, true, $codigo);
        exit;
    }

    public static function esHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);
    }

    /** Ruta interna segura (evita redirecciones abiertas): solo rutas que empiezan por "/" y sin "//". */
    public static function rutaInterna(string $ruta): string
    {
        $ruta = trim($ruta);
        if ($ruta === '' || $ruta[0] !== '/' || str_starts_with($ruta, '//') || str_contains($ruta, '\\')) {
            return '';
        }
        return mb_substr(strtok($ruta, '?#') ?: '', 0, 300);
    }
}
