<?php
declare(strict_types=1);

namespace AguaSinCal\Controladores;

use AguaSinCal\App;
use AguaSinCal\Http;

/** /api/evento.php — cuenta clics en "Llamar" y "WhatsApp" por página y día. Sin cookies ni datos personales. */
final class Evento
{
    public const TIPOS = ['llamar', 'whatsapp'];

    public function __construct(private readonly App $app)
    {
    }

    public function manejar(): void
    {
        header('X-Robots-Tag: noindex');
        if (!Http::esPost()) {
            http_response_code(405);
            return;
        }
        $tipo = (string) ($_POST['tipo'] ?? '');
        $pagina = Http::rutaInterna((string) ($_POST['pagina'] ?? ''));
        if (!in_array($tipo, self::TIPOS, true) || $pagina === '') {
            http_response_code(400);
            return;
        }
        $ahora = time();
        $limitador = $this->app->limitador();
        $clave = 'evento:ip:' . Http::ipHash($this->app->clave());
        if ($limitador->superado($clave, 60, 3600, $ahora)) {
            http_response_code(204);
            return;
        }
        $limitador->registrar($clave, $ahora);
        self::registrar($this->app->db(), gmdate('Y-m-d', $ahora), $pagina, $tipo);
        http_response_code(204);
    }

    public static function registrar(\PDO $db, string $dia, string $pagina, string $tipo): void
    {
        $st = $db->prepare('INSERT INTO eventos (dia, pagina, tipo, total) VALUES (?, ?, ?, 1)
            ON CONFLICT (dia, pagina, tipo) DO UPDATE SET total = total + 1');
        $st->execute([$dia, $pagina, $tipo]);
    }
}
