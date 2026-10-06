<?php
declare(strict_types=1);

namespace AguaSinCal\Tests;

use AguaSinCal\App;
use AguaSinCal\Mantenimiento;
use PHPUnit\Framework\TestCase;

final class MantenimientoTest extends TestCase
{
    private string $datos;

    protected function setUp(): void
    {
        $this->datos = sys_get_temp_dir() . '/aguasincal-mant-' . bin2hex(random_bytes(4));
        mkdir($this->datos);
    }

    private function app(): App
    {
        return new App(dirname(__DIR__), $this->datos, ['entorno' => 'desarrollo']);
    }

    public function testSeEjecutaUnaVezCada24Horas(): void
    {
        $ahora = 1_800_000_000;
        $mantenimiento = new Mantenimiento($this->app());
        $r = $mantenimiento->siToca($ahora);
        $this->assertNotNull($r);
        $this->assertFileExists($this->datos . '/backups/' . $r['copia']);
        $this->assertNull($mantenimiento->siToca($ahora + 3600), 'No repite antes de 24 h');
        $this->assertNotNull($mantenimiento->siToca($ahora + 86400 + 1), 'Vuelve a ejecutarse al día siguiente');
    }

    public function testBorraCopiasDeMasDe14Dias(): void
    {
        mkdir($this->datos . '/backups');
        $vieja = $this->datos . '/backups/aguasincal-2000-01-01.sqlite';
        touch($vieja);
        (new Mantenimiento($this->app()))->ejecutar(time());
        $this->assertFileDoesNotExist($vieja);
    }
}
