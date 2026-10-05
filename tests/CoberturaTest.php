<?php
declare(strict_types=1);

namespace AguaSinCal\Tests;

use PHPUnit\Framework\TestCase;

final class CoberturaTest extends TestCase
{
    use Apoyo;

    public function testSinConfiguracionEsListaDeEspera(): void
    {
        $r = $this->app()->cobertura()->resolver('46', 'descalcificadores');
        $this->assertSame('espera', $r['modo']);
        $this->assertNull($r['profesional']);
    }

    public function testZonaActivaDevuelveElProfesional(): void
    {
        $app = $this->app();
        $pro = $this->profesional($app);
        $app->cobertura()->guardar('08', 'descalcificadores', 'activo', $pro);
        $r = $app->cobertura()->resolver('08', 'descalcificadores');
        $this->assertSame('activo', $r['modo']);
        $this->assertSame('Aguas Prueba', $r['profesional']['nombre_comercial']);
        $this->assertSame('espera', $app->cobertura()->resolver('08', 'osmosis-inversa')['modo']);
    }

    public function testProfesionalInactivoSeTrataComoEspera(): void
    {
        $app = $this->app();
        $pro = $this->profesional($app, ['activo' => 0]);
        $app->cobertura()->guardar('29', 'osmosis-inversa', 'activo', $pro);
        $this->assertSame('espera', $app->cobertura()->resolver('29', 'osmosis-inversa')['modo']);
    }

    public function testNoSePuedeActivarSinProfesional(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->app()->cobertura()->guardar('08', 'descalcificadores', 'activo', null);
    }

    public function testGuardarActualizaLaFila(): void
    {
        $app = $this->app();
        $pro = $this->profesional($app);
        $app->cobertura()->guardar('08', 'descalcificadores', 'activo', $pro);
        $app->cobertura()->guardar('08', 'descalcificadores', 'espera', $pro);
        $this->assertSame('espera', $app->cobertura()->resolver('08', 'descalcificadores')['modo']);
        $this->assertCount(1, $app->cobertura()->mapa()['08']);
    }
}
