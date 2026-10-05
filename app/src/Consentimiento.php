<?php
declare(strict_types=1);

namespace AguaSinCal;

/**
 * Textos de consentimiento del formulario. Cada lead guarda la versión aceptada.
 * Si cambias un texto, sube VERSION para poder demostrar qué aceptó cada persona.
 */
final class Consentimiento
{
    public const VERSION = '2026-10-v1';

    public static function version(string $modo): string
    {
        return self::VERSION . '-' . $modo;
    }

    public static function texto(string $modo, ?array $profesional, string $nombreSitio): string
    {
        if ($modo === 'activo' && $profesional !== null) {
            $empresa = trim((string) ($profesional['razon_social'] ?? '')) ?: (string) $profesional['nombre_comercial'];
            $comercial = (string) $profesional['nombre_comercial'];
            $quien = $empresa === $comercial ? $comercial : $comercial . ' (' . $empresa . ')';
            return sprintf(
                'He leído la política de privacidad y acepto que %s trate mis datos para gestionar mi solicitud y que los comunique a %s, el profesional que me preparará el presupuesto.',
                $nombreSitio,
                $quien,
            );
        }
        return sprintf(
            'He leído la política de privacidad y acepto que %s guarde mis datos para avisarme cuando haya un profesional verificado en mi zona. No se comunicarán a ninguna empresa sin volver a pedirme permiso.',
            $nombreSitio,
        );
    }
}
