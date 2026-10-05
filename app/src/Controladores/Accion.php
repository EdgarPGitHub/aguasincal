<?php
declare(strict_types=1);

namespace AguaSinCal\Controladores;

use AguaSinCal\App;
use AguaSinCal\Http;
use AguaSinCal\Leads;

/**
 * /accion/?t=… — enlaces de un clic del email de aviso.
 * GET solo muestra una confirmación (los antivirus de correo abren enlaces solos);
 * la acción se ejecuta con el botón (POST).
 */
final class Accion
{
    private const ACCIONES = [
        'enviar_email' => 'Enviar el lead al profesional por email',
        'enviar_whatsapp' => 'Enviar el lead al profesional por WhatsApp',
        'invalido' => 'Marcar el lead como inválido',
    ];

    public function __construct(private readonly App $app)
    {
    }

    public function manejar(): string
    {
        Http::cabeceras(panel: true);
        $token = (string) ($_POST['t'] ?? $_GET['t'] ?? '');
        $datos = $this->app->firma()->verificar($token, time());
        if ($datos === null || !isset(self::ACCIONES[$datos['a'] ?? ''])) {
            http_response_code(403);
            return $this->render(['error' => 'El enlace no es válido o ha caducado. Gestiona el lead desde el panel.']);
        }
        $lead = $this->app->leads()->obtener((int) $datos['l']);
        if ($lead === null || $lead['anonimizado'] !== null) {
            http_response_code(404);
            return $this->render(['error' => 'Este lead ya no existe.']);
        }
        $profesional = $lead['profesional_id'] ? $this->app->profesionales()->obtener((int) $lead['profesional_id']) : null;
        $vars = [
            'token' => $token,
            'accion' => $datos['a'],
            'accion_nombre' => self::ACCIONES[$datos['a']],
            'lead' => $lead,
            'profesional' => $profesional,
            'motivos' => Leads::MOTIVOS_INVALIDO,
        ];
        if (!Http::esPost()) {
            return $this->render($vars);
        }
        return $this->render($vars + $this->ejecutar($datos['a'], $lead, $profesional));
    }

    /** @return array{hecho?: string, error?: string, whatsapp_url?: string} */
    private function ejecutar(string $accion, array $lead, ?array $profesional): array
    {
        $leads = $this->app->leads();
        if ($accion === 'invalido') {
            $motivo = (string) ($_POST['motivo'] ?? 'otro');
            $leads->cambiarEstado((int) $lead['id'], 'invalido', $motivo);
            return ['hecho' => 'Lead #' . $lead['id'] . ' marcado como inválido.'];
        }
        if ($profesional === null || !$profesional['activo']) {
            return ['error' => 'Este lead no tiene un profesional activo asignado. Asígnalo desde el panel.'];
        }
        $notificaciones = $this->app->notificaciones();
        if ($accion === 'enviar_email') {
            if ($profesional['email'] === '' || !$notificaciones->enviarAProfesional($lead, $profesional)) {
                return ['error' => 'No se ha podido enviar el email al profesional. Revisa su email en el panel.'];
            }
            $leads->marcarEnviado((int) $lead['id'], (int) $profesional['id']);
            return ['hecho' => 'Lead #' . $lead['id'] . ' enviado a ' . $profesional['nombre_comercial'] . '.'];
        }
        // enviar_whatsapp
        if ($profesional['whatsapp'] === '') {
            return ['error' => 'El profesional no tiene WhatsApp configurado en el panel.'];
        }
        $leads->marcarEnviado((int) $lead['id'], (int) $profesional['id']);
        return [
            'hecho' => 'Lead #' . $lead['id'] . ' marcado como enviado. Pulsa el botón para abrir WhatsApp con el mensaje preparado.',
            'whatsapp_url' => 'https://wa.me/' . $profesional['whatsapp'] . '?text=' . rawurlencode($notificaciones->textoWhatsapp($lead)),
        ];
    }

    private function render(array $vars): string
    {
        return $this->app->twig()->render('pages/accion.twig', $vars + [
            'pagina' => ['titulo' => 'Gestionar lead', 'seo_title' => 'Gestionar lead | AguaSinCal', 'descripcion' => '', 'ruta' => '/accion/', 'indexable' => false],
        ]);
    }
}
