<?php
declare(strict_types=1);

namespace AguaSinCal\Tests;

use AguaSinCal\Build\DatosAgua;
use PHPUnit\Framework\TestCase;

final class DatosAguaTest extends TestCase
{
    public function testDurezaCoherenteConCalcioYMagnesio(): void
    {
        $p = ['dureza' => [117, 'mg/L', '2025-06-16'], 'calcio' => [38, 'mg/L', '2025-06-16'], 'magnesio' => [5, 'mg/L', '2025-06-16']];
        $this->assertSame(117.0, DatosAgua::durezaMgL($p, '2026-11-12')['mg']);
    }

    public function testDurezaPublicadaEnGradosFrancesesSeCorrige(): void
    {
        // Caso real (Palma): «25 mg/L» con 74 mg/L de calcio y 17 de magnesio → son 25 °fH (≈ 250 mg/L).
        $p = ['dureza' => [25, 'mg/L', '2026-06-11'], 'calcio' => [74, 'mg/L', '2026-06-11'], 'magnesio' => [17, 'mg/L', '2026-06-11']];
        $this->assertSame(250.0, DatosAgua::durezaMgL($p, '2026-11-12')['mg']);
    }

    public function testDatoIncoherenteUsaElMasRecienteOElCalculado(): void
    {
        $p = ['dureza' => [498, 'mg/L', '2026-01-10'], 'calcio' => [80, 'mg/L', '2026-01-10'], 'magnesio' => [16, 'mg/L', '2026-01-10']];
        $r = DatosAgua::durezaMgL($p, '2026-11-12');
        $this->assertTrue($r['calculada']);
        $this->assertSame(266.0, $r['mg']);
        $p['dureza'][2] = '2026-05-01';
        $this->assertSame(498.0, DatosAgua::durezaMgL($p, '2026-11-12')['mg'], 'Si la dureza es más reciente, manda ella');
    }

    public function testSeDescartanMuestrasDeMasDeCincoAnos(): void
    {
        $this->assertNull(DatosAgua::durezaMgL(['dureza' => [200, 'mg/L', '2004-10-06']], '2026-11-12'));
    }
}
