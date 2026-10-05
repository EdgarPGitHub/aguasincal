<?php
declare(strict_types=1);

namespace AguaSinCal;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Entorno Twig compartido por el generador estático y las páginas PHP
 * (formulario y panel), para que todo use el mismo diseño. Escapado HTML automático.
 */
final class Vista
{
    public static function crear(App $app, ?string $cache = null, array $globales = []): Environment
    {
        $twig = new Environment(new FilesystemLoader($app->raiz . '/templates'), [
            'autoescape' => 'html',
            'cache' => $cache ?? false,
            'strict_variables' => !$app->esProduccion(),
        ]);

        $site = $app->config('site');
        $version = $globales['version_assets'] ?? self::versionAssets($app);
        $twig->addGlobal('site', $site);
        $twig->addGlobal('servicios', $app->servicios());
        $twig->addGlobal('provincias', $app->config('provincias'));
        $twig->addGlobal('zonas_publicadas', $app->config('zonas-publicadas'));
        $twig->addGlobal('tipos_cliente', Leads::TIPOS_CLIENTE);
        $twig->addGlobal('modalidades', Leads::MODALIDADES);
        $twig->addGlobal('contacto_directo', $site['telefono_enlace'] !== '' || $site['whatsapp'] !== '');
        $twig->addGlobal('anio', (int) gmdate('Y'));
        foreach ($globales as $nombre => $valor) {
            $twig->addGlobal($nombre, $valor);
        }

        $twig->addFunction(new TwigFunction('asset', fn (string $ruta): string => '/assets/' . ltrim($ruta, '/') . '?v=' . $version));
        $twig->addFunction(new TwigFunction('url_abs', fn (string $ruta = '/'): string => rtrim($site['url'], '/') . $ruta));
        $twig->addFunction(new TwigFunction('dureza', [Agua::class, 'categoria']));
        $twig->addFunction(new TwigFunction('whatsapp_url', fn (string $texto = ''): string => $site['whatsapp'] === ''
            ? '' : 'https://wa.me/' . $site['whatsapp'] . ($texto !== '' ? '?text=' . rawurlencode($texto) : '')));
        $twig->addFilter(new TwigFilter('num', fn (float|int|string|null $n, int $dec = 0): string => $n === null || $n === ''
            ? '–' : number_format((float) $n, $dec, ',', '.')));
        $twig->addFilter(new TwigFilter('fecha', [self::class, 'fecha']));
        return $twig;
    }

    /** Fecha legible en español: "5 de octubre de 2026" (o "octubre de 2026" con $sinDia). */
    public static function fecha(?string $iso, bool $sinDia = false): string
    {
        if ($iso === null || $iso === '') {
            return '';
        }
        $t = strtotime($iso);
        if ($t === false) {
            return $iso;
        }
        $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        $mes = $meses[(int) gmdate('n', $t) - 1];
        return $sinDia ? $mes . ' de ' . gmdate('Y', $t) : gmdate('j', $t) . ' de ' . $mes . ' de ' . gmdate('Y', $t);
    }

    /** Versión de los assets: la escribe el generador en _build.json junto a la web publicada. */
    private static function versionAssets(App $app): string
    {
        $publica = defined('AGUASINCAL_PUBLICA') ? (string) constant('AGUASINCAL_PUBLICA') : '';
        foreach ([$publica, $app->raiz . '/dist'] as $dir) {
            if ($dir !== '' && is_file($dir . '/_build.json')) {
                $info = json_decode((string) file_get_contents($dir . '/_build.json'), true);
                return (string) ($info['version'] ?? '1');
            }
        }
        return '1';
    }
}
