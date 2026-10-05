<?php
declare(strict_types=1);

namespace AguaSinCal\Build;

use AguaSinCal\Agua;
use AguaSinCal\App;
use AguaSinCal\Vista;
use Twig\Environment;

/**
 * Genera la web estática en dist/: páginas HTML, sitemaps, robots.txt, .htaccess,
 * índice de búsqueda, assets y puntos de entrada PHP. Después pasa las comprobaciones SEO.
 */
final class Generador
{
    /** Municipios con página propia: población mínima (salvo capitales o con página comercial). */
    public const POBLACION_MINIMA = 10000;
    /** Antigüedad máxima de la última muestra para indexar una página de municipio. */
    public const MESES_MAX_MUESTRA = 24;
    /** Mínimo de municipios con datos para indexar una página de provincia. */
    public const MIN_MUNICIPIOS_PROVINCIA = 5;

    public const TEXTOS_RECOMENDACION = [
        'no-necesario' => 'no necesario',
        'opcional' => 'opcional',
        'recomendable' => 'recomendable',
        'recomendado' => 'recomendado',
        'muy-recomendado' => 'muy recomendado',
        'sin-datos' => 'sin datos',
    ];

    private array $site;
    private array $servicios;
    private array $provincias;
    private array $zonas;
    private array $precios;
    private Environment $twig;
    private DatosAgua $agua;
    /** @var array<string, array> páginas por ruta */
    private array $paginas = [];
    /** @var array<string, array> páginas de municipio generadas por INE */
    private array $paginasMunicipio = [];
    private array $docs = [];
    private string $version;
    private Informe $informe;

    /**
     * @param array{salida: string, app: string, panel: string, protegido: bool, htpasswd: string, hoy: string, datos?: string} $opciones
     */
    public function __construct(private readonly App $app, private readonly array $opciones)
    {
        $this->informe = new Informe();
    }

    public function generar(): Informe
    {
        $this->site = $this->app->config('site');
        $this->servicios = $this->app->servicios();
        $this->provincias = $this->app->config('provincias');
        $this->zonas = $this->app->config('zonas-publicadas');
        $this->precios = $this->app->config('precios');
        $this->version = $this->calcularVersion();
        $this->twig = Vista::crear($this->app, null, ['version_assets' => $this->version]);
        $this->agua = new DatosAgua($this->opciones['datos'] ?? $this->app->raiz . '/data');

        $contenido = new Contenido($this->app->raiz . '/content', $this->site);
        foreach (['paginas', 'servicios', 'ciudades', 'guias'] as $tipo) {
            $this->docs[$tipo] = $contenido->cargar($tipo);
            foreach ($this->docs[$tipo] as &$doc) {
                $this->normalizarDoc($doc);
            }
            unset($doc);
        }

        $this->registrarPaginasDureza();
        $this->registrarServicios();
        $this->registrarCiudades();
        $this->registrarGuias();
        $this->registrarPaginas();
        $this->registrar('/404.html', 'pages/404.twig', ['pagina' => [
            'titulo' => 'Página no encontrada', 'descripcion' => '', 'ruta' => '/404.html', 'indexable' => false,
        ]], false, 'sistema');

        $this->escribir();
        (new Comprobaciones($this->informe))->ejecutar($this->paginas, $this->opciones['salida'], $this->docs);
        return $this->informe;
    }

    // ------------------------------------------------------------------ registro de páginas

    private function normalizarDoc(array &$doc): void
    {
        $fm = &$doc['fm'];
        foreach (Contenido::OBLIGATORIOS as $campo) {
            if (empty($fm[$campo])) {
                $this->informe->error("{$doc['archivo']}: falta el campo obligatorio \"$campo\".");
            }
        }
        foreach ($doc['avisos'] as $aviso) {
            $this->informe->aviso(basename($doc['archivo']) . ': ' . $aviso . ' (se publica como borrador).');
        }
        $fm['ruta'] = (string) ($fm['ruta'] ?? '');
        if ($fm['ruta'] !== '' && $fm['ruta'] !== '/' && !preg_match('#^(/[a-z0-9]+(?:-[a-z0-9]+)*)+/$#', $fm['ruta'])) {
            $this->informe->error("{$doc['archivo']}: la ruta \"{$fm['ruta']}\" debe ir en minúsculas, sin tildes y acabar en /.");
        }
        // Firma de revisión: explícita o la del revisor técnico configurado.
        if (($fm['revision'] ?? '') === 'tecnica' && empty($fm['revisado_por']) && $this->site['revisor_tecnico']['nombre'] !== '') {
            $fm['revisado_por'] = $this->site['revisor_tecnico']['nombre'];
            $fm['revisor_cargo'] = trim($this->site['revisor_tecnico']['cargo'] . ($this->site['revisor_tecnico']['empresa'] !== '' ? ' en ' . $this->site['revisor_tecnico']['empresa'] : ''));
        }
        $necesitaRevision = in_array($doc['tipo'], ['servicios', 'ciudades', 'guias'], true);
        if ($necesitaRevision && ($fm['estado'] ?? '') === 'publicado' && empty($fm['revisado_por'])) {
            $fm['estado'] = 'borrador';
            $this->informe->aviso(basename($doc['archivo']) . ': sin revisor técnico, se publica como borrador (noindex).');
        }
        $fm['indexable'] = ($fm['estado'] ?? '') === 'publicado' && ($fm['indexable'] ?? true) !== false;
    }

    private function registrar(string $ruta, string $plantilla, array $vars, bool $indexable, string $tipo, ?string $lastmod = null): void
    {
        if (isset($this->paginas[$ruta])) {
            $this->informe->error("Ruta duplicada: $ruta ({$this->paginas[$ruta]['tipo']} y $tipo).");
            return;
        }
        $vars['pagina']['indexable'] = $indexable;
        $vars['pagina']['ruta'] = $ruta;
        $this->paginas[$ruta] = [
            'ruta' => $ruta,
            'plantilla' => $plantilla,
            'vars' => $vars,
            'indexable' => $indexable,
            'tipo' => $tipo,
            'lastmod' => $lastmod,
        ];
    }

    private function paginaDesdeDoc(array $doc, array $migas): array
    {
        $fm = $doc['fm'];
        return [
            'titulo' => $fm['titulo'] ?? '',
            'seo_title' => $fm['seo_title'] ?? null,
            'descripcion' => $fm['descripcion'] ?? '',
            'entradilla' => $fm['entradilla'] ?? '',
            'actualizado' => $fm['actualizado'] ?? '',
            'revisado_por' => $fm['revisado_por'] ?? '',
            'revisor_cargo' => $fm['revisor_cargo'] ?? '',
            'cta' => $fm['cta'] ?? null,
            'breadcrumbs' => $migas,
            'schema' => $this->schemaMigas($migas),
        ];
    }

    private function registrarServicios(): void
    {
        foreach ($this->docs['servicios'] as $doc) {
            $fm = $doc['fm'];
            $servicio = (string) ($fm['servicio'] ?? '');
            if ($servicio !== '' && !isset($this->servicios[$servicio])) {
                $this->informe->error("{$doc['archivo']}: servicio \"$servicio\" no existe en config/servicios.php.");
                continue;
            }
            $pagina = $this->paginaDesdeDoc($doc, [['nombre' => $fm['nombre_corto'] ?? $fm['titulo'], 'ruta' => $fm['ruta']]]);
            $pagina['nombre_servicio'] = $servicio !== '' ? $this->servicios[$servicio]['nombre'] : $fm['titulo'];
            $pagina['schema'][] = $this->schemaServicio($fm['titulo'], $pagina['descripcion'], $fm['ruta'], array_map(
                fn ($c) => ['@type' => 'AdministrativeArea', 'name' => $this->provincias[$c]['nombre']],
                array_keys($this->zonas),
            ));
            $servicioForm = (string) ($fm['servicio_form'] ?? $servicio);
            $this->registrar($fm['ruta'], 'pages/servicio.twig', [
                'pagina' => $pagina,
                'contenido_html' => $doc['html'],
                'precios' => $this->precios[$servicioForm] ?? [],
                'precios_actualizado' => $this->precios['actualizado'] ?? '',
                'servicio_form' => $servicioForm,
                'ciudades' => $this->enlacesCiudades($servicioForm, null),
                'guias' => $this->enlacesGuias(fn ($g) => ($g['fm']['servicio_form'] ?? '') === $servicioForm),
                'cubierta' => true,
            ], $fm['indexable'], 'servicio', $fm['actualizado'] ?? null);
        }
    }

    private function registrarCiudades(): void
    {
        foreach ($this->docs['ciudades'] as $doc) {
            $fm = $doc['fm'];
            $servicio = (string) ($fm['servicio'] ?? '');
            $provincia = (string) ($fm['provincia'] ?? '');
            if (!isset($this->servicios[$servicio])) {
                $this->informe->error("{$doc['archivo']}: servicio \"$servicio\" no válido.");
                continue;
            }
            if (!isset($this->zonas[$provincia])) {
                $this->informe->error("{$doc['archivo']}: la provincia \"$provincia\" no está en config/zonas-publicadas.php.");
                continue;
            }
            $hub = $this->docPorServicio($servicio);
            $migas = [];
            if ($hub) {
                $migas[] = ['nombre' => $hub['fm']['nombre_corto'] ?? $hub['fm']['titulo'], 'ruta' => $hub['fm']['ruta']];
            }
            $migas[] = ['nombre' => $fm['ciudad'], 'ruta' => $fm['ruta']];
            $pagina = $this->paginaDesdeDoc($doc, $migas);
            $pagina['schema'][] = $this->schemaServicio($fm['titulo'], $pagina['descripcion'], $fm['ruta'], [['@type' => 'City', 'name' => $fm['ciudad']]]);

            $agua = null;
            $m = isset($fm['municipio_ine']) ? $this->agua->municipio((string) $fm['municipio_ine']) : null;
            if ($m && $m['dureza_fh'] !== null) {
                $agua = [
                    'dureza_fh' => $m['dureza_fh'],
                    'recomendacion' => $m['recomendacion'],
                    'fecha' => $m['fecha'],
                    'zonas' => count($m['zonas']),
                    'ruta' => $this->paginasMunicipio[$m['ine']] ?? ('/dureza-agua/' . $this->provincias[$provincia]['slug'] . '/#m-' . $m['ine']),
                ];
            }
            $this->registrar($fm['ruta'], 'pages/ciudad.twig', [
                'pagina' => $pagina,
                'contenido_html' => $doc['html'],
                'ciudad' => $fm['ciudad'],
                'provincia_nombre' => $this->provincias[$provincia]['nombre'],
                'atiende' => $this->zonas[$provincia]['atiende'] ?? '',
                'agua' => $agua,
                'precios' => $this->precios[$servicio] ?? [],
                'precios_actualizado' => $this->precios['actualizado'] ?? '',
                'servicio_form' => $servicio,
                'otras_ciudades' => array_values(array_filter(
                    $this->enlacesCiudades($servicio, $provincia),
                    fn ($c) => $c['ruta'] !== $fm['ruta'],
                )),
                'cubierta' => true,
            ], $fm['indexable'], 'ciudad', $fm['actualizado'] ?? null);
        }
    }

    private function registrarGuias(): void
    {
        $grupos = [];
        foreach ($this->docs['guias'] as $doc) {
            $fm = $doc['fm'];
            $pagina = $this->paginaDesdeDoc($doc, [['nombre' => 'Guías', 'ruta' => '/guias/'], ['nombre' => $fm['titulo'], 'ruta' => $fm['ruta']]]);
            $pagina['og_type'] = 'article';
            $pagina['schema'][] = $this->schemaArticulo($fm, $pagina['descripcion']);
            $relacionadas = [];
            foreach ((array) ($fm['relacionadas'] ?? []) as $ruta) {
                $rel = $this->docPorRuta('guias', (string) $ruta) ?? $this->docPorRuta('servicios', (string) $ruta);
                if ($rel) {
                    $relacionadas[] = ['ruta' => $rel['fm']['ruta'], 'titulo' => $rel['fm']['titulo']];
                } else {
                    $this->informe->error("{$doc['archivo']}: la relacionada \"$ruta\" no existe.");
                }
            }
            $this->registrar($fm['ruta'], 'pages/guia.twig', [
                'pagina' => $pagina,
                'contenido_html' => $doc['html'],
                'servicio_form' => (string) ($fm['servicio_form'] ?? ''),
                'relacionadas' => $relacionadas,
            ], $fm['indexable'], 'guia', $fm['actualizado'] ?? null);
            $grupos[$fm['grupo'] ?? 'Otras guías'][] = ['ruta' => $fm['ruta'], 'titulo' => $fm['titulo'], 'descripcion' => $fm['descripcion']];
        }
        $hayPublicadas = (bool) array_filter($this->docs['guias'], fn ($d) => $d['fm']['indexable']);
        $this->registrar('/guias/', 'pages/guias.twig', [
            'pagina' => [
                'titulo' => 'Guías sobre agua dura, descalcificadores y ósmosis',
                'seo_title' => 'Guías: descalcificadores, ósmosis, filtros y mantenimiento | AguaSinCal',
                'descripcion' => 'Precios, averías, mantenimiento y cómo elegir descalcificador, ósmosis inversa o fuente de agua. Guías revisadas por técnicos.',
                'entradilla' => 'Respuestas claras, revisadas por técnicos que instalan y reparan estos equipos cada día.',
                'breadcrumbs' => [['nombre' => 'Guías', 'ruta' => '/guias/']],
                'schema' => $this->schemaMigas([['nombre' => 'Guías', 'ruta' => '/guias/']]),
            ],
            'grupos' => array_map(fn ($nombre, $guias) => ['nombre' => $nombre, 'guias' => $guias], array_keys($grupos), $grupos),
        ], $hayPublicadas, 'indice');
    }

    private function registrarPaginas(): void
    {
        foreach ($this->docs['paginas'] as $doc) {
            $fm = $doc['fm'];
            $clave = $fm['clave'] ?? '';
            if (in_array($clave, ['dureza-hub'], true)) {
                continue; // la usa registrarPaginasDureza()
            }
            if ($clave === 'home') {
                $pagina = $this->paginaDesdeDoc($doc, []);
                $pagina['subtitulo'] = $fm['subtitulo'] ?? '';
                $pagina['schema'] = [$this->schemaOrganizacion(), [
                    '@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => $this->site['nombre'],
                    'url' => $this->site['url'] . '/', 'inLanguage' => 'es-ES',
                ]];
                $this->registrar('/', 'pages/home.twig', [
                    'pagina' => $pagina,
                    'contenido_html' => $doc['html'],
                    'servicios_destacados' => array_values(array_map(fn ($d) => [
                        'ruta' => $d['fm']['ruta'], 'nombre' => $d['fm']['nombre_corto'] ?? $d['fm']['titulo'], 'resumen' => $d['fm']['resumen'] ?? $d['fm']['descripcion'],
                    ], array_filter($this->docs['servicios'], fn ($d) => !empty($d['fm']['resumen'])))),
                    'guias_destacadas' => $this->enlacesGuias(fn ($g) => !empty($g['fm']['destacada'])),
                    'cubierta' => true,
                ], $fm['indexable'], 'home', $fm['actualizado'] ?? null);
                continue;
            }
            $migas = [['nombre' => $fm['titulo'], 'ruta' => $fm['ruta']]];
            $pagina = $this->paginaDesdeDoc($doc, $migas);
            if ($clave === 'calculadora') {
                $this->registrar($fm['ruta'], 'pages/calculadora.twig', [
                    'pagina' => $pagina,
                    'contenido_html' => $doc['html'],
                    'tabla' => $this->tablaCalculadora(),
                ], $fm['indexable'], 'herramienta', $fm['actualizado'] ?? null);
                continue;
            }
            $this->registrar($fm['ruta'], 'pages/pagina.twig', [
                'pagina' => $pagina,
                'contenido_html' => $doc['html'],
            ], $fm['indexable'], 'pagina', $fm['actualizado'] ?? null);
        }
    }

    private function registrarPaginasDureza(): void
    {
        $hoy = $this->opciones['hoy'];
        $limiteMuestra = date('Y-m-d', strtotime('-' . self::MESES_MAX_MUESTRA . ' months', strtotime($hoy)));
        $inesConCiudad = [];
        foreach ($this->docs['ciudades'] as $doc) {
            if (isset($doc['fm']['municipio_ine'])) {
                $inesConCiudad[(string) $doc['fm']['municipio_ine']] = $doc;
            }
        }

        // 1) Decidir qué municipios tienen página propia (antes de pintar nada, para poder enlazarlos).
        foreach ($this->zonas as $codigo => $zona) {
            $codigo = (string) $codigo; // PHP convierte '17' en entero al usarlo como clave
            foreach ($this->agua->deProvincia($codigo) as $m) {
                $grande = $m['poblacion'] >= self::POBLACION_MINIMA || $m['capital'] || isset($inesConCiudad[$m['ine']]);
                if ($grande && $m['fecha'] >= $limiteMuestra) {
                    $this->paginasMunicipio[$m['ine']] = '/dureza-agua/' . $this->provincias[$codigo]['slug'] . '/' . $m['slug'] . '/';
                }
            }
        }

        $hub = null;
        foreach ($this->docs['paginas'] as $doc) {
            if (($doc['fm']['clave'] ?? '') === 'dureza-hub') {
                $hub = $doc;
            }
        }
        $migasHub = [['nombre' => 'Dureza del agua', 'ruta' => '/dureza-agua/']];
        $provinciasDatos = [];
        $indice = [];
        $algunaIndexable = false;

        foreach ($this->zonas as $codigo => $zona) {
            $codigo = (string) $codigo; // PHP convierte '17' en entero al usarlo como clave
            $prov = $this->provincias[$codigo];
            $rutaProv = '/dureza-agua/' . $prov['slug'] . '/';
            $municipios = $this->agua->deProvincia($codigo);
            $durezas = array_column($municipios, 'dureza_fh');
            $media = $durezas ? round(array_sum($durezas) / count($durezas), 1) : null;
            $duras = count(array_filter($durezas, fn ($d) => $d >= 30));
            $provinciasDatos[] = ['nombre' => $prov['nombre'], 'ruta' => $rutaProv, 'municipios_con_datos' => count($municipios), 'media_fh' => $media];
            $indexableProv = count($municipios) >= self::MIN_MUNICIPIOS_PROVINCIA;
            $algunaIndexable = $algunaIndexable || $indexableProv;

            $filas = [];
            foreach ($municipios as $m) {
                $ruta = $this->paginasMunicipio[$m['ine']] ?? null;
                $filas[] = $m + ['ruta' => $ruta];
                $indice[] = ['n' => $m['nombre'], 'p' => $prov['nombre'], 'r' => $ruta ?? $rutaProv . '#m-' . $m['ine'], 'f' => $m['dureza_fh']];
            }
            $migasProv = [...$migasHub, ['nombre' => $prov['nombre'], 'ruta' => $rutaProv]];
            $entradilla = $media !== null
                ? sprintf('El agua del grifo en la provincia de %s tiene una dureza media de %s °fH (%s). %d de los %d municipios con datos tienen agua dura o muy dura.',
                    $prov['nombre'], number_format($media, 1, ',', '.'), mb_strtolower(Agua::categoria($media)['nombre']), $duras, count($municipios))
                : 'Estamos preparando los datos oficiales de dureza del agua de esta provincia.';
            $this->registrar($rutaProv, 'pages/dureza-provincia.twig', [
                'pagina' => [
                    'titulo' => 'Dureza del agua en la provincia de ' . $prov['nombre'],
                    'seo_title' => $this->tituloSeo('Dureza del agua en ' . $prov['nombre'] . ', municipio a municipio'),
                    'descripcion' => 'Consulta la dureza del agua de cada municipio de la provincia de ' . $prov['nombre'] . ' con datos oficiales del SINAC y descubre si necesitas descalcificador.',
                    'entradilla' => $entradilla,
                    'breadcrumbs' => $migasProv,
                    'schema' => $this->schemaMigas($migasProv),
                ],
                'contenido_html' => '',
                'municipios' => $filas,
                'resumen' => ['media_fh' => $media, 'pct_duras' => $municipios ? round($duras / count($municipios) * 100) : 0],
                'provincia_nombre' => $prov['nombre'],
                'cubierta' => true,
            ], $indexableProv, 'dureza-provincia', $municipios ? max(array_column($municipios, 'fecha')) : null);

            // Páginas de municipio
            $ranking = $municipios;
            usort($ranking, fn ($a, $b) => $b['dureza_fh'] <=> $a['dureza_fh']);
            $posiciones = array_flip(array_column($ranking, 'ine'));
            foreach ($municipios as $m) {
                if (!isset($this->paginasMunicipio[$m['ine']])) {
                    continue;
                }
                $ruta = $this->paginasMunicipio[$m['ine']];
                $cat = Agua::categoria($m['dureza_fh']);
                $migas = [...$migasProv, ['nombre' => $m['nombre'], 'ruta' => $ruta]];
                $ciudadDoc = $inesConCiudad[$m['ine']] ?? null;
                $this->registrar($ruta, 'pages/dureza-municipio.twig', [
                    'pagina' => [
                        'titulo' => 'Dureza del agua en ' . $m['nombre'],
                        'seo_title' => $this->tituloSeo(sprintf('Dureza del agua en %s: %s °fH (%s)', $m['nombre'], number_format($m['dureza_fh'], 0, ',', '.'), mb_strtolower($cat['nombre']))),
                        'descripcion' => sprintf('El agua de %s tiene %s °fH (%s). Dato oficial del SINAC. Descubre si necesitas descalcificador u ósmosis y de qué tamaño.',
                            $m['nombre'], number_format($m['dureza_fh'], 1, ',', '.'), mb_strtolower($cat['nombre'])),
                        'entradilla' => $this->entradillaMunicipio($m, $media, (int) $posiciones[$m['ine']] + 1, count($municipios), $prov['nombre']),
                        'breadcrumbs' => $migas,
                        'schema' => $this->schemaMigas($migas),
                    ],
                    'contenido_html' => '',
                    'm' => $m + [
                        'dimensionados' => array_values(array_filter(array_map(
                            fn ($p) => ($d = Agua::dimensionado($p, $m['dureza_fh'])) ? $d + ['personas' => $p] : null,
                            [2, 3, 4, 5, 6],
                        ))),
                        'servicio_ciudad' => $ciudadDoc ? ['ruta' => $ciudadDoc['fm']['ruta'], 'titulo' => $ciudadDoc['fm']['titulo']] : null,
                    ],
                    'textos_reco' => self::TEXTOS_RECOMENDACION,
                    'cercanos' => array_map(fn ($c) => $c + ['ruta' => $this->paginasMunicipio[$c['ine']]], $this->agua->cercanos(
                        $m, 6, fn ($o) => isset($this->paginasMunicipio[$o['ine']]),
                    )),
                    'ruta_provincia' => $rutaProv,
                    'provincia_nombre' => $prov['nombre'],
                    'cubierta' => true,
                ], true, 'dureza-municipio', $m['fecha']);
            }
        }

        $paginaHub = $hub ? $this->paginaDesdeDoc($hub, $migasHub) : [
            'titulo' => 'Dureza del agua por municipio', 'descripcion' => '', 'entradilla' => '', 'breadcrumbs' => $migasHub, 'schema' => $this->schemaMigas($migasHub),
        ];
        $this->registrar('/dureza-agua/', 'pages/dureza-hub.twig', [
            'pagina' => $paginaHub,
            'contenido_html' => $hub['html'] ?? '',
            'provincias_datos' => $provinciasDatos,
        ], $algunaIndexable && ($hub['fm']['indexable'] ?? false), 'dureza-hub');
        $this->indiceBusqueda = $indice;
    }

    private array $indiceBusqueda = [];

    private function entradillaMunicipio(array $m, ?float $mediaProvincia, int $posicion, int $total, string $provincia): string
    {
        $cat = mb_strtolower(Agua::categoria($m['dureza_fh'])['nombre']);
        $texto = sprintf('El agua del grifo en %s tiene una dureza de %s °fH: es %s.', $m['nombre'], number_format($m['dureza_fh'], 1, ',', '.'), $cat);
        if (count($m['zonas']) > 1 && $m['max_fh'] - $m['min_fh'] >= 3) {
            $texto .= sprintf(' Según la zona, va de %s a %s °fH.', number_format($m['min_fh'], 0, ',', '.'), number_format($m['max_fh'], 0, ',', '.'));
        }
        if ($mediaProvincia !== null && $total >= self::MIN_MUNICIPIOS_PROVINCIA) {
            $diferencia = $m['dureza_fh'] - $mediaProvincia;
            if (abs($diferencia) < 2) {
                $texto .= sprintf(' Está en la media de la provincia de %s.', $provincia);
            } else {
                $texto .= sprintf(' Es %s que la media de la provincia (%s °fH)', $diferencia > 0 ? 'más dura' : 'más blanda', number_format($mediaProvincia, 1, ',', '.'));
                $texto .= $posicion <= 10 && $diferencia > 0 ? sprintf(' y está entre las 10 más duras de %d municipios.', $total) : '.';
            }
        }
        return $texto;
    }

    /** Añade " | AguaSinCal" solo si el título no se pasa de 65 caracteres. */
    private function tituloSeo(string $titulo): string
    {
        $conMarca = $titulo . ' | ' . $this->site['nombre'];
        return mb_strlen($conMarca) <= 65 ? $conMarca : $titulo;
    }

    private function tablaCalculadora(): array
    {
        $personas = [2, 3, 4, 5, 6];
        $filas = [];
        foreach ([15, 20, 25, 30, 35, 40, 45, 50] as $fh) {
            $filas[] = ['fh' => $fh, 'celdas' => array_map(fn ($p) => Agua::dimensionado($p, (float) $fh), $personas)];
        }
        return ['personas' => $personas, 'filas' => $filas];
    }

    // ------------------------------------------------------------------ enlaces auxiliares

    private function docPorServicio(string $servicio): ?array
    {
        foreach ($this->docs['servicios'] as $d) {
            if (($d['fm']['servicio'] ?? '') === $servicio) {
                return $d;
            }
        }
        return null;
    }

    private function docPorRuta(string $tipo, string $ruta): ?array
    {
        foreach ($this->docs[$tipo] as $d) {
            if ($d['fm']['ruta'] === $ruta) {
                return $d;
            }
        }
        return null;
    }

    private function enlacesCiudades(string $servicio, ?string $provincia): array
    {
        $lista = [];
        foreach ($this->docs['ciudades'] as $d) {
            if (($d['fm']['servicio'] ?? '') === $servicio && ($provincia === null || (string) $d['fm']['provincia'] === $provincia)) {
                $lista[] = ['ruta' => $d['fm']['ruta'], 'ciudad' => $d['fm']['ciudad']];
            }
        }
        return $lista;
    }

    private function enlacesGuias(callable $filtro): array
    {
        $lista = [];
        foreach ($this->docs['guias'] as $d) {
            if ($filtro($d)) {
                $lista[] = ['ruta' => $d['fm']['ruta'], 'titulo' => $d['fm']['titulo']];
            }
        }
        return $lista;
    }

    // ------------------------------------------------------------------ schema.org

    private function schemaOrganizacion(): array
    {
        $org = [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => $this->site['nombre'],
            'url' => $this->site['url'] . '/',
            'logo' => $this->site['url'] . '/favicon.svg',
        ];
        if ($this->site['email_publico'] !== '') {
            $org['email'] = $this->site['email_publico'];
        }
        if ($this->site['telefono_enlace'] !== '') {
            $org['telephone'] = '+' . $this->site['telefono_enlace'];
        }
        return $org;
    }

    private function schemaMigas(array $migas): array
    {
        if (!$migas) {
            return [];
        }
        $items = [['@type' => 'ListItem', 'position' => 1, 'name' => 'Inicio', 'item' => $this->site['url'] . '/']];
        foreach ($migas as $i => $miga) {
            $items[] = ['@type' => 'ListItem', 'position' => $i + 2, 'name' => $miga['nombre'], 'item' => $this->site['url'] . $miga['ruta']];
        }
        return [['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items]];
    }

    private function schemaServicio(string $nombre, string $descripcion, string $ruta, array $areas): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'Service',
            'name' => $nombre,
            'description' => $descripcion,
            'url' => $this->site['url'] . $ruta,
            'areaServed' => $areas,
            'provider' => ['@type' => 'Organization', 'name' => $this->site['nombre'], 'url' => $this->site['url'] . '/'],
        ];
    }

    private function schemaArticulo(array $fm, string $descripcion): array
    {
        $articulo = [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $fm['titulo'],
            'description' => $descripcion,
            'datePublished' => $fm['publicado'] ?? $fm['actualizado'] ?? '',
            'dateModified' => $fm['actualizado'] ?? '',
            'inLanguage' => 'es-ES',
            'mainEntityOfPage' => $this->site['url'] . $fm['ruta'],
            'author' => ['@type' => 'Organization', 'name' => $this->site['nombre'], 'url' => $this->site['url'] . '/'],
            'publisher' => ['@type' => 'Organization', 'name' => $this->site['nombre'], 'logo' => ['@type' => 'ImageObject', 'url' => $this->site['url'] . '/favicon.svg']],
        ];
        return $articulo;
    }

    // ------------------------------------------------------------------ escritura

    private function escribir(): void
    {
        $salida = $this->opciones['salida'];
        $this->vaciar($salida);
        foreach ($this->paginas as $ruta => &$p) {
            $html = $this->twig->render($p['plantilla'], $p['vars']);
            $archivo = $ruta === '/404.html' ? $salida . '/404.html' : $salida . rtrim($ruta, '/') . '/index.html';
            if (!is_dir(dirname($archivo))) {
                mkdir(dirname($archivo), 0755, true);
            }
            file_put_contents($archivo, $html);
            $p['archivo'] = $archivo;
        }
        unset($p);

        $this->copiarDirectorio($this->app->raiz . '/assets', $salida . '/assets');
        $this->escribirPhp($salida);
        file_put_contents($salida . '/favicon.svg', $this->favicon());
        if (!is_dir($salida . '/datos')) {
            mkdir($salida . '/datos', 0755, true);
        }
        file_put_contents($salida . '/datos/municipios.json', json_encode($this->indiceBusqueda, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->escribirSitemaps($salida);
        file_put_contents($salida . '/robots.txt', $this->robots());
        file_put_contents($salida . '/.htaccess', $this->htaccess());
        file_put_contents($salida . '/_build.json', json_encode([
            'version' => $this->version,
            'generado' => gmdate('c'),
            'paginas' => count($this->paginas),
            'indexables' => count(array_filter($this->paginas, fn ($p) => $p['indexable'])),
        ], JSON_PRETTY_PRINT));
    }

    private function escribirPhp(string $salida): void
    {
        $origen = $this->app->raiz . '/app/public';
        foreach (['presupuesto/index.php', 'accion/index.php', 'api/evento.php'] as $archivo) {
            if (!is_dir(dirname($salida . '/' . $archivo))) {
                mkdir(dirname($salida . '/' . $archivo), 0755, true);
            }
            copy($origen . '/' . $archivo, $salida . '/' . $archivo);
        }
        $panel = trim($this->opciones['panel'], '/');
        mkdir($salida . '/' . $panel, 0755, true);
        copy($origen . '/panel/index.php', $salida . '/' . $panel . '/index.php');
        $app = rtrim($this->opciones['app'], '/');
        file_put_contents($salida . '/_app.php', "<?php\n// Generado por build/build.php: ruta del código de la aplicación.\nreturn require __DIR__ . '/" . addslashes($app) . "/app/bootstrap.php';\n");
    }

    private function escribirSitemaps(string $salida): void
    {
        $grupos = [
            'paginas' => ['home', 'pagina', 'indice', 'herramienta'],
            'servicios' => ['servicio', 'ciudad'],
            'guias' => ['guia'],
            'dureza' => ['dureza-hub', 'dureza-provincia', 'dureza-municipio'],
        ];
        $indice = [];
        foreach ($grupos as $nombre => $tipos) {
            $urls = array_filter($this->paginas, fn ($p) => $p['indexable'] && in_array($p['tipo'], $tipos, true));
            if (!$urls) {
                continue;
            }
            $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
            foreach ($urls as $p) {
                $xml .= '  <url><loc>' . htmlspecialchars($this->site['url'] . $p['ruta'], ENT_XML1) . '</loc>'
                    . ($p['lastmod'] ? '<lastmod>' . htmlspecialchars(substr($p['lastmod'], 0, 10), ENT_XML1) . '</lastmod>' : '')
                    . "</url>\n";
            }
            $xml .= "</urlset>\n";
            file_put_contents("$salida/sitemap-$nombre.xml", $xml);
            $indice[] = "sitemap-$nombre.xml";
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($indice as $archivo) {
            $xml .= '  <sitemap><loc>' . $this->site['url'] . '/' . $archivo . "</loc></sitemap>\n";
        }
        file_put_contents("$salida/sitemap.xml", $xml . "</sitemapindex>\n");
    }

    private function robots(): string
    {
        if ($this->opciones['protegido']) {
            return "User-agent: *\nDisallow: /\n";
        }
        return "User-agent: *\nDisallow: /presupuesto/\nDisallow: /accion/\nDisallow: /api/\n\nSitemap: {$this->site['url']}/sitemap.xml\n";
    }

    private function htaccess(): string
    {
        $host = parse_url($this->site['url'], PHP_URL_HOST);
        $hostRegex = preg_quote((string) $host, '/');
        $redirecciones = '';
        $mapa = is_file($this->app->raiz . '/config/redirecciones.php') ? require $this->app->raiz . '/config/redirecciones.php' : [];
        foreach ($mapa as $desde => $hacia) {
            $redirecciones .= 'RedirectMatch 301 ^' . preg_quote(rtrim($desde, '/'), ' ') . '/?$ ' . $hacia . "\n";
        }
        $proteccion = '';
        if ($this->opciones['protegido']) {
            $proteccion = <<<TXT
                # --- Web protegida hasta el lanzamiento ---
                AuthType Basic
                AuthName "AguaSinCal (en preparación)"
                AuthUserFile {$this->opciones['htpasswd']}
                Require valid-user
                <IfModule mod_headers.c>
                Header always set X-Robots-Tag "noindex, nofollow"
                </IfModule>

                TXT;
        }
        $csp = "default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'";
        return <<<HTA
            # Generado por build/build.php. No editar en el servidor: se sobrescribe en cada despliegue.
            Options -Indexes
            DirectoryIndex index.html index.php
            ErrorDocument 404 /404.html
            AddDefaultCharset UTF-8
            AddType application/manifest+json .webmanifest
            AddType image/svg+xml .svg

            $proteccion<IfModule mod_rewrite.c>
            RewriteEngine On
            # HTTPS y dominio sin www
            RewriteCond %{HTTPS} !=on [OR]
            RewriteCond %{HTTP_HOST} !^$hostRegex$ [NC]
            RewriteCond %{HTTP_HOST} !^(localhost|127\.0\.0\.1)(:\d+)?$ [NC]
            RewriteRule ^ https://$host%{REQUEST_URI} [L,R=301]
            # Archivos internos: ocultos (.git, .env…) salvo .well-known, y los que empiezan por _
            RewriteRule (^|/)\.(?!well-known/) - [F]
            RewriteRule (^|/)_ - [F]
            RewriteRule \.(sqlite|sql|log|md|lock|dist|bak|ini|sh)$ - [F,NC]
            </IfModule>

            $redirecciones<IfModule mod_headers.c>
            Header always set Strict-Transport-Security "max-age=31536000"
            Header always set X-Content-Type-Options "nosniff"
            Header always set X-Frame-Options "DENY"
            Header always set Referrer-Policy "strict-origin-when-cross-origin"
            Header always set Permissions-Policy "camera=(), microphone=(), geolocation=()"
            Header always set Content-Security-Policy "$csp"
            <FilesMatch "\.(css|js|svg|png|jpe?g|webp|avif|woff2)$">
            Header set Cache-Control "public, max-age=31536000, immutable"
            </FilesMatch>
            <FilesMatch "\.(html|xml|txt|json)$">
            Header set Cache-Control "public, max-age=600"
            </FilesMatch>
            </IfModule>

            <IfModule mod_deflate.c>
            AddOutputFilterByType DEFLATE text/html text/css application/javascript application/json image/svg+xml application/xml text/plain
            </IfModule>

            HTA;
    }

    private function favicon(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><path d="M16 2.5c-.4 0-.8.2-1 .5C11.3 8 6.5 14 6.5 19.5a9.5 9.5 0 0 0 19 0C25.5 14 20.7 8 17 3c-.2-.3-.6-.5-1-.5Z" fill="#0a84c6"/><path d="M11.2 20.4a4.9 4.9 0 0 0 4.9 4.9" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round"/></svg>';
    }

    private function calcularVersion(): string
    {
        $hash = hash_init('sha1');
        foreach (['css/estilos.css', 'js/app.js'] as $f) {
            hash_update($hash, (string) file_get_contents($this->app->raiz . '/assets/' . $f));
        }
        return substr(hash_final($hash), 0, 10);
    }

    private function vaciar(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
    }

    private function copiarDirectorio(string $origen, string $destino): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($origen, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $f) {
            $rel = substr($f->getPathname(), strlen($origen));
            if ($f->isDir()) {
                if (!is_dir($destino . $rel)) {
                    mkdir($destino . $rel, 0755, true);
                }
            } else {
                if (!is_dir(dirname($destino . $rel))) {
                    mkdir(dirname($destino . $rel), 0755, true);
                }
                copy($f->getPathname(), $destino . $rel);
            }
        }
    }
}
