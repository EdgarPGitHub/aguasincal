<?php
declare(strict_types=1);

namespace AguaSinCal\Tests;

use AguaSinCal\App;
use AguaSinCal\Build\Generador;
use PHPUnit\Framework\TestCase;

/** Genera la web con los datos ficticios de tests/fixtures y comprueba el resultado. */
final class GeneradorTest extends TestCase
{
    private static string $salida;
    private static \AguaSinCal\Build\Informe $informe;

    public static function setUpBeforeClass(): void
    {
        $raiz = dirname(__DIR__);
        self::$salida = sys_get_temp_dir() . '/aguasincal-dist-' . bin2hex(random_bytes(4));
        $app = new App($raiz, sys_get_temp_dir(), ['entorno' => 'build']);
        self::$informe = (new Generador($app, [
            'salida' => self::$salida, 'app' => '../aguasincal-app', 'panel' => 'gestion-prueba',
            'protegido' => false, 'htpasswd' => '', 'hoy' => '2026-10-05', 'datos' => $raiz . '/tests/fixtures/data',
        ]))->generar();
    }

    public function testSinErrores(): void
    {
        $this->assertSame([], self::$informe->errores);
    }

    public function testSoloMunicipiosGrandesYConMuestraRecienteTienenPagina(): void
    {
        $this->assertFileExists(self::$salida . '/dureza-agua/barcelona/vilaprova-de-mar/index.html');
        $this->assertFileExists(self::$salida . '/dureza-agua/barcelona/barcelona/index.html');
        $this->assertFileDoesNotExist(self::$salida . '/dureza-agua/barcelona/poblet-de-test/index.html', 'Menos de 10.000 habitantes');
        $this->assertFileDoesNotExist(self::$salida . '/dureza-agua/barcelona/riera-ficticia/index.html', 'Muestra de hace más de 24 meses');
        $provincia = (string) file_get_contents(self::$salida . '/dureza-agua/barcelona/index.html');
        $this->assertStringContainsString('Poblet de Test', $provincia, 'Aparece en la tabla de la provincia');
    }

    public function testBorradoresSonNoindexYNoEstanEnElSitemap(): void
    {
        $guia = (string) file_get_contents(self::$salida . '/guias/descalcificador-no-gasta-sal/index.html');
        $this->assertStringContainsString('<meta name="robots" content="noindex, follow">', $guia);
        $sitemaps = implode('', array_map('file_get_contents', glob(self::$salida . '/sitemap-*.xml')));
        $this->assertStringNotContainsString('/guias/descalcificador-no-gasta-sal/', $sitemaps);
        $this->assertStringContainsString('/dureza-agua/barcelona/vilaprova-de-mar/', $sitemaps);
    }

    public function testPaginaDeMunicipio(): void
    {
        $html = (string) file_get_contents(self::$salida . '/dureza-agua/barcelona/vilaprova-de-mar/index.html');
        $this->assertStringContainsString('<link rel="canonical" href="https://aguasincal.es/dureza-agua/barcelona/vilaprova-de-mar/">', $html);
        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertStringContainsString('Según la zona, va de 36 a 43 °fH', $html);
        $this->assertStringContainsString('BreadcrumbList', $html);
    }

    public function testArchivosDeServidor(): void
    {
        $this->assertFileExists(self::$salida . '/gestion-prueba/index.php');
        $this->assertFileExists(self::$salida . '/presupuesto/index.php');
        $this->assertStringContainsString("'/../aguasincal-app/app/bootstrap.php'", (string) file_get_contents(self::$salida . '/_app.php'));
        $htaccess = (string) file_get_contents(self::$salida . '/.htaccess');
        $this->assertStringContainsString('RewriteRule (^|/)_ - [F]', $htaccess);
        $this->assertStringContainsString('Content-Security-Policy', $htaccess);
        $this->assertStringNotContainsString('AuthType Basic', $htaccess);
        $indice = json_decode((string) file_get_contents(self::$salida . '/datos/municipios.json'), true);
        $this->assertContains('Vilaprova de Mar', array_column($indice, 'n'));
        $this->assertStringContainsString('Disallow: /presupuesto/', (string) file_get_contents(self::$salida . '/robots.txt'));
    }
}
