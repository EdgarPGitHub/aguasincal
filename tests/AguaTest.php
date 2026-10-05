<?php
declare(strict_types=1);

namespace AguaSinCal\Tests;

use AguaSinCal\Agua;
use AguaSinCal\Consentimiento;
use PHPUnit\Framework\TestCase;

final class AguaTest extends TestCase
{
    public function testCategorias(): void
    {
        $this->assertSame('blanda', Agua::categoria(10)['clave']);
        $this->assertSame('media', Agua::categoria(15)['clave']);
        $this->assertSame('dura', Agua::categoria(30)['clave']);
        $this->assertSame('muy-dura', Agua::categoria(40)['clave']);
        $this->assertSame('sin-datos', Agua::categoria(null)['clave']);
        $this->assertSame(100.0, Agua::categoria(90)['posicion']);
    }

    public function testDimensionado(): void
    {
        // Mismo caso que comprueba la calculadora en JavaScript (assets/js/app.js).
        $this->assertSame(['litros_resina' => 25, 'sal_kg_anual' => 195, 'regeneraciones_anuales' => 52], Agua::dimensionado(4, 39.3));
        $this->assertNull(Agua::dimensionado(4, 7.0), 'Agua blanda: no hace falta');
        $this->assertNull(Agua::dimensionado(0, 40.0));
        $this->assertSame(100, Agua::dimensionado(20, 120.0)['litros_resina'], 'Tope del mayor tamaño');
    }

    public function testRecomendacion(): void
    {
        $this->assertSame('no-necesario', Agua::recomendacion(12)['descalcificador']);
        $this->assertSame('muy-recomendado', Agua::recomendacion(45)['descalcificador']);
    }

    public function testConsentimientoNombraAlProfesionalSoloEnZonaActiva(): void
    {
        $pro = ['nombre_comercial' => 'Aguas Prueba', 'razon_social' => 'Aguas Prueba SL'];
        $this->assertStringContainsString('Aguas Prueba (Aguas Prueba SL)', Consentimiento::texto('activo', $pro, 'AguaSinCal'));
        $espera = Consentimiento::texto('espera', null, 'AguaSinCal');
        $this->assertStringContainsString('No se comunicarán', $espera);
        $this->assertNotSame(Consentimiento::version('activo'), Consentimiento::version('espera'));
    }
}
