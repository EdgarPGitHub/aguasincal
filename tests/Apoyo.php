<?php
declare(strict_types=1);

namespace AguaSinCal\Tests;

use AguaSinCal\App;

/** App con base de datos SQLite en memoria y correo a archivos temporales. */
trait Apoyo
{
    private ?string $tmp = null;

    protected function app(array $local = []): App
    {
        $this->tmp ??= sys_get_temp_dir() . '/aguasincal-test-' . bin2hex(random_bytes(4));
        return new App(dirname(__DIR__), $this->tmp, $local + [
            'entorno' => 'desarrollo',
            'app_key' => str_repeat('k', 40),
            'db' => ['dsn' => 'sqlite::memory:'],
            'leads_email' => 'titular@ejemplo.test',
            'correo' => ['transporte' => 'archivo'],
            'url' => 'https://aguasincal.test',
        ]);
    }

    protected function profesional(App $app, array $datos = []): int
    {
        return $app->profesionales()->guardar(null, $datos + [
            'nombre_comercial' => 'Aguas Prueba', 'razon_social' => 'Aguas Prueba SL', 'email' => 'pro@ejemplo.test',
            'whatsapp' => '34600000000', 'precio_lead' => 20.0, 'notas' => '', 'activo' => 1,
        ]);
    }

    protected function correos(): array
    {
        return glob(($this->tmp ?? '') . '/correo/*.eml') ?: [];
    }
}
