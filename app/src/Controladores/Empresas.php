<?php
declare(strict_types=1);

namespace AguaSinCal\Controladores;

use AguaSinCal\App;
use AguaSinCal\Directorio;
use AguaSinCal\Http;

/**
 * Directorio público de empresas (páginas PHP; el resto de la web es estática).
 *
 *  /empresas/                      índice + buscador por municipio
 *  /empresas/{provincia}/{municipio}/  empresas que trabajan en ese municipio, por cercanía
 *  /empresa/{slug}/                ficha de una empresa
 *  /empresas/alta/                 alta gratuita (queda pendiente de revisión)
 *  /empresas/acceso/               pedir el enlace para editar la ficha (o reclamar una ficha)
 *  /empresas/editar/?t=…           editar la ficha con el enlace recibido por email
 *  /sitemap-empresas.xml           fichas y municipios indexables
 */
final class Empresas
{
    public const MAX_ALTAS_IP_DIA = 3;
    public const CADUCIDAD_ENLACE_EDICION = 3 * 86400;
    private const CAMPO_TRAMPA = 'sitio_web_2';

    private Directorio $directorio;

    public function __construct(private readonly App $app)
    {
        $this->directorio = $app->directorio();
    }

    public function manejar(): string
    {
        Http::cabeceras();
        $ruta = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $partes = array_values(array_filter(explode('/', $ruta), fn ($p) => $p !== ''));

        if ($ruta === '/sitemap-empresas.xml') {
            return $this->sitemap();
        }
        if (($partes[0] ?? '') === 'empresa' && count($partes) === 2) {
            return $this->ficha($partes[1]);
        }
        if (($partes[0] ?? '') !== 'empresas') {
            return $this->noEncontrada();
        }
        return match (true) {
            count($partes) === 1 => $this->indice(),
            $partes[1] === 'alta' => $this->alta(),
            $partes[1] === 'acceso' => $this->acceso(),
            $partes[1] === 'editar' => $this->editar(),
            count($partes) === 3 => $this->municipio($partes[1], $partes[2]),
            default => $this->noEncontrada(),
        };
    }

    // ---------------------------------------------------------------------------------------------- páginas

    private function indice(): string
    {
        $q = trim((string) ($_GET['q'] ?? ''));
        $resultados = [];
        if ($q !== '') {
            $resultados = $this->app->municipios()->buscar($q, null, 12);
            if (count($resultados) === 1 || ($resultados && \AguaSinCal\Municipios::normalizar($resultados[0]['nombre']) === \AguaSinCal\Municipios::normalizar($q))) {
                Http::redirigir($this->rutaMunicipio($resultados[0]));
            }
        }
        return $this->render('pages/empresas.twig', [
            'pagina' => $this->pagina('Empresas de tratamiento de agua', '/empresas/', 'Directorio de empresas de descalcificadores, ósmosis inversa, filtros y fuentes de agua. Busca las que trabajan en tu municipio y pide presupuesto.', $q === '', [['nombre' => 'Empresas', 'ruta' => '/empresas/']]),
            'q' => $q,
            'resultados' => array_map(fn ($m) => $m + ['ruta' => $this->rutaMunicipio($m)], $resultados),
            'bases' => array_map(fn ($lista) => array_map(fn ($b) => $b + ['ruta' => $this->rutaMunicipio($b['m'])], $lista), $this->directorio->bases()),
            'provincias' => $this->app->provincias()->todas(),
        ]);
    }

    private function municipio(string $provSlug, string $muniSlug): string
    {
        $codigo = $this->app->provincias()->porSlug($provSlug);
        $m = $codigo !== null ? $this->app->municipios()->porSlug($codigo, $muniSlug) : null;
        if ($m === null) {
            return $this->noEncontrada();
        }
        $servicio = (string) ($_GET['servicio'] ?? '');
        $servicio = isset($this->app->servicios()[$servicio]) ? $servicio : null;
        $empresas = $this->directorio->para($m, $this->hoy(), $servicio);
        $ruta = $this->rutaMunicipio($m);
        $prov = $this->app->provincias()->nombre($m['provincia']);
        $indexable = $servicio === null && count($empresas) >= Directorio::MIN_INDEXABLE;
        $migas = [['nombre' => 'Empresas', 'ruta' => '/empresas/'], ['nombre' => $m['nombre'], 'ruta' => $ruta]];
        $pagina = $this->pagina(
            'Empresas de tratamiento de agua en ' . $m['nombre'],
            $ruta,
            sprintf('Empresas de descalcificadores, ósmosis y filtros de agua que trabajan en %s (%s), ordenadas por cercanía. Pide presupuesto gratis.', $m['nombre'], $prov),
            $indexable,
            $migas,
        );
        $pagina['entradilla'] = $empresas
            ? sprintf('%d %s en %s o alrededores, de más cerca a más lejos. Las destacadas aparecen primero.', count($empresas), count($empresas) === 1 ? 'empresa trabaja' : 'empresas trabajan', $m['nombre'])
            : sprintf('Todavía no tenemos empresas en %s. Déjanos tu solicitud y buscaremos un profesional de la zona.', $m['nombre']);
        return $this->render('pages/empresas-municipio.twig', [
            'pagina' => $pagina,
            'm' => $m,
            'provincia_nombre' => $prov,
            'empresas' => $empresas,
            'servicio' => $servicio,
            'dureza' => $this->durezaPublicada($m),
        ]);
    }

    private function ficha(string $slug): string
    {
        $e = $this->directorio->publicada($slug);
        if ($e === null) {
            return $this->noEncontrada();
        }
        $hoy = $this->hoy();
        $e['destacada'] = Directorio::destacada($e, $hoy);
        $ruta = '/empresa/' . $e['slug'] . '/';
        $migas = [['nombre' => 'Empresas', 'ruta' => '/empresas/']];
        if ($e['base']) {
            $migas[] = ['nombre' => $e['base']['nombre'], 'ruta' => $this->rutaMunicipio($e['base'])];
        }
        $migas[] = ['nombre' => $e['nombre_comercial'], 'ruta' => $ruta];
        $lugar = $e['base'] ? ' en ' . $e['base']['nombre'] : '';
        $pagina = $this->pagina(
            $e['nombre_comercial'],
            $ruta,
            mb_substr(trim($e['descripcion']) ?: sprintf('%s: %s%s. Pide presupuesto sin compromiso.', $e['nombre_comercial'], $this->listaServicios($e['servicios']), $lugar), 0, 155),
            Directorio::indexable($e),
            $migas,
        );
        $pagina['seo_title'] = $e['nombre_comercial'] . $lugar . ' | AguaSinCal';
        $negocio = ['@context' => 'https://schema.org', '@type' => 'LocalBusiness', 'name' => $e['nombre_comercial'], 'url' => $this->app->url($ruta)];
        if ($e['base']) {
            $negocio['address'] = ['@type' => 'PostalAddress', 'addressLocality' => $e['base']['nombre'], 'addressRegion' => $this->app->provincias()->nombre($e['base']['provincia']), 'addressCountry' => 'ES'];
        }
        if (trim($e['descripcion']) !== '') {
            $negocio['description'] = mb_substr(trim($e['descripcion']), 0, 300);
        }
        if ($e['destacada'] && $e['telefono'] !== '') {
            $negocio['telephone'] = $e['telefono'];
        }
        $pagina['schema'][] = $negocio;
        return $this->render('pages/empresa.twig', [
            'pagina' => $pagina,
            'e' => $e,
            'provincia_nombre' => $e['base'] ? $this->app->provincias()->nombre($e['base']['provincia']) : '',
            'ruta_municipio' => $e['base'] ? $this->rutaMunicipio($e['base']) : null,
        ]);
    }

    private function alta(): string
    {
        $vars = ['datos' => ['servicios' => [], 'radio_km' => 25, 'provincia' => ''], 'errores' => [], 'sugerencias' => [], 'hecho' => false];
        $ahora = time();
        if (Http::esPost()) {
            if (trim((string) ($_POST[self::CAMPO_TRAMPA] ?? '')) !== '') {
                return $this->renderAlta(['hecho' => true] + $vars, $ahora);
            }
            [$datos, $errores, $sugerencias] = $this->directorio->validarFicha($_POST, true);
            if (empty($_POST['acepto'])) {
                $errores['acepto'] = 'Para publicar la ficha necesitamos que aceptes las condiciones.';
            }
            $errores += $this->comprobarEnvio($ahora, 'alta-empresa', self::MAX_ALTAS_IP_DIA, 86400);
            if (!$errores) {
                $id = $this->directorio->alta($datos);
                $this->app->limitador()->registrar('alta-empresa:' . Http::ipHash($this->app->clave()), $ahora);
                $empresa = $this->directorio->porId($id);
                $avisos = $this->app->notificaciones();
                $avisos->enlaceEdicionEmpresa($empresa, $ahora, true);
                $avisos->avisarTitularEmpresa($empresa, 'alta', $ahora);
                return $this->renderAlta(['hecho' => true] + $vars, $ahora);
            }
            $vars = ['datos' => $datos, 'errores' => $errores, 'sugerencias' => $sugerencias] + $vars;
        }
        return $this->renderAlta($vars, $ahora);
    }

    /** Pedir el enlace de edición. Si la ficha no tiene ese email, lo decide el titular (reclamación). */
    private function acceso(): string
    {
        $empresa = isset($_REQUEST['empresa']) ? $this->directorio->publicada((string) $_REQUEST['empresa']) : null;
        $vars = ['empresa' => $empresa, 'email' => '', 'errores' => [], 'hecho' => false];
        $ahora = time();
        if (Http::esPost()) {
            $email = trim((string) ($_POST['email'] ?? ''));
            $vars['email'] = $email;
            $errores = [];
            if (trim((string) ($_POST[self::CAMPO_TRAMPA] ?? '')) !== '') {
                return $this->renderAcceso(['hecho' => true] + $vars, $ahora);
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errores['email'] = 'Escribe un email válido.';
            }
            $errores += $this->comprobarEnvio($ahora, 'acceso-empresa', 5, 3600);
            if (!$errores) {
                $this->app->limitador()->registrar('acceso-empresa:' . Http::ipHash($this->app->clave()), $ahora);
                $avisos = $this->app->notificaciones();
                $suyas = $this->directorio->porEmail($email);
                if ($empresa !== null && !in_array($empresa['id'], array_column($suyas, 'id'), true)) {
                    // Reclamación de una ficha que no tiene ese email: la revisa el titular.
                    $avisos->avisarTitularEmpresa($empresa, 'reclamacion', $ahora, ['email' => $email, 'mensaje' => mb_substr(trim((string) ($_POST['mensaje'] ?? '')), 0, 500)]);
                } else {
                    foreach ($suyas as $e) {
                        $avisos->enlaceEdicionEmpresa($e, $ahora, false);
                    }
                }
                // Misma respuesta exista o no: no se revela qué emails están dados de alta.
                return $this->renderAcceso(['hecho' => true] + $vars, $ahora);
            }
            $vars['errores'] = $errores;
        }
        return $this->renderAcceso($vars, $ahora);
    }

    private function editar(): string
    {
        Http::cabeceras(panel: true);
        header('Referrer-Policy: no-referrer'); // el enlace lleva el token: que no salga en el Referer
        $token = (string) ($_POST['t'] ?? $_GET['t'] ?? '');
        $datosToken = $this->app->firma()->verificar($token, time());
        $empresa = ($datosToken['a'] ?? '') === 'editar_empresa' ? $this->directorio->porId((int) $datosToken['e']) : null;
        if ($empresa === null) {
            http_response_code(403);
            return $this->render('pages/empresa-acceso.twig', [
                'pagina' => $this->pagina('Editar ficha', '/empresas/editar/', '', false, []),
                'empresa' => null, 'email' => '', 'hecho' => false, 'token' => '',
                'errores' => ['general' => 'El enlace no es válido o ha caducado. Pide uno nuevo.'],
            ]);
        }
        // Abrir el enlace demuestra que el email es suyo.
        if (!$empresa['verificada']) {
            $this->directorio->marcarVerificada((int) $empresa['id']);
            $empresa['verificada'] = 1;
        }
        $vars = [
            'pagina' => $this->pagina('Editar la ficha de ' . $empresa['nombre_comercial'], '/empresas/editar/', '', false, []),
            'token' => $token,
            'empresa' => $empresa,
            'datos' => $empresa + ['municipio_texto' => $empresa['base']['nombre'] ?? '', 'provincia' => $empresa['base']['provincia'] ?? ''],
            'errores' => [],
            'sugerencias' => [],
            'hecho' => false,
            'servicios_lista' => $this->app->servicios(),
            'radios' => Directorio::RADIOS,
            'provincias' => $this->app->provincias()->todas(),
        ];
        if (Http::esPost()) {
            [$datos, $errores, $sugerencias] = $this->directorio->validarFicha($_POST + ['email' => $empresa['email']], false);
            if (!$errores) {
                $this->directorio->actualizarFicha((int) $empresa['id'], $datos);
                $this->app->notificaciones()->avisarTitularEmpresa($this->directorio->porId((int) $empresa['id']), 'cambios', time());
                $vars['hecho'] = true;
                $vars['empresa'] = $this->directorio->porId((int) $empresa['id']);
            }
            $vars['datos'] = $datos + ['id' => $empresa['id']];
            $vars['errores'] = $errores;
            $vars['sugerencias'] = $sugerencias;
        }
        return $this->render('pages/empresa-editar.twig', $vars);
    }

    private function sitemap(): string
    {
        header('Content-Type: application/xml; charset=UTF-8');
        header('Cache-Control: public, max-age=3600');
        $urls = [];
        $hoy = $this->hoy();
        $municipiosVistos = [];
        foreach ($this->directorio->publicadas() as $e) {
            if (Directorio::indexable($e)) {
                $urls[] = ['/empresa/' . $e['slug'] . '/', substr((string) ($e['actualizado'] ?? $e['creado']), 0, 10)];
            }
            // Municipios dentro del radio de alguna empresa: solo los que llegan al mínimo de empresas.
            if ($e['base'] !== null && !isset($municipiosVistos[$e['base']['ine']])) {
                $municipiosVistos[$e['base']['ine']] = true;
                if (count($this->directorio->para($e['base'], $hoy)) >= Directorio::MIN_INDEXABLE) {
                    $urls[] = [$this->rutaMunicipio($e['base']), null];
                }
            }
        }
        $base = rtrim($this->app->url(), '/');
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as [$ruta, $lastmod]) {
            $xml .= '  <url><loc>' . htmlspecialchars($base . $ruta, ENT_XML1) . '</loc>' . ($lastmod ? "<lastmod>$lastmod</lastmod>" : '') . "</url>\n";
        }
        return $xml . "</urlset>\n";
    }

    // ---------------------------------------------------------------------------------------------- apoyo

    /** @return array<string, string> errores del token del formulario y del límite por IP */
    private function comprobarEnvio(int $ahora, string $accion, int $maximo, int $ventana): array
    {
        $estado = $this->app->tokenFormulario()->validar((string) ($_POST['token'] ?? ''), $ahora);
        if ($estado !== 'ok') {
            return ['general' => $estado === 'caducado' ? 'El formulario ha caducado. Vuelve a enviarlo.' : 'No hemos podido validar el formulario. Vuelve a intentarlo.'];
        }
        if ($this->app->limitador()->superado($accion . ':' . Http::ipHash($this->app->clave()), $maximo, $ventana, $ahora)) {
            return ['general' => 'Hemos recibido varias solicitudes desde tu conexión. Inténtalo más tarde o escríbenos.'];
        }
        return [];
    }

    private function renderAlta(array $vars, int $ahora): string
    {
        return $this->render('pages/empresa-alta.twig', $vars + [
            'pagina' => $this->pagina('Da de alta tu empresa gratis', '/empresas/alta/', 'Publica gratis la ficha de tu empresa de tratamiento de agua y recibe solicitudes de clientes de tu zona.', true, [['nombre' => 'Empresas', 'ruta' => '/empresas/'], ['nombre' => 'Alta', 'ruta' => '/empresas/alta/']]),
            'token' => $this->app->tokenFormulario()->emitir($ahora),
            'servicios_lista' => $this->app->servicios(),
            'radios' => Directorio::RADIOS,
            'provincias' => $this->app->provincias()->todas(),
            'trampa' => self::CAMPO_TRAMPA,
        ]);
    }

    private function renderAcceso(array $vars, int $ahora): string
    {
        return $this->render('pages/empresa-acceso.twig', $vars + [
            'pagina' => $this->pagina('Gestiona la ficha de tu empresa', '/empresas/acceso/', '', false, [['nombre' => 'Empresas', 'ruta' => '/empresas/']]),
            'token' => $this->app->tokenFormulario()->emitir($ahora),
            'trampa' => self::CAMPO_TRAMPA,
        ]);
    }

    /** Dureza del municipio si tiene página de datos publicada (índice que genera la web estática). */
    private function durezaPublicada(array $m): ?array
    {
        $archivo = (defined('AGUASINCAL_PUBLICA') ? AGUASINCAL_PUBLICA : '') . '/datos/municipios.json';
        if (!is_file($archivo)) {
            return null;
        }
        $nombre = $m['nombre'];
        $prov = $this->app->provincias()->nombre($m['provincia']);
        foreach (json_decode((string) file_get_contents($archivo), true) ?: [] as $fila) {
            if (($fila['n'] ?? '') === $nombre && ($fila['p'] ?? '') === $prov) {
                return ['fh' => (float) $fila['f'], 'ruta' => (string) $fila['r']];
            }
        }
        return null;
    }

    private function rutaMunicipio(array $m): string
    {
        return '/empresas/' . $this->app->provincias()->slug($m['provincia']) . '/' . $m['slug'] . '/';
    }

    private function listaServicios(array $claves): string
    {
        $nombres = array_map(fn ($c) => mb_strtolower($this->app->servicios()[$c]['nombre'] ?? $c), $claves);
        return $nombres ? ucfirst(implode(', ', $nombres)) : 'Tratamiento de agua';
    }

    private function hoy(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Madrid')))->format('Y-m-d');
    }

    private function pagina(string $titulo, string $ruta, string $descripcion, bool $indexable, array $migas): array
    {
        return [
            'titulo' => $titulo,
            'seo_title' => $titulo . ' | AguaSinCal',
            'descripcion' => $descripcion,
            'ruta' => $ruta,
            'indexable' => $indexable,
            'breadcrumbs' => $migas,
            'schema' => $migas ? [[
                '@context' => 'https://schema.org',
                '@type' => 'BreadcrumbList',
                'itemListElement' => array_map(fn ($i, $m) => ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $m['nombre'], 'item' => $this->app->url($m['ruta'])], array_keys($migas), $migas),
            ]] : [],
        ];
    }

    private function noEncontrada(): string
    {
        http_response_code(404);
        return $this->render('pages/404.twig', ['pagina' => $this->pagina('Página no encontrada', '/404.html', '', false, [])]);
    }

    private function render(string $plantilla, array $vars): string
    {
        return $this->app->twig()->render($plantilla, $vars + ['servicios_lista' => $this->app->servicios()]);
    }
}
