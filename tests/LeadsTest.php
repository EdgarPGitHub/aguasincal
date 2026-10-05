<?php
declare(strict_types=1);

namespace AguaSinCal\Tests;

use AguaSinCal\Leads;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LeadsTest extends TestCase
{
    use Apoyo;

    private function paso2(array $extra = []): array
    {
        return $extra + [
            'servicio' => 'descalcificadores', 'tipo' => 'particular', 'cp' => '08201',
            'nombre' => 'Ana', 'telefono' => '612 345 678', 'acepto' => '1',
        ];
    }

    public function testElCodigoPostalDeterminaLaProvincia(): void
    {
        $leads = $this->app()->leads();
        [$datos, $errores] = $leads->validarPaso1(['servicio' => 'osmosis-inversa', 'tipo' => 'empresa', 'cp' => '29600']);
        $this->assertSame([], $errores);
        $this->assertSame('29', $datos['provincia']);
    }

    #[DataProvider('cpNoValidos')]
    public function testRechazaCodigosPostalesNoValidos(string $cp): void
    {
        [, $errores] = $this->app()->leads()->validarPaso1(['servicio' => 'descalcificadores', 'cp' => $cp]);
        $this->assertArrayHasKey('cp', $errores);
    }

    public static function cpNoValidos(): array
    {
        return [['0820'], ['99999'], ['00123'], ['abcde'], ['']];
    }

    #[DataProvider('telefonos')]
    public function testNormalizaTelefonos(string $entrada, ?string $esperado): void
    {
        $this->assertSame($esperado, Leads::normalizarTelefono($entrada));
    }

    public static function telefonos(): array
    {
        return [
            ['612 345 678', '612345678'],
            ['+34 612-345-678', '612345678'],
            ['0034612345678', '612345678'],
            ['932.123.456', '932123456'],
            ['+44 7911 123456', '+447911123456'],
            ['12345', null],
            ['512345678', null],
        ];
    }

    public function testExigeConsentimientoYNombre(): void
    {
        [, $errores] = $this->app()->leads()->validarPaso2($this->paso2(['acepto' => '', 'nombre' => 'A']));
        $this->assertArrayHasKey('acepto', $errores);
        $this->assertArrayHasKey('nombre', $errores);
    }

    public function testLasComunidadesSeEnrutanComoServicioPropio(): void
    {
        $this->assertSame('comunidades', Leads::servicioEfectivo('descalcificadores', 'comunidad'));
        $this->assertSame('descalcificadores', Leads::servicioEfectivo('descalcificadores', 'empresa'));
    }

    public function testCrearLeadActivoYEnEspera(): void
    {
        $app = $this->app();
        $pro = $this->profesional($app);
        $leads = $app->leads();
        [$datos] = $leads->validarPaso2($this->paso2());
        $activo = $leads->crear($datos, ['modo' => 'activo', 'profesional' => ['id' => $pro]], ['consentimiento_version' => 'v-activo']);
        $espera = $leads->crear($datos, ['modo' => 'espera', 'profesional' => null], ['consentimiento_version' => 'v-espera']);

        $this->assertSame('nuevo', $leads->obtener($activo)['estado']);
        $this->assertSame($pro, (int) $leads->obtener($activo)['profesional_id']);
        $this->assertSame('espera', $leads->obtener($espera)['estado']);
        $this->assertNull($leads->obtener($espera)['profesional_id']);
        $this->assertStringContainsString('#' . $activo, $leads->obtener($espera)['notas'], 'Marca el posible duplicado');
    }

    public function testAnonimizaLeadsAntiguos(): void
    {
        $app = $this->app();
        $leads = $app->leads();
        [$datos] = $leads->validarPaso2($this->paso2());
        $id = $leads->crear($datos, ['modo' => 'espera', 'profesional' => null], []);
        $this->assertSame(0, $leads->anonimizarAntiguos(time()));
        $this->assertSame(1, $leads->anonimizarAntiguos(strtotime('+13 months')));
        $lead = $leads->obtener($id);
        $this->assertSame('', $lead['telefono']);
        $this->assertSame('(anonimizado)', $lead['nombre']);
        $this->assertSame('08201', $lead['cp'], 'Se conservan los datos no personales');
    }

    public function testFiltrosYExportacion(): void
    {
        $app = $this->app();
        $pro = $this->profesional($app);
        $leads = $app->leads();
        [$datos] = $leads->validarPaso2($this->paso2());
        $id = $leads->crear($datos, ['modo' => 'activo', 'profesional' => ['id' => $pro]], []);
        $leads->marcarEnviado($id, $pro);
        $this->assertSame(1, $leads->contar(['estado' => 'enviado', 'provincia' => '08']));
        $this->assertSame(0, $leads->contar(['provincia' => '29']));
        $filas = $leads->exportar(gmdate('Y-m'), $pro);
        $this->assertCount(1, $filas);
        $this->assertEquals(20.0, $filas[0]['precio_lead']);
    }
}
