<?php
declare(strict_types=1);

namespace AguaSinCal;

/**
 * Lógica de recomendación a partir de la dureza (grados franceses, °fH).
 * 1 °fH = 10 mg/L de CaCO3. La escala es la orientativa que explica /metodologia-datos/.
 * La misma lógica está en assets/js/app.js (calculadora); si cambias algo aquí, cámbialo también allí.
 */
final class Agua
{
    /** Límites superiores de cada categoría (°fH). */
    public const ESCALA = [
        ['clave' => 'blanda', 'nombre' => 'Blanda', 'hasta' => 15],
        ['clave' => 'media', 'nombre' => 'Moderadamente dura', 'hasta' => 30],
        ['clave' => 'dura', 'nombre' => 'Dura', 'hasta' => 40],
        ['clave' => 'muy-dura', 'nombre' => 'Muy dura', 'hasta' => null],
    ];

    /** Escala del medidor visual (0–60 °fH). */
    public const MAX_MEDIDOR = 60;

    // Supuestos del dimensionado (documentados en la metodología).
    public const LITROS_PERSONA_DIA = 140;
    public const DUREZA_RESIDUAL = 8.0;
    public const CAPACIDAD_RESINA = 5.0;    // °fH·m³ por litro de resina
    public const SAL_KG_POR_LITRO = 0.15;  // kg de sal por litro de resina y regeneración
    public const DIAS_ENTRE_REGENERACIONES = 7;

    /** @return array{clave: string, nombre: string, posicion: float} */
    public static function categoria(?float $fh): array
    {
        if ($fh === null) {
            return ['clave' => 'sin-datos', 'nombre' => 'Sin datos', 'posicion' => 0.0];
        }
        foreach (self::ESCALA as $tramo) {
            if ($tramo['hasta'] === null || $fh < $tramo['hasta']) {
                return [
                    'clave' => $tramo['clave'],
                    'nombre' => $tramo['nombre'],
                    'posicion' => round(min(100, max(0, $fh / self::MAX_MEDIDOR * 100)), 1),
                ];
            }
        }
        throw new \LogicException('Escala incompleta');
    }

    /**
     * Recomendación de equipos según dureza.
     * @return array{descalcificador: string, osmosis: string, resumen: string}
     */
    public static function recomendacion(?float $fh): array
    {
        $cat = self::categoria($fh)['clave'];
        return match ($cat) {
            'blanda' => [
                'descalcificador' => 'no-necesario',
                'osmosis' => 'opcional',
                'resumen' => 'El agua es blanda: no necesitas descalcificador. Si no te gusta el sabor, un filtro de carbón o una ósmosis para beber es suficiente.',
            ],
            'media' => [
                'descalcificador' => 'recomendable',
                'osmosis' => 'opcional',
                'resumen' => 'Agua moderadamente dura: la cal se nota en grifos, mamparas y termo. Un descalcificador es recomendable si quieres alargar la vida de electrodomésticos y caldera; la ósmosis es opcional para beber.',
            ],
            'dura' => [
                'descalcificador' => 'recomendado',
                'osmosis' => 'recomendado',
                'resumen' => 'Agua dura: un descalcificador protege tuberías, termo, caldera y electrodomésticos. Para beber y cocinar, una ósmosis inversa mejora mucho el sabor.',
            ],
            'muy-dura' => [
                'descalcificador' => 'muy-recomendado',
                'osmosis' => 'recomendado',
                'resumen' => 'Agua muy dura: sin descalcificador la cal se acumula rápido en la instalación y los electrodomésticos. Para beber, la ósmosis inversa es la opción más habitual.',
            ],
            default => [
                'descalcificador' => 'sin-datos',
                'osmosis' => 'sin-datos',
                'resumen' => 'Todavía no tenemos datos de dureza para esta zona. Un técnico puede medirla en tu casa en unos minutos.',
            ],
        };
    }

    /**
     * Dimensionado orientativo de un descalcificador doméstico.
     * @return array{litros_resina: int, sal_kg_anual: int, regeneraciones_anuales: int}|null
     */
    public static function dimensionado(int $personas, ?float $fh): ?array
    {
        if ($fh === null || $personas < 1 || $fh <= self::DUREZA_RESIDUAL) {
            return null;
        }
        $m3Dia = $personas * self::LITROS_PERSONA_DIA / 1000;
        $carga = $m3Dia * self::DIAS_ENTRE_REGENERACIONES * ($fh - self::DUREZA_RESIDUAL);
        $litros = $carga / self::CAPACIDAD_RESINA;
        // Tamaños comerciales habituales.
        $tamanos = [8, 10, 12, 15, 20, 25, 30, 35, 40, 50, 60, 75, 100];
        $elegido = end($tamanos);
        foreach ($tamanos as $t) {
            if ($t >= $litros) {
                $elegido = $t;
                break;
            }
        }
        $cargaAnual = $m3Dia * 365 * ($fh - self::DUREZA_RESIDUAL);
        $regeneraciones = (int) ceil($cargaAnual / ($elegido * self::CAPACIDAD_RESINA));
        return [
            'litros_resina' => $elegido,
            'sal_kg_anual' => (int) (round($regeneraciones * $elegido * self::SAL_KG_POR_LITRO / 5) * 5),
            'regeneraciones_anuales' => $regeneraciones,
        ];
    }
}
