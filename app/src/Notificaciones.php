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
            $lead['municipio'] !== '' ? $lead['municipio'] : $this->app->provincias()->nombre($lead['provincia']),
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

    /** Enlace para editar la ficha (al darse de alta o cuando la empresa lo pide). Abrirlo verifica el email. */
    public function enlaceEdicionEmpresa(array $empresa, int $ahora, bool $alta): bool
    {
        if (!filter_var($empresa['email'], FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $token = $this->app->firma()->firmar(['a' => 'editar_empresa', 'e' => (int) $empresa['id']], $ahora + \AguaSinCal\Controladores\Empresas::CADUCIDAD_ENLACE_EDICION);
        $texto = $this->app->twig()->render('correo/empresa-enlace.txt.twig', [
            'empresa' => $empresa,
            'alta' => $alta,
            'enlace' => $this->app->url('/empresas/editar/?t=' . $token),
        ] + $this->comun());
        $asunto = $alta ? 'Confirma tu email y revisa tu ficha en AguaSinCal' : 'Enlace para editar tu ficha en AguaSinCal';
        return $this->correo->enviar((string) $empresa['email'], $asunto, $texto);
    }

    public function avisarEmpresaPublicada(array $empresa): bool
    {
        if (!filter_var($empresa['email'], FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $texto = $this->app->twig()->render('correo/empresa-publicada.txt.twig', [
            'empresa' => $empresa,
            'ficha' => $this->app->url('/empresa/' . $empresa['slug'] . '/'),
            'acceso' => $this->app->url('/empresas/acceso/'),
        ] + $this->comun());
        return $this->correo->enviar((string) $empresa['email'], 'Tu ficha ya está publicada en AguaSinCal', $texto, (string) ($this->app->config('site')['email_publico'] ?: null));
    }

    /** Aviso al titular sobre una ficha: alta nueva, reclamación de una ficha o cambios hechos por la empresa. */
    public function avisarTitularEmpresa(array $empresa, string $tipo, int $ahora, array $extra = []): bool
    {
        $para = (string) $this->app->local('leads_email', '');
        if ($para === '') {
            return false;
        }
        $caduca = $ahora + self::CADUCIDAD_ENLACES;
        $firmar = fn (array $datos) => $this->app->url('/accion/?t=' . $this->app->firma()->firmar($datos + ['e' => (int) $empresa['id']], $caduca));
        $enlaces = ['panel' => $this->app->url($this->app->rutaPanel() . '?r=profesional&id=' . (int) $empresa['id'])];
        if ($tipo === 'alta') {
            $enlaces['publicar'] = $firmar(['a' => 'publicar_empresa']);
            $enlaces['descartar'] = $firmar(['a' => 'descartar_empresa']);
        } elseif ($tipo === 'reclamacion') {
            $enlaces['asignar'] = $firmar(['a' => 'asignar_email', 'm' => (string) $extra['email']]);
        }
        $asuntos = ['alta' => 'Nueva empresa para revisar', 'reclamacion' => 'Reclamación de ficha', 'cambios' => 'Ficha actualizada por la empresa'];
        $texto = $this->app->twig()->render('correo/titular-empresa.txt.twig', [
            'empresa' => $empresa, 'tipo' => $tipo, 'extra' => $extra, 'enlaces' => $enlaces,
            'ficha' => $this->app->url('/empresa/' . $empresa['slug'] . '/'),
        ] + $this->comun());
        return $this->correo->enviar($para, '[AguaSinCal] ' . ($asuntos[$tipo] ?? 'Empresa') . ': ' . $empresa['nombre_comercial'], $texto);
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
