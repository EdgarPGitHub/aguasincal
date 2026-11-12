<?php
declare(strict_types=1);

namespace AguaSinCal\Controladores;

use AguaSinCal\App;
use AguaSinCal\Directorio;
use AguaSinCal\Cobertura;
use AguaSinCal\Consentimiento;
use AguaSinCal\Http;
use AguaSinCal\Leads;
use AguaSinCal\Profesionales;

/**
 * Panel de gestión: leads, cobertura (provincia × servicio) y profesionales.
 * Acceso con email + contraseña + código 2FA. Todas las acciones POST llevan token CSRF.
 */
final class Panel
{
    private const INACTIVIDAD = 7200;
    private const DURACION_MAX = 43200;

    private string $base;
    private ?array $usuario = null;

    public function __construct(private readonly App $app)
    {
        $this->base = $app->rutaPanel();
    }

    public function manejar(): void
    {
        Http::cabeceras(panel: true);
        $this->iniciarSesion();
        $ruta = (string) ($_GET['r'] ?? 'leads');

        if (in_array($ruta, ['login', 'codigo', 'alta-2fa'], true) || !$this->autenticado()) {
            echo $this->acceso($ruta);
            return;
        }
        if (Http::esPost() && !$this->csrfValido()) {
            http_response_code(400);
            echo $this->render('panel/mensaje.twig', ['mensaje' => 'La sesión ha caducado. Vuelve a intentarlo.']);
            return;
        }

        switch ($ruta) {
            case 'lead':
                echo $this->lead((int) ($_GET['id'] ?? 0));
                break;
            case 'nuevo':
                echo $this->nuevoLead();
                break;
            case 'exportar':
                $this->exportar();
                break;
            case 'cobertura':
                echo $this->cobertura();
                break;
            case 'profesionales':
                $filtro = is_string($_GET['ficha'] ?? null) && isset(Directorio::FICHAS[$_GET['ficha']]) ? $_GET['ficha'] : null;
                $todas = array_map(fn ($p) => $this->app->directorio()->completar($p), $this->app->profesionales()->todos());
                echo $this->render('panel/profesionales.twig', [
                    'profesionales' => $filtro ? array_values(array_filter($todas, fn ($p) => $p['ficha'] === $filtro)) : $todas,
                    'filtro' => $filtro,
                    'pendientes' => count(array_filter($todas, fn ($p) => $p['ficha'] === 'pendiente')),
                    'fichas' => Directorio::FICHAS,
                    'hoy' => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Madrid')))->format('Y-m-d'),
                ]);
                break;
            case 'profesional':
                echo $this->profesional(isset($_GET['id']) ? (int) $_GET['id'] : null);
                break;
            case 'clics':
                echo $this->clics();
                break;
            case 'clave':
                echo $this->clave();
                break;
            case 'salir':
                if (Http::esPost()) {
                    $_SESSION = [];
                    session_destroy();
                }
                Http::redirigir($this->base . '?r=login');
            default:
                echo $this->listado();
        }
    }

    // ---------------------------------------------------------------- acceso

    private function acceso(string $ruta): string
    {
        $auth = $this->app->auth();
        $ahora = time();
        $this->asegurarAdminInicial();
        $ipHash = Http::ipHash($this->app->clave());

        // Volver a la pantalla de login descarta un acceso a medias.
        if ($ruta === 'login' && !Http::esPost()) {
            unset($_SESSION['pendiente']);
        }
        // Paso 2: código TOTP (o alta del 2FA la primera vez).
        $pendiente = $_SESSION['pendiente'] ?? null;
        if ($pendiente !== null && ($pendiente['hasta'] ?? 0) < $ahora) {
            unset($_SESSION['pendiente']);
            $pendiente = null;
        }
        if ($pendiente !== null) {
            $usuario = $auth->obtener((int) $pendiente['id']);
            if ($usuario === null) {
                unset($_SESSION['pendiente']);
                Http::redirigir($this->base . '?r=login');
            }
            if (!$usuario['totp_activo']) {
                $secreto = $_SESSION['pendiente']['secreto'] ??= $auth->nuevoSecreto();
                $error = '';
                if (Http::esPost() && $this->csrfValido()) {
                    if ($auth->verificarCodigo($secreto, (string) ($_POST['codigo'] ?? ''), $ipHash, $ahora)) {
                        $auth->activarTotp((int) $usuario['id'], $secreto);
                        $this->entrar($usuario);
                    }
                    $error = 'El código no es correcto. Revisa la hora del móvil y vuelve a probar.';
                }
                return $this->render('panel/alta-2fa.twig', [
                    'qr' => $auth->qrDataUri($usuario['email'], $secreto),
                    'secreto' => $secreto,
                    'error' => $error,
                ]);
            }
            $error = '';
            if (Http::esPost() && $this->csrfValido()) {
                if ($auth->verificarCodigo($usuario['totp_secreto'], (string) ($_POST['codigo'] ?? ''), $ipHash, $ahora)) {
                    $this->entrar($usuario);
                }
                $error = 'Código incorrecto o demasiados intentos.';
            }
            return $this->render('panel/codigo.twig', ['error' => $error]);
        }

        // Paso 1: email y contraseña.
        $error = '';
        if (Http::esPost() && $this->csrfValido()) {
            [$usuario, $error] = $auth->credenciales((string) ($_POST['email'] ?? ''), (string) ($_POST['clave'] ?? ''), $ipHash, $ahora);
            if ($usuario !== null) {
                session_regenerate_id(true);
                $_SESSION['pendiente'] = ['id' => (int) $usuario['id'], 'hasta' => $ahora + 600];
                Http::redirigir($this->base . '?r=codigo');
            }
        }
        if ($ruta !== 'login' && !Http::esPost()) {
            Http::redirigir($this->base . '?r=login');
        }
        return $this->render('panel/login.twig', ['error' => $error]);
    }

    /** Crea el primer usuario a partir de config.local.php (admin_inicial) si todavía no hay ninguno. */
    private function asegurarAdminInicial(): void
    {
        $email = (string) $this->app->local('admin_inicial.email', '');
        $hash = (string) $this->app->local('admin_inicial.hash', '');
        $auth = $this->app->auth();
        if ($email !== '' && $hash !== '' && !$auth->hayUsuarios()) {
            $auth->crearUsuarioConHash($email, $hash);
        }
    }

    private function entrar(array $usuario): never
    {
        session_regenerate_id(true);
        unset($_SESSION['pendiente']);
        $_SESSION['usuario'] = (int) $usuario['id'];
        $_SESSION['inicio'] = time();
        $_SESSION['actividad'] = time();
        $this->app->auth()->registrarAcceso((int) $usuario['id']);
        Http::redirigir($this->base);
    }

    private function autenticado(): bool
    {
        $id = $_SESSION['usuario'] ?? null;
        $ahora = time();
        if ($id === null
            || ($ahora - (int) ($_SESSION['actividad'] ?? 0)) > self::INACTIVIDAD
            || ($ahora - (int) ($_SESSION['inicio'] ?? 0)) > self::DURACION_MAX) {
            unset($_SESSION['usuario']);
            return false;
        }
        $this->usuario = $this->app->auth()->obtener((int) $id);
        if ($this->usuario === null) {
            return false;
        }
        $_SESSION['actividad'] = $ahora;
        return true;
    }

    private function iniciarSesion(): void
    {
        session_name('asc_panel');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $this->base,
            'secure' => Http::esHttps(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        $dir = $this->app->datos . '/sesiones';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        session_save_path($dir);
        session_start();
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    private function csrfValido(): bool
    {
        return is_string($_POST['csrf'] ?? null) && hash_equals((string) $_SESSION['csrf'], $_POST['csrf']);
    }

    // ---------------------------------------------------------------- leads

    private function listado(): string
    {
        $leads = $this->app->leads();
        $filtros = array_intersect_key($_GET, array_flip(['provincia', 'servicio', 'estado', 'modo', 'mes', 'q']));
        $filtros = array_map(fn ($v) => is_string($v) ? trim($v) : '', $filtros);
        $pagina = max(1, (int) ($_GET['p'] ?? 1));
        $total = $leads->contar($filtros);
        $mes = gmdate('Y-m');
        return $this->render('panel/leads.twig', [
            'leads' => $leads->listar($filtros, $pagina, 50),
            'total' => $total,
            'pagina_actual' => $pagina,
            'paginas' => max(1, (int) ceil($total / 50)),
            'filtros' => $filtros + ['provincia' => '', 'servicio' => '', 'estado' => '', 'modo' => '', 'mes' => '', 'q' => ''],
            'resumen' => $leads->resumenMes($mes),
            'mes' => $mes,
        ]);
    }

    private function lead(int $id): string
    {
        $leads = $this->app->leads();
        $lead = $leads->obtener($id);
        if ($lead === null) {
            http_response_code(404);
            return $this->render('panel/mensaje.twig', ['mensaje' => 'Lead no encontrado.']);
        }
        $aviso = '';
        $error = '';
        if (Http::esPost()) {
            $accion = (string) ($_POST['accion'] ?? '');
            switch ($accion) {
                case 'estado':
                    $leads->cambiarEstado($id, (string) ($_POST['estado'] ?? ''), (string) ($_POST['motivo'] ?? ''));
                    $aviso = 'Estado actualizado.';
                    break;
                case 'notas':
                    $leads->guardarNotas($id, (string) ($_POST['notas'] ?? ''));
                    $aviso = 'Notas guardadas.';
                    break;
                case 'enviar':
                    $profesional = $this->app->profesionales()->obtener((int) ($_POST['profesional_id'] ?? 0));
                    if ($profesional === null || !$profesional['activo'] || $profesional['email'] === '') {
                        $error = 'Elige un profesional activo con email.';
                    } elseif ($lead['modo'] === 'espera' && empty($_POST['reconfirmado'])) {
                        $error = 'Este lead se recogió en lista de espera: antes de enviarlo, confirma que has hablado con la persona y acepta que pasemos sus datos.';
                    } elseif ($this->app->notificaciones()->enviarAProfesional($lead, $profesional)) {
                        $leads->marcarEnviado($id, (int) $profesional['id']);
                        $aviso = 'Enviado a ' . $profesional['nombre_comercial'] . '.';
                    } else {
                        $error = 'No se ha podido enviar el email.';
                    }
                    break;
                case 'anonimizar':
                    $leads->anonimizar($id);
                    $aviso = 'Datos personales borrados.';
                    break;
            }
            $lead = $leads->obtener($id);
        }
        $whatsapp = '';
        if ($lead['profesional_id'] && $lead['anonimizado'] === null) {
            $pro = $this->app->profesionales()->obtener((int) $lead['profesional_id']);
            if ($pro && $pro['whatsapp'] !== '') {
                $whatsapp = 'https://wa.me/' . $pro['whatsapp'] . '?text=' . rawurlencode($this->app->notificaciones()->textoWhatsapp($lead));
            }
        }
        return $this->render('panel/lead.twig', [
            'lead' => $lead,
            'utm' => $lead['utm'] !== '' ? (array) json_decode($lead['utm'], true) : [],
            'profesionales' => $this->app->profesionales()->todos(true),
            'motivos' => Leads::MOTIVOS_INVALIDO,
            'whatsapp_url' => $whatsapp,
            'aviso' => $aviso,
            'error' => $error,
        ]);
    }

    /** Alta manual de leads que entran por teléfono o WhatsApp. */
    private function nuevoLead(): string
    {
        $datos = ['servicio' => '', 'cp' => '', 'tipo_cliente' => 'particular', 'nombre' => '', 'telefono' => '', 'email' => '',
            'municipio' => '', 'mensaje' => '', 'modalidad' => '', 'personas' => null, 'origen' => 'telefono'];
        $errores = [];
        if (Http::esPost()) {
            $leads = $this->app->leads();
            [$datos, $errores] = $leads->validarPaso2($_POST + ['acepto' => '1']);
            $origen = in_array($_POST['origen'] ?? '', ['telefono', 'whatsapp'], true) ? $_POST['origen'] : 'telefono';
            $datos['origen'] = $origen;
            if (!$errores) {
                $servicioRuta = Leads::servicioEfectivo($datos['servicio'], $datos['tipo_cliente']);
                $cobertura = $this->app->cobertura()->resolver($datos['provincia'], $servicioRuta);
                $id = $leads->crear($datos, $cobertura, [
                    'origen' => $origen,
                    'consentimiento_version' => 'verbal-' . Consentimiento::version($cobertura['modo']),
                    'pagina_origen' => '',
                ]);
                Http::redirigir($this->base . '?r=lead&id=' . $id);
            }
        }
        return $this->render('panel/nuevo.twig', ['datos' => $datos, 'errores' => $errores]);
    }

    private function exportar(): void
    {
        $mes = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['mes'] ?? '')) ? $_GET['mes'] : gmdate('Y-m');
        $profesionalId = !empty($_GET['profesional']) ? (int) $_GET['profesional'] : null;
        $filas = $this->app->leads()->exportar($mes, $profesionalId);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="leads-' . $mes . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['id', 'creado', 'enviado', 'servicio', 'modalidad', 'tipo_cliente', 'cp', 'provincia', 'municipio', 'nombre', 'telefono', 'estado', 'motivo_invalido', 'profesional', 'precio_lead'], ';', '"', '');
        foreach ($filas as $fila) {
            // Evita inyección de fórmulas al abrir el CSV en Excel.
            $fila = array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v, $fila);
            fputcsv($out, $fila, ';', '"', '');
        }
        fclose($out);
    }

    // ---------------------------------------------------------------- cobertura y profesionales

    private function cobertura(): string
    {
        $cobertura = $this->app->cobertura();
        $aviso = '';
        $error = '';
        if (Http::esPost()) {
            $provincia = (string) ($_POST['provincia'] ?? '');
            if (!$this->app->provincias()->existe($provincia)) {
                $error = 'Provincia no válida.';
            } else {
                foreach (array_keys($this->app->servicios()) as $servicio) {
                    $modo = (string) ($_POST['modo'][$servicio] ?? 'espera');
                    $pro = (int) ($_POST['profesional'][$servicio] ?? 0);
                    if ($modo === 'activo' && $pro === 0) {
                        $error = 'Para activar un servicio elige un profesional. Los demás cambios se han guardado.';
                        $modo = 'espera';
                    }
                    $cobertura->guardar($provincia, $servicio, in_array($modo, Cobertura::MODOS, true) ? $modo : 'espera', $pro ?: null);
                }
                $aviso = 'Cobertura de ' . $this->app->provincias()->nombre($provincia) . ' guardada.';
            }
        }
        return $this->render('panel/cobertura.twig', [
            'mapa' => $cobertura->mapa(),
            'profesionales' => $this->app->profesionales()->todos(),
            'demanda' => $this->app->leads()->demandaEnEspera(90, time()),
            'editar' => (string) ($_GET['provincia'] ?? $_POST['provincia'] ?? ''),
            'aviso' => $aviso,
            'error' => $error,
        ]);
    }

    /** Alta y edición de una empresa: datos internos (leads) y ficha pública del directorio. */
    private function profesional(?int $id): string
    {
        $repo = $this->app->profesionales();
        $directorio = $this->app->directorio();
        $profesional = $id !== null ? $directorio->porId($id) : null;
        if ($id !== null && $profesional === null) {
            http_response_code(404);
            return $this->render('panel/mensaje.twig', ['mensaje' => 'Empresa no encontrada.']);
        }
        $errores = [];
        $sugerencias = [];
        $datos = $profesional ?? ['nombre_comercial' => '', 'razon_social' => '', 'email' => '', 'whatsapp' => '', 'precio_lead' => 0, 'notas' => '',
            'activo' => 1, 'servicios' => [], 'radio_km' => 25, 'origen' => 'propia', 'ficha' => 'oculta', 'destacada_hasta' => '', 'prioridad' => 0,
            'verificada' => 0, 'descripcion' => '', 'web' => '', 'telefono' => '', 'base' => null];
        $datos['municipio_texto'] ??= $profesional['base']['nombre'] ?? '';
        $datos['provincia'] ??= $profesional['base']['provincia'] ?? '';
        if (Http::esPost()) {
            [$internos, $errores] = Profesionales::validar($_POST);
            $texto = fn (string $clave) => is_string($_POST[$clave] ?? null) ? $_POST[$clave] : '';
            $fecha = \DateTimeImmutable::createFromFormat('!Y-m-d', $texto('destacada_hasta'));
            $admin = [
                'origen' => isset(Directorio::ORIGENES[$texto('origen')]) ? $texto('origen') : 'propia',
                'ficha' => isset(Directorio::FICHAS[$texto('ficha')]) ? $texto('ficha') : 'oculta',
                'destacada_hasta' => $fecha && $fecha->format('Y-m-d') === $texto('destacada_hasta') ? $texto('destacada_hasta') : null,
                'prioridad' => max(-10, min(10, (int) ($_POST['prioridad'] ?? 0))),
                'verificada' => !empty($_POST['verificada']) ? 1 : 0,
            ];
            // Un email nuevo no está confirmado: la empresa tendrá que guardar la ficha con su enlace.
            if ($profesional !== null && mb_strtolower(trim((string) $profesional['email'])) !== mb_strtolower(trim($texto('email')))) {
                $admin['verificada'] = 0;
            }
            // La ficha pública solo se valida si se publica o si se ha rellenado el municipio.
            $conFicha = $admin['ficha'] !== 'oculta' || trim($texto('municipio')) !== '' || $texto('municipio_ine') !== '';
            $publicos = null;
            if ($conFicha) {
                [$publicos, $erroresFicha, $sugerencias] = $directorio->validarFicha($_POST, false);
                unset($erroresFicha['email']);
                $errores += $erroresFicha;
            }
            if (!$errores) {
                $nuevoId = $repo->guardar($id, $internos);
                if ($publicos !== null) {
                    $directorio->actualizarFicha($nuevoId, $publicos);
                }
                $directorio->guardarAdmin($nuevoId, $admin);
                Http::redirigir($this->base . '?r=profesionales');
            }
            $datos = $internos + $admin + ($publicos ?? []) + ['servicios' => (array) ($_POST['servicios'] ?? []), 'municipio_texto' => (string) ($_POST['municipio'] ?? ''),
                'provincia' => (string) ($_POST['provincia'] ?? ''), 'descripcion' => (string) ($_POST['descripcion'] ?? ''), 'web' => (string) ($_POST['web'] ?? ''),
                'telefono' => (string) ($_POST['telefono'] ?? ''), 'radio_km' => (int) ($_POST['radio_km'] ?? 25), 'slug' => $profesional['slug'] ?? null];
        }
        return $this->render('panel/profesional.twig', [
            'datos' => $datos, 'id' => $id, 'errores' => $errores, 'sugerencias' => $sugerencias,
            'servicios_lista' => $this->app->servicios(), 'radios' => Directorio::RADIOS, 'provincias' => $this->app->provincias()->todas(),
            'origenes' => Directorio::ORIGENES, 'fichas' => Directorio::FICHAS,
        ]);
    }

    private function clics(): string
    {
        $desde = gmdate('Y-m-d', time() - 30 * 86400);
        $st = $this->app->db()->prepare('SELECT pagina, tipo, SUM(total) AS total FROM eventos WHERE dia >= ? GROUP BY pagina, tipo ORDER BY total DESC LIMIT 100');
        $st->execute([$desde]);
        return $this->render('panel/clics.twig', ['filas' => $st->fetchAll()]);
    }

    private function clave(): string
    {
        $error = '';
        $aviso = '';
        if (Http::esPost()) {
            $nueva = (string) ($_POST['nueva'] ?? '');
            if (!password_verify((string) ($_POST['actual'] ?? ''), $this->usuario['password_hash'])) {
                $error = 'La contraseña actual no es correcta.';
            } elseif ($nueva !== (string) ($_POST['repetir'] ?? '')) {
                $error = 'Las contraseñas nuevas no coinciden.';
            } else {
                try {
                    $this->app->auth()->cambiarClave((int) $this->usuario['id'], $nueva);
                    $aviso = 'Contraseña cambiada.';
                } catch (\InvalidArgumentException $e) {
                    $error = $e->getMessage();
                }
            }
        }
        return $this->render('panel/clave.twig', ['error' => $error, 'aviso' => $aviso]);
    }

    private function render(string $plantilla, array $vars = []): string
    {
        return $this->app->twig()->render($plantilla, $vars + [
            'base' => $this->base,
            'csrf' => $_SESSION['csrf'] ?? '',
            'usuario' => $this->usuario,
            'estados' => Leads::ESTADOS,
            'ruta' => (string) ($_GET['r'] ?? 'leads'),
        ]);
    }
}
