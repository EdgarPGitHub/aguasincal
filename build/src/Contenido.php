<?php
declare(strict_types=1);

namespace AguaSinCal\Build;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use Symfony\Component\Yaml\Yaml;

/**
 * Carga los Markdown de content/{tipo}/ con su front matter YAML.
 * Sustituye marcadores %clave.subclave% por valores de config/site.php; si alguno
 * está vacío, la página se marca como borrador (no se publica con huecos).
 */
final class Contenido
{
    public const OBLIGATORIOS = ['titulo', 'descripcion', 'ruta', 'estado', 'actualizado'];

    private MarkdownConverter $md;

    public function __construct(private readonly string $dir, private readonly array $site)
    {
        $entorno = new Environment(['html_input' => 'allow', 'allow_unsafe_links' => false]);
        $entorno->addExtension(new CommonMarkCoreExtension());
        $entorno->addExtension(new GithubFlavoredMarkdownExtension());
        $this->md = new MarkdownConverter($entorno);
    }

    /** @return list<array{tipo: string, archivo: string, fm: array, html: string, texto: string, avisos: list<string>}> */
    public function cargar(string $tipo): array
    {
        $docs = [];
        foreach (glob($this->dir . '/' . $tipo . '/*.md') ?: [] as $archivo) {
            $docs[] = $this->leer($tipo, $archivo);
        }
        usort($docs, fn ($a, $b) => ($a['fm']['orden'] ?? 100) <=> ($b['fm']['orden'] ?? 100) ?: strcmp($a['archivo'], $b['archivo']));
        return $docs;
    }

    public function leer(string $tipo, string $archivo): array
    {
        $bruto = (string) file_get_contents($archivo);
        if (!preg_match('/\A---\R(.*?)\R---\R?(.*)\z/s', $bruto, $m)) {
            throw new \RuntimeException("Falta el front matter en $archivo");
        }
        $fm = Yaml::parse($m[1]) ?? [];
        if (!is_array($fm)) {
            throw new \RuntimeException("Front matter no válido en $archivo");
        }
        foreach ($fm as $k => $v) {
            if ($v instanceof \DateTimeInterface) {
                $fm[$k] = $v->format('Y-m-d');
            } elseif (is_int($v) && in_array($k, ['actualizado'], true)) {
                $fm[$k] = gmdate('Y-m-d', $v);
            }
        }
        $avisos = [];
        $cuerpo = $this->sustituir($m[2], $avisos, true);
        foreach (['titulo', 'descripcion', 'entradilla', 'seo_title'] as $campo) {
            if (isset($fm[$campo]) && is_string($fm[$campo])) {
                $fm[$campo] = $this->sustituir($fm[$campo], $avisos);
            }
        }
        $avisos = array_values(array_unique($avisos));
        if ($avisos && ($fm['estado'] ?? '') === 'publicado') {
            $fm['estado'] = 'borrador';
        }
        $html = $this->md->convert($cuerpo)->getContent();
        return [
            'tipo' => $tipo,
            'archivo' => $archivo,
            'fm' => $fm,
            'html' => $html,
            'texto' => trim(preg_replace('/\s+/u', ' ', strip_tags($html))),
            'avisos' => $avisos,
        ];
    }

    /** @param list<string> $avisos */
    /** @param bool $html true en el cuerpo Markdown: los emails se escriben protegidos contra robots de spam. */
    private function sustituir(string $texto, array &$avisos, bool $html = false): string
    {
        return preg_replace_callback('/%([a-z_]+(?:\.[a-z_]+)*)%/', function (array $m) use (&$avisos, $html): string {
            $valor = $this->site;
            foreach (explode('.', $m[1]) as $parte) {
                $valor = is_array($valor) ? ($valor[$parte] ?? null) : null;
            }
            if (!is_scalar($valor) || (string) $valor === '') {
                $avisos[] = 'Falta el dato "' . $m[1] . '" en config/site.php';
                return '[' . strtoupper($m[1]) . ' PENDIENTE]';
            }
            if ($html && str_contains($m[1], 'email')) {
                return \AguaSinCal\Vista::emailProtegido((string) $valor);
            }
            return (string) $valor;
        }, $texto);
    }
}
