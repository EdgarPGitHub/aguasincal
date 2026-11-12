<?php
declare(strict_types=1);

namespace AguaSinCal\Tests;

use AguaSinCal\App;
use AguaSinCal\Directorio;
use AguaSinCal\Municipios;
use PHPUnit\Framework\TestCase;

final class DirectorioTest extends TestCase
{
    use Apoyo;

    private function ficha(array $cambios = []): array
    {
        return $cambios + [
            'nombre_comercial' => 'Aguas Prueba',
            'municipio' => 'Badalona',
            'provincia' => '08',
            'servicios' => ['descalcificadores', 'osmosis-inversa'],
            'radio_km' => '25',
            'descripcion' => 'Instalación y mantenimiento de descalcificadores.',
            'telefono' => '600 11 22 33',
            'web' => 'aguasprueba.example',
            'email' => 'empresa@ejemplo.test',
        ];
    }

    private function publicar(App $app, array $cambios = [], array $admin = []): int
    {
        $d = $app->directorio();
        [$datos, $errores] = $d->validarFicha($this->ficha($cambios), true);
        $this->assertSame([], $errores);
        $id = $d->alta($datos);
        $d->guardarAdmin($id, $admin + ['origen' => 'empresa', 'ficha' => 'publicada', 'destacada_hasta' => null, 'prioridad' => 0, 'verificada' => 0]);
        return $id;
    }

    public function testBuscaMunicipiosSinTildesNiArticulos(): void
    {
        $m = $this->app()->municipios();
        $this->assertSame('08101', $m->buscar('hospitalet de llobregat')[0]['ine']);
        $this->assertSame('29067', $m->buscar('Malaga')[0]['ine']);
        $this->assertSame('03014', $m->buscar('alicante')[0]['ine'], 'Nombres bilingües: vale cualquiera de las dos formas');
        $this->assertSame([], $m->buscar('x'));
    }

    public function testValidaLaFichaYResuelveElMunicipio(): void
    {
        $d = $this->app()->directorio();
        [$datos, $errores] = $d->validarFicha($this->ficha(), true);
        $this->assertSame([], $errores);
        $this->assertSame('08015', $datos['municipio_ine']);
        $this->assertSame('https://aguasprueba.example', $datos['web']);
        $this->assertSame('600112233', $datos['telefono']);

        [, $errores] = $d->validarFicha($this->ficha(['servicios' => [], 'email' => 'no', 'descripcion' => 'Visítanos en www.spam.example']), true);
        $this->assertArrayHasKey('servicios', $errores);
        $this->assertArrayHasKey('email', $errores);
        $this->assertArrayHasKey('descripcion', $errores, 'Sin enlaces en la descripción');

        [, $errores, $sugerencias] = $d->validarFicha($this->ficha(['municipio' => 'Sant', 'provincia' => '08']), true);
        $this->assertArrayHasKey('municipio', $errores, 'Si es ambiguo, pide elegir');
        $this->assertNotEmpty($sugerencias);
    }

    public function testElAltaQuedaPendienteYConSlugUnico(): void
    {
        $app = $this->app();
        $d = $app->directorio();
        [$datos] = $d->validarFicha($this->ficha(), true);
        $a = $d->porId($d->alta($datos));
        $b = $d->porId($d->alta($datos));
        $this->assertSame('pendiente', $a['ficha']);
        $this->assertSame('empresa', $a['origen']);
        $this->assertSame('aguas-prueba-badalona', $a['slug']);
        $this->assertSame('aguas-prueba-badalona-2', $b['slug']);
        $this->assertNull($d->publicada('aguas-prueba-badalona'), 'Pendiente: no se ve');
    }

    public function testOrdenDestacadasPrimeroYLuegoPorCercania(): void
    {
        $app = $this->app();
        $d = $app->directorio();
        $this->publicar($app, ['nombre_comercial' => 'Cerca', 'municipio' => 'Barcelona']);
        $this->publicar($app, ['nombre_comercial' => 'Lejos', 'municipio' => 'Sabadell', 'radio_km' => '50']);
        $this->publicar($app, ['nombre_comercial' => 'Pagada', 'municipio' => 'Badalona'], ['destacada_hasta' => '2026-12-31']);
        $this->publicar($app, ['nombre_comercial' => 'Caducada', 'municipio' => 'Badalona'], ['destacada_hasta' => '2026-01-31']);
        $this->publicar($app, ['nombre_comercial' => 'Fuera de radio', 'municipio' => 'Girona', 'provincia' => '17', 'radio_km' => '50']);

        $barcelona = $app->municipios()->obtener('08019');
        $orden = array_column($d->para($barcelona, '2026-11-12'), 'nombre_comercial');
        $this->assertSame(['Pagada', 'Cerca', 'Caducada', 'Lejos'], $orden);

        $soloOsmosis = $d->para($barcelona, '2026-11-12', 'filtros-agua');
        $this->assertSame([], $soloOsmosis, 'Filtra por servicio');
    }

    public function testElRadioLimitaDondeAparece(): void
    {
        $app = $this->app();
        $this->publicar($app, ['nombre_comercial' => 'Local', 'municipio' => 'Sabadell', 'radio_km' => '10']);
        $d = $app->directorio();
        $this->assertCount(1, $d->para($app->municipios()->obtener('08187'), '2026-11-12'));
        $this->assertCount(0, $d->para($app->municipios()->obtener('08019'), '2026-11-12'), 'Barcelona está a más de 10 km de Sabadell');
    }

    public function testImportaSemillasSinPisarCambios(): void
    {
        $app = $this->app();
        $d = $app->directorio();
        $csv = sys_get_temp_dir() . '/empresas-' . bin2hex(random_bytes(3)) . '.csv';
        file_put_contents($csv, "slug;nombre;razon_social;municipio_ine;servicios;descripcion;web;telefono;email;radio_km\n"
            . "aguas-ejemplo-girona;Aguas Ejemplo;;17079;descalcificadores,inventado;;;;;25\n"
            . "sin-municipio;Nadie;;99999;descalcificadores;;;;;25\n");
        $this->assertSame(1, $d->importar($csv));
        $e = $d->publicada('aguas-ejemplo-girona');
        $this->assertSame('auto', $e['origen']);
        $this->assertSame(['descalcificadores'], $e['servicios']);

        // La empresa la edita; reimportar (aunque cambie el archivo) no la pisa ni la duplica.
        [$datos] = $d->validarFicha(['nombre_comercial' => 'Aguas Ejemplo Girona SL', 'municipio_ine' => '17079', 'servicios' => ['osmosis-inversa']], false);
        $d->actualizarFicha((int) $e['id'], $datos);
        file_put_contents($csv, "\n", FILE_APPEND);
        $this->assertSame(0, $d->importar($csv));
        $this->assertSame('Aguas Ejemplo Girona SL', $d->publicada('aguas-ejemplo-girona')['nombre_comercial']);
        unlink($csv);
    }

    public function testFichaIndexableSoloConDescripcion(): void
    {
        $this->assertFalse(Directorio::indexable(['ficha' => 'publicada', 'descripcion' => 'Corta']));
        $this->assertTrue(Directorio::indexable(['ficha' => 'publicada', 'descripcion' => str_repeat('Texto útil. ', 12)]));
        $this->assertFalse(Directorio::indexable(['ficha' => 'pendiente', 'descripcion' => str_repeat('Texto útil. ', 12)]));
    }

    public function testDistanciaEntreMunicipios(): void
    {
        $m = $this->app()->municipios();
        $km = Municipios::distanciaKm($m->obtener('08019'), $m->obtener('08187'));
        $this->assertGreaterThan(15, $km);
        $this->assertLessThan(25, $km);
    }

    public function testLosEmailsDeTextoNoEscapanHtml(): void
    {
        $app = $this->app();
        $id = $this->publicar($app);
        $app->notificaciones()->avisarTitularEmpresa($app->directorio()->porId($id), 'alta', time());
        $correo = (string) file_get_contents($this->correos()[0]);
        $this->assertStringContainsString('?r=profesional&id=' . $id, $correo);
        $this->assertStringNotContainsString('&amp;', $correo);
    }
}
