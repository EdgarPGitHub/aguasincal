<?php
declare(strict_types=1);

namespace AguaSinCal\Build;

/**
 * Comprobaciones SEO sobre el HTML generado. Los errores bloquean el despliegue.
 *  - title, meta description, un único H1 y canonical en páginas indexables
 *  - enlaces internos rotos
 *  - JSON-LD válido
 *  - sitemap = páginas indexables
 *  - contenido casi duplicado entre páginas redactadas (Markdown)
 */
final class Comprobaciones
{
    public const SIMILITUD_ERROR = 0.6;
    public const SIMILITUD_AVISO = 0.4;

    /** @var array<string, array<string, true>> destino noindex => páginas indexables que lo enlazan */
    private array $enlacesABorrador = [];

    public function __construct(private readonly Informe $informe)
    {
    }

    /** @param array<string, array> $paginas */
    public function ejecutar(array $paginas, string $salida, array $docs): void
    {
        $rutasValidas = $this->rutasValidas($paginas, $salida);
        $this->enlacesABorrador = [];
        $indexables = 0;
        foreach ($paginas as $ruta => $p) {
            $html = (string) file_get_contents($p['archivo']);
            $this->comprobarEnlaces($ruta, $html, $rutasValidas, $paginas, $p['indexable']);
            $this->comprobarJsonLd($ruta, $html);
            $this->comprobarEmails($ruta, $html);
            if ($p['indexable']) {
                $indexables++;
                $this->comprobarMetadatos($ruta, $html);
            }
        }
        foreach ($this->enlacesABorrador as $destino => $origenes) {
            $this->informe->aviso(sprintf('%d páginas indexables enlazan a %s, que aún es borrador/noindex.', count($origenes), $destino));
        }
        $this->comprobarSitemap($paginas, $salida);
        $this->comprobarSimilitud($docs);
        $this->informe->resumen = [
            'páginas generadas' => count($paginas),
            'páginas indexables' => $indexables,
        ];
    }

    private function rutasValidas(array $paginas, string $salida): array
    {
        $validas = array_fill_keys(array_keys($paginas), true);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($salida, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $rel = substr($f->getPathname(), strlen($salida));
            $validas[$rel] = true;
            if (str_ends_with($rel, '/index.php') || str_ends_with($rel, '/index.html')) {
                $validas[substr($rel, 0, -strlen(basename($rel)))] = true;
            }
        }
        return $validas;
    }

    private function comprobarEnlaces(string $ruta, string $html, array $validas, array $paginas, bool $indexable): void
    {
        preg_match_all('/\s(?:href|src)="(\/[^"]*)"/', $html, $m);
        foreach (array_unique($m[1]) as $url) {
            $limpia = html_entity_decode((string) strtok($url, '?#'));
            if ($limpia === '' || str_starts_with($limpia, '//')) {
                continue;
            }
            if (!isset($validas[$limpia])) {
                $this->informe->error("Enlace roto en $ruta → $url");
                continue;
            }
            if ($indexable && isset($paginas[$limpia]) && !$paginas[$limpia]['indexable'] && !in_array($paginas[$limpia]['tipo'], ['pagina', 'sistema'], true)) {
                $this->enlacesABorrador[$limpia][$ruta] = true;
            }
        }
    }

    /** Ningún email en claro ni mailto: (se usan |email_protegido y %…email%), para no atraer spam. */
    private function comprobarEmails(string $ruta, string $html): void
    {
        if (str_contains($html, 'mailto:') || preg_match('/[a-z0-9._%+-]+@[a-z0-9-]+(?:\.[a-z0-9-]+)*\.[a-z]{2,}/i', $html, $m)) {
            $this->informe->error("$ruta: contiene un email sin proteger" . (isset($m[0]) ? " ({$m[0]})" : ' (mailto:)') . '. Usa el filtro email_protegido.');
        }
    }

    private function comprobarJsonLd(string $ruta, string $html): void
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        foreach ($m[1] as $json) {
            json_decode($json);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->informe->error("JSON-LD no válido en $ruta: " . json_last_error_msg());
            }
        }
    }

    private function comprobarMetadatos(string $ruta, string $html): void
    {
        if (!preg_match('#<title>(.*?)</title>#s', $html, $t) || trim($t[1]) === '') {
            $this->informe->error("$ruta: falta <title>.");
        } elseif (mb_strlen(html_entity_decode($t[1])) > 70) {
            $this->informe->aviso("$ruta: title largo (" . mb_strlen(html_entity_decode($t[1])) . ' caracteres; Google corta hacia 60).');
        }
        if (!preg_match('#<meta name="description" content="([^"]*)">#', $html, $d) || trim($d[1]) === '') {
            $this->informe->error("$ruta: falta meta description.");
        } elseif (mb_strlen(html_entity_decode($d[1])) > 165) {
            $this->informe->aviso("$ruta: meta description larga (" . mb_strlen(html_entity_decode($d[1])) . ' caracteres).');
        }
        $h1 = preg_match_all('#<h1[\s>]#', $html);
        if ($h1 !== 1) {
            $this->informe->error("$ruta: debe tener exactamente un H1 (tiene $h1).");
        }
        if (!str_contains($html, '<link rel="canonical"')) {
            $this->informe->error("$ruta: falta canonical.");
        }
        if (str_contains($html, 'PENDIENTE]')) {
            $this->informe->error("$ruta: contiene datos pendientes y está marcada como indexable.");
        }
    }

    private function comprobarSitemap(array $paginas, string $salida): void
    {
        $enSitemap = [];
        foreach (glob($salida . '/sitemap-*.xml') ?: [] as $archivo) {
            preg_match_all('#<loc>[^<]*?(/[^<]*)</loc>#', (string) file_get_contents($archivo), $m);
            foreach ($m[1] as $loc) {
                $enSitemap[parse_url($loc, PHP_URL_PATH) ?: $loc] = true;
            }
        }
        foreach ($paginas as $ruta => $p) {
            if ($p['indexable'] && !isset($enSitemap[$ruta])) {
                $this->informe->error("$ruta es indexable pero no está en el sitemap.");
            }
            if (!$p['indexable'] && isset($enSitemap[$ruta])) {
                $this->informe->error("$ruta está en el sitemap pero no es indexable.");
            }
        }
    }

    /** Similitud de Jaccard entre textos redactados del mismo tipo (shingles de 5 palabras). */
    private function comprobarSimilitud(array $docs): void
    {
        foreach (['ciudades', 'guias', 'servicios'] as $tipo) {
            $lista = array_values(array_filter($docs[$tipo] ?? [], fn ($d) => $d['fm']['indexable'] || ($d['fm']['estado'] ?? '') === 'borrador'));
            $shingles = array_map(fn ($d) => $this->shingles($d['texto']), $lista);
            for ($i = 0; $i < count($lista); $i++) {
                for ($j = $i + 1; $j < count($lista); $j++) {
                    $sim = $this->jaccard($shingles[$i], $shingles[$j]);
                    $par = basename($lista[$i]['archivo']) . ' y ' . basename($lista[$j]['archivo']);
                    $publicados = $lista[$i]['fm']['indexable'] && $lista[$j]['fm']['indexable'];
                    if ($sim >= self::SIMILITUD_ERROR && $publicados) {
                        $this->informe->error(sprintf('Contenido casi duplicado (%d %%): %s.', $sim * 100, $par));
                    } elseif ($sim >= self::SIMILITUD_AVISO) {
                        $this->informe->aviso(sprintf('Contenido muy parecido (%d %%): %s.', $sim * 100, $par));
                    }
                }
            }
        }
    }

    /** @return array<string, true> */
    private function shingles(string $texto): array
    {
        $palabras = preg_split('/\W+/u', mb_strtolower($texto), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $set = [];
        for ($i = 0; $i + 5 <= count($palabras); $i++) {
            $set[implode(' ', array_slice($palabras, $i, 5))] = true;
        }
        return $set;
    }

    private function jaccard(array $a, array $b): float
    {
        if (!$a || !$b) {
            return 0.0;
        }
        $inter = count(array_intersect_key($a, $b));
        return $inter / (count($a) + count($b) - $inter);
    }
}
