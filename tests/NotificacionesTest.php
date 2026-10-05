<?php
declare(strict_types=1);

namespace AguaSinCal\Tests;

use PHPUnit\Framework\TestCase;

final class NotificacionesTest extends TestCase
{
    use Apoyo;

    public function testAvisoAlTitularConEnlacesFirmadosSoloEnZonaActiva(): void
    {
        $app = $this->app();
        $pro = $this->profesional($app);
        $leads = $app->leads();
        [$datos] = $leads->validarPaso2(['servicio' => 'osmosis-inversa', 'tipo' => 'hosteleria', 'cp' => '29600', 'nombre' => 'Bar Prueba', 'telefono' => '612345678', 'acepto' => '1']);
        $activo = $leads->obtener($leads->crear($datos, ['modo' => 'activo', 'profesional' => ['id' => $pro]], []));
        $espera = $leads->obtener($leads->crear($datos, ['modo' => 'espera', 'profesional' => null], []));

        $notif = $app->notificaciones();
        $this->assertTrue($notif->avisarTitular($activo, time()));
        $this->assertTrue($notif->avisarTitular($espera, time()));
        $correos = array_map('file_get_contents', $this->correos());
        sort($correos);
        $todo = implode("\n----\n", $correos);
        $this->assertSame(2, substr_count($todo, 'To: titular@ejemplo.test'));
        $this->assertSame(1, substr_count($todo, 'Enviar al profesional por email: https://aguasincal.test/accion/?t='), 'Solo el lead activo lleva el botón de envío');
        $this->assertStringContainsString('LISTA DE ESPERA', $todo);

        preg_match('#accion/\?t=(\S+)#', $todo, $m);
        $datosToken = $app->firma()->verificar($m[1], time());
        $this->assertSame('enviar_email', $datosToken['a']);
    }

    public function testEnvioAlProfesionalConRespuestaAlTitular(): void
    {
        $app = $this->app();
        $proId = $this->profesional($app);
        $leads = $app->leads();
        [$datos] = $leads->validarPaso2(['servicio' => 'descalcificadores', 'tipo' => 'particular', 'cp' => '08201', 'nombre' => 'Ana', 'telefono' => '612345678', 'personas' => '4', 'acepto' => '1']);
        $lead = $leads->obtener($leads->crear($datos, ['modo' => 'activo', 'profesional' => ['id' => $proId]], []));
        $this->assertTrue($app->notificaciones()->enviarAProfesional($lead, $app->profesionales()->obtener($proId)));
        $correo = (string) file_get_contents($this->correos()[0]);
        $this->assertStringContainsString('To: pro@ejemplo.test', $correo);
        $this->assertStringContainsString('Reply-To: titular@ejemplo.test', $correo);
        $this->assertStringContainsString('Personas:   4', $correo);
        $this->assertStringContainsString('612345678', $app->notificaciones()->textoWhatsapp($lead));
    }
}
