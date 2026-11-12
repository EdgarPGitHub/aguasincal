<?php
declare(strict_types=1);

namespace AguaSinCal\Controladores;

use AguaSinCal\App;
use AguaSinCal\Consentimiento;
use AguaSinCal\Http;
use AguaSinCal\Leads;

/**
 * /presupuesto/ — formulario en 2 pasos.
 * Paso 1 (servicio, CP, tipo) puede venir de cualquier página estática por GET.
 * Paso 2 muestra el texto legal correcto según la cobertura de la provincia antes de enviar.
 */
final class Presupuesto
{
    public const MAX_LEADS_IP_HORA = 5;
    private const CAMPOS_UTM = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid'];

    public function __construct(private readonly App $app)
    {
    }

    public function manejar(): string
    {
        Http::cabeceras();
        $ahora = time();
        if (Http::esPost()) {
            return $this->enviar($_POST, $ahora);
        }
        $q = $_GET;
        if (isset($q['ok'])) {
            return $this->render('gracias', ['modo' => in_array($q['m'] ?? '', ['activo', 'empresa'], true) ? $q['m'] : 'espera']);
        }
        if (isset($q['cambiar'])) {
            $datos = ['servicio' => (string) ($q['servicio'] ?? ''), 'cp' => '', 'tipo_cliente' => (string) ($q['tipo'] ?? 'particular')];
            return $this->render('paso1', ['datos' => $datos, 'errores' => [], 'meta' => $this->meta($q)]);
        }
        if (!isset($q['cp']) && isset($q['servicio'])) {
            // Viene de un enlace con el servicio ya elegido: falta el CP, sin mostrar errores todavía.
            $datos = ['servicio' => (string) $q['servicio'], 'cp' => '', 'tipo_cliente' => (string) ($q['tipo'] ?? 'particular')];
            return $this->render('paso1', ['datos' => $datos, 'errores' => [], 'meta' => $this->meta($q)]);
        }
        if (isset($q['servicio']) || isset($q['cp'])) {
            [$datos, $errores] = $this->app->leads()->validarPaso1($q);
            $meta = $this->meta($q);
            if ($errores) {
                return $this->render('paso1', ['datos' => $datos, 'errores' => $errores, 'meta' => $meta]);
            }
            return $this->paso2($datos, [], $meta, $ahora);
        }
        return $this->render('paso1', ['datos' => ['servicio' => '', 'cp' => '', 'tipo_cliente' => 'particular'], 'errores' => [], 'meta' => $this->meta($q)]);
    }

    private function enviar(array $in, int $ahora): string
    {
        $leads = $this->app->leads();
        $meta = $this->meta($in);

        // Campo trampa: los humanos no lo ven. Si viene relleno, fingimos éxito y no guardamos nada.
        if (trim((string) ($in['web'] ?? '')) !== '') {
            Http::redirigir('/presupuesto/?ok=1&m=espera');
        }

        [$datos, $errores] = $leads->validarPaso2($in);
        $estadoToken = $this->app->tokenFormulario()->validar((string) ($in['token'] ?? ''), $ahora);
        if ($estadoToken === 'invalido') {
            http_response_code(400);
            $errores['general'] = 'No hemos podido validar el formulario. Vuelve a intentarlo.';
        } elseif ($estadoToken === 'rapido') {
            $errores['general'] = 'Revisa los datos y vuelve a enviar.';
        } elseif ($estadoToken === 'caducado') {
            $errores['general'] = 'El formulario ha caducado. Revisa los datos y vuelve a enviarlo.';
        }

        $ipHash = Http::ipHash($this->app->clave());
        $limitador = $this->app->limitador();
        $claveLimite = 'lead:ip:' . $ipHash;
        if (!$errores && $limitador->superado($claveLimite, self::MAX_LEADS_IP_HORA, 3600, $ahora)) {
            $errores['general'] = 'Hemos recibido varias solicitudes desde tu conexión. Si necesitas algo más, escríbenos.';
        }

        if (isset($errores['servicio']) || isset($errores['cp']) || isset($errores['tipo_cliente'])) {
            return $this->render('paso1', ['datos' => $datos, 'errores' => $errores, 'meta' => $meta]);
        }

        $cobertura = $this->resolver($datos, $meta);
        $version = Consentimiento::version($cobertura['modo'], $cobertura['profesional']);
        if (!$errores && ($in['consentimiento_version'] ?? '') !== $version) {
            $errores['acepto'] = 'La disponibilidad en tu zona ha cambiado. Lee el nuevo texto y vuelve a marcar la casilla.';
        }
        if ($errores) {
            return $this->paso2($datos, $errores, $meta, $ahora);
        }

        $id = $leads->crear($datos, $cobertura, $meta + [
            'consentimiento_version' => $version,
            'ip_hash' => $ipHash,
            'origen' => 'web',
        ]);
        $limitador->registrar($claveLimite, $ahora);

        $lead = $leads->obtener($id);
        $notificaciones = $this->app->notificaciones();
        // Empresa del directorio que ha confirmado su email: le llega la solicitud directamente.
        $empresa = $cobertura['empresa'] ? $cobertura['profesional'] : null;
        if ($empresa !== null && $empresa['verificada'] && filter_var($empresa['email'], FILTER_VALIDATE_EMAIL)
            && $notificaciones->enviarAProfesional($lead, $empresa)) {
            $leads->marcarEnviado($id, (int) $empresa['id']);
            $lead = $leads->obtener($id);
        }
        $notificaciones->avisarTitular($lead, $ahora);
        $notificaciones->confirmarCliente($lead, $cobertura['profesional']);

        Http::redirigir('/presupuesto/?ok=1&m=' . ($cobertura['empresa'] ? 'empresa' : $cobertura['modo']));
    }

    /**
     * Quién atenderá la solicitud: la empresa elegida en el directorio (si está publicada) o, si no,
     * el profesional que cubre la provincia y el servicio.
     * @return array{modo: string, profesional: ?array, empresa: bool}
     */
    private function resolver(array $datos, array $meta): array
    {
        $servicioRuta = Leads::servicioEfectivo($datos['servicio'], $datos['tipo_cliente']);
        $empresa = $meta['empresa'] !== '' ? $this->app->directorio()->publicada($meta['empresa']) : null;
        // Solo si esa empresa ofrece el servicio pedido; si no, se busca profesional como siempre (y se avisa).
        if ($empresa !== null && in_array($servicioRuta, $empresa['servicios'], true)) {
            return ['modo' => 'activo', 'profesional' => $empresa, 'empresa' => true];
        }
        return $this->app->cobertura()->resolver($datos['provincia'], $servicioRuta) + ['empresa' => false];
    }

    private function paso2(array $datos, array $errores, array $meta, int $ahora): string
    {
        $cobertura = $this->resolver($datos, $meta);
        $nombreSitio = $this->app->config('site')['nombre'];
        return $this->render('paso2', [
            'datos' => $datos,
            'errores' => $errores,
            'meta' => $meta,
            'modo' => $cobertura['modo'],
            'profesional' => $cobertura['profesional'],
            'empresa_elegida' => $cobertura['empresa'],
            'provincia_nombre' => $this->app->provincias()->nombre($datos['provincia']),
            'consentimiento' => Consentimiento::texto($cobertura['modo'], $cobertura['profesional'], $nombreSitio),
            'consentimiento_version' => Consentimiento::version($cobertura['modo'], $cobertura['profesional']),
            'empresa_no_disponible' => $meta['empresa'] !== '' && !$cobertura['empresa'],
            'token' => $this->app->tokenFormulario()->emitir($ahora),
        ]);
    }

    /** Origen del lead: página del formulario, referrer externo y parámetros de campaña. */
    private function meta(array $in): array
    {
        $utm = [];
        foreach (self::CAMPOS_UTM as $campo) {
            $valor = preg_replace('/[^\w\-. ]+/u', '', (string) ($in[$campo] ?? ''));
            if ($valor !== '') {
                $utm[$campo] = mb_substr($valor, 0, 100);
            }
        }
        $ref = (string) ($in['ref'] ?? '');
        $partes = $ref !== '' ? parse_url($ref) : false;
        $referrer = is_array($partes) && isset($partes['host'])
            ? ($partes['scheme'] ?? 'https') . '://' . $partes['host'] . ($partes['path'] ?? '')
            : '';
        return [
            'empresa' => is_string($in['empresa'] ?? null) && preg_match('/^[a-z0-9-]{1,90}$/', $in['empresa']) ? $in['empresa'] : '',
            'pagina_origen' => Http::rutaInterna((string) ($in['origen'] ?? '')),
            'referrer' => mb_substr($referrer, 0, 300),
            'utm' => $utm,
        ];
    }

    private function render(string $paso, array $vars): string
    {
        $empresa = !empty($vars['meta']['empresa']) ? $this->app->directorio()->publicada($vars['meta']['empresa']) : null;
        return $this->app->twig()->render('pages/presupuesto.twig', $vars + [
            'empresa_destino' => $empresa,
            'paso' => $paso,
            'pagina' => [
                'titulo' => 'Pide presupuesto',
                'seo_title' => 'Pide presupuesto sin compromiso | AguaSinCal',
                'descripcion' => 'Solicita presupuesto gratuito para descalcificador, ósmosis inversa, filtros o fuentes de agua.',
                'ruta' => '/presupuesto/',
                'indexable' => false,
            ],
            'tipos_por_servicio' => array_map(fn (array $s) => $s['clientes'], $this->app->servicios()),
        ]);
    }
}
