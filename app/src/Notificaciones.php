<?php
declare(strict_types=1);

namespace AguaSinCal;

/** Emails que genera un lead: aviso al titular, envío al profesional y confirmación al cliente. */
final class Notificaciones
{
    public const CADUCIDAD_ENLACES = 30 * 86400;

    public function __construct(private readonly App $app, private readonly Correo $correo)
    {
    }

    /** Aviso al titular con enlaces de acción firmados. */
    public function avisarTitular(array $lead, int $ahora): bool
    {
        $para = (string) $this->app->local('leads_email', '');
        if ($para === '') {
            error_log('[leads] Falta leads_email en config.local.php; el lead #' . $lead['id'] . ' solo está en el panel.');
            return false;
        }
        $enlaces = [];
        $caduca = $ahora + self::CADUCIDAD_ENLACES;
        if ($lead['modo'] === 'activo' && !empty($lead['profesional_id'])) {
            $enlaces['enviar_email'] = $this->enlaceAccion('enviar_email', (int) $lead['id'], $caduca);
            $enlaces['enviar_whatsapp'] = $this->enlaceAccion('enviar_whatsapp', (int) $lead['id'], $caduca);
        }
        $enlaces['invalido'] = $this->enlaceAccion('invalido', (int) $lead['id'], $caduca);
        $enlaces['panel'] = $this->app->url($this->app->rutaPanel() . '?r=lead&id=' . (int) $lead['id']);

        $asunto = sprintf(
            '[AguaSinCal] Lead #%d · %s · %s (%s) · %s',
            $lead['id'],
            $this->app->servicios()[$lead['servicio']]['formulario'] ?? $lead['servicio'],
            $lead['municipio'] !== '' ? $lead['municipio'] : $lead['cp'],
            $this->app->provincias()->nombre($lead['provincia']),
            $lead['modo'] === 'activo' ? 'ACTIVO' : 'LISTA DE ESPERA',
        );
        $texto = $this->app->twig()->render('correo/titular.txt.twig', ['lead' => $lead, 'enlaces' => $enlaces] + $this->comun());
        return $this->correo->enviar($para, $asunto, $texto);
    }

    /** Envío del lead al profesional (Reply-To al titular). */
    public function enviarAProfesional(array $lead, array $profesional): bool
    {
        $asunto = sprintf(
            'Nuevo cliente AguaSinCal: %s en %s (%s)',
            $this->app->servicios()[$lead['servicio']]['formulario'] ?? $lead['servicio'],
            $lead['municipio'] !== '' ? $lead['municipio'] : $lead['cp'],
            $lead['cp'],
        );
        $texto = $this->app->twig()->render('correo/profesional.txt.twig', ['lead' => $lead, 'profesional' => $profesional] + $this->comun());
        return $this->correo->enviar((string) $profesional['email'], $asunto, $texto, (string) $this->app->local('leads_email', ''));
    }

    /** Confirmación al cliente, solo si dejó email. */
    public function confirmarCliente(array $lead, ?array $profesional): bool
    {
        if ($lead['email'] === '') {
            return false;
        }
        $texto = $this->app->twig()->render('correo/cliente.txt.twig', ['lead' => $lead, 'profesional' => $profesional] + $this->comun());
        return $this->correo->enviar($lead['email'], 'Hemos recibido tu solicitud – AguaSinCal', $texto, (string) ($this->app->config('site')['email_publico'] ?: null));
    }

    /** Texto para reenviar el lead por WhatsApp al profesional. */
    public function textoWhatsapp(array $lead): string
    {
        return trim($this->app->twig()->render('correo/whatsapp.txt.twig', ['lead' => $lead] + $this->comun()));
    }

    public function enlaceAccion(string $accion, int $leadId, int $caduca): string
    {
        $token = $this->app->firma()->firmar(['a' => $accion, 'l' => $leadId], $caduca);
        return $this->app->url('/accion/?t=' . $token);
    }

    private function comun(): array
    {
        return [
            'servicios' => $this->app->servicios(),
            'provincias' => $this->app->provincias()->todas(),
            'tipos_cliente' => Leads::TIPOS_CLIENTE,
            'modalidades' => Leads::MODALIDADES,
            'site' => $this->app->config('site'),
        ];
    }
}
