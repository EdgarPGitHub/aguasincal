<?php
declare(strict_types=1);

namespace AguaSinCal\Tests;

use AguaSinCal\App;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AppTest extends TestCase
{
    #[DataProvider('numeros')]
    public function testNumeroInternacional(string $entrada, ?string $esperado): void
    {
        $this->assertSame($esperado, App::numeroInternacional($entrada));
    }

    public static function numeros(): array
    {
        return [
            ['600 12 34 56', '34600123456'],
            ['+34 932-123-456', '34932123456'],
            ['0034611223344', '34611223344'],
            ['+44 7911 123456', '447911123456'],
            ['12', null],
            ['', null],
        ];
    }

    public function testElContactoLocalSustituyeAlDeSite(): void
    {
        $app = new App(dirname(__DIR__), sys_get_temp_dir(), ['contacto' => ['telefono' => '600 12 34 56', 'whatsapp' => '611 22 33 44']]);
        $site = $app->config('site');
        $this->assertSame('600 12 34 56', $site['telefono_visible']);
        $this->assertSame('34600123456', $site['telefono_enlace']);
        $this->assertSame('34611223344', $site['whatsapp']);
    }

    public function testSinContactoNoHayTelefono(): void
    {
        $site = (new App(dirname(__DIR__), sys_get_temp_dir(), ['contacto' => ['telefono' => '', 'whatsapp' => '']]))->config('site');
        $this->assertSame('', $site['telefono_enlace']);
        $this->assertSame('', $site['whatsapp']);
    }

    public function testLaClaveSeLeeDeAppKeyEnElServidor(): void
    {
        $datos = sys_get_temp_dir() . '/aguasincal-clave-' . bin2hex(random_bytes(4));
        mkdir($datos);
        file_put_contents($datos . '/app.key', str_repeat('z', 48) . "\n");
        $app = new App(dirname(__DIR__), $datos, ['entorno' => 'produccion']);
        $this->assertSame(str_repeat('z', 48), $app->clave());
    }

    public function testEnProduccionSinClaveFalla(): void
    {
        $this->expectException(\RuntimeException::class);
        (new App(dirname(__DIR__), sys_get_temp_dir() . '/no-existe-' . bin2hex(random_bytes(3)), ['entorno' => 'produccion']))->clave();
    }
}
