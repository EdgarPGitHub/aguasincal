<?php
declare(strict_types=1);

namespace AguaSinCal\Tests;

use AguaSinCal\Firma;
use AguaSinCal\Http;
use AguaSinCal\TokenFormulario;
use PHPUnit\Framework\TestCase;

final class SeguridadTest extends TestCase
{
    use Apoyo;

    public function testFirmaValidaManipuladaYCaducada(): void
    {
        $firma = new Firma(str_repeat('a', 40));
        $token = $firma->firmar(['a' => 'enviar_email', 'l' => 7], 2000);
        $this->assertSame(7, $firma->verificar($token, 1000)['l']);
        $this->assertNull($firma->verificar($token, 2001), 'Caducado');
        $this->assertNull($firma->verificar($token . 'x', 1000), 'Firma alterada');
        [$cuerpo, $mac] = explode('.', $token);
        $otro = rtrim(strtr(base64_encode('{"a":"enviar_email","l":8,"exp":2000}'), '+/', '-_'), '=');
        $this->assertNull($firma->verificar($otro . '.' . $mac, 1000), 'Cuerpo alterado');
        $this->assertNull((new Firma(str_repeat('b', 40)))->verificar($token, 1000), 'Otra clave');
    }

    public function testTokenFormulario(): void
    {
        $t = new TokenFormulario(str_repeat('a', 40));
        $token = $t->emitir(1_800_000_000);
        $this->assertSame('rapido', $t->validar($token, 1_800_000_001));
        $this->assertSame('ok', $t->validar($token, 1_800_000_010));
        $this->assertSame('caducado', $t->validar($token, 1_800_000_000 + 7201));
        $this->assertSame('invalido', $t->validar('1800000000.' . str_repeat('0', 64), 1_800_000_010));
        $this->assertSame('invalido', $t->validar('basura', 1_800_000_010));
    }

    public function testLimitador(): void
    {
        $limitador = $this->app()->limitador();
        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse($limitador->superado('k', 5, 60, 1000));
            $limitador->registrar('k', 1000);
        }
        $this->assertTrue($limitador->superado('k', 5, 60, 1000));
        $this->assertFalse($limitador->superado('k', 5, 60, 1100), 'Fuera de la ventana');
        $this->assertSame(5, $limitador->purgar(1050));
    }

    public function testRutaInternaEvitaRedireccionesAbiertas(): void
    {
        $this->assertSame('/guias/x/', Http::rutaInterna('/guias/x/?a=1#b'));
        $this->assertSame('', Http::rutaInterna('//malo.com/'));
        $this->assertSame('', Http::rutaInterna('https://malo.com/'));
        $this->assertSame('', Http::rutaInterna('/\\malo.com'));
    }

    public function testElPanelEscapaLosDatosDelFormulario(): void
    {
        $app = $this->app();
        [$datos] = $app->leads()->validarPaso2([
            'servicio' => 'descalcificadores', 'tipo' => 'particular', 'cp' => '08201',
            'nombre' => '<img src=x onerror=alert(1)>', 'telefono' => '612345678',
            'mensaje' => '<script>alert(2)</script>', 'acepto' => '1',
        ]);
        $id = $app->leads()->crear($datos, ['modo' => 'espera', 'profesional' => null], []);
        $html = $app->twig()->render('panel/lead.twig', [
            'lead' => $app->leads()->obtener($id), 'utm' => [], 'profesionales' => [], 'motivos' => [],
            'whatsapp_url' => '', 'aviso' => '', 'error' => '', 'base' => '/gestion/', 'csrf' => 'x',
            'usuario' => ['id' => 1], 'estados' => \AguaSinCal\Leads::ESTADOS, 'ruta' => 'lead',
        ]);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('<script>alert(2)', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testCrearUsuarioYCredenciales(): void
    {
        $app = $this->app();
        $auth = $app->auth();
        $auth->crearUsuario('Admin@Ejemplo.test', 'una-clave-bien-larga');
        [$u] = $auth->credenciales('admin@ejemplo.test', 'una-clave-bien-larga', 'ip', 1000);
        $this->assertNotNull($u);
        for ($i = 0; $i < 5; $i++) {
            [$u, $error] = $auth->credenciales('admin@ejemplo.test', 'mal', 'ip', 1000);
            $this->assertNull($u);
        }
        [$u, $error] = $auth->credenciales('admin@ejemplo.test', 'una-clave-bien-larga', 'ip', 1001);
        $this->assertNull($u, 'Bloqueado tras 5 intentos');
        $this->assertStringContainsString('Demasiados intentos', $error);
    }

    public function testRechazaContrasenasCortas(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->app()->auth()->crearUsuario('a@ejemplo.test', 'corta');
    }

    public function testUsuarioInicialDesdeHash(): void
    {
        $auth = $this->app()->auth();
        $auth->crearUsuarioConHash('Admin@Ejemplo.test', password_hash('una-clave-bien-larga', PASSWORD_BCRYPT));
        [$u] = $auth->credenciales('admin@ejemplo.test', 'una-clave-bien-larga', 'ip', 1000);
        $this->assertNotNull($u);
        $this->expectException(\InvalidArgumentException::class);
        $auth->crearUsuarioConHash('otro@ejemplo.test', 'texto-en-claro');
    }

    public function testEmailProtegido(): void
    {
        $html = \AguaSinCal\Vista::emailProtegido('info@aguasincal.es');
        $this->assertStringNotContainsString('info@aguasincal.es', $html);
        $this->assertStringContainsString('info [arroba] aguasincal.es', $html);
        $this->assertStringContainsString('data-u="ofni"', $html);
    }
}
