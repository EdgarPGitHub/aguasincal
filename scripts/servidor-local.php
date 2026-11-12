<?php
/**
 * Router para el servidor de desarrollo de PHP: hace lo mismo que las reglas de .htaccess
 * que necesitan PHP (directorio de empresas).
 *
 *   php -S 127.0.0.1:8080 -t dist scripts/servidor-local.php
 */
$ruta = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$raiz = (string) $_SERVER['DOCUMENT_ROOT'];
if ($ruta === '/sitemap-empresas.xml' || (preg_match('#^/empresas?/#', $ruta) && !is_file($raiz . $ruta) && !is_file($raiz . $ruta . '/index.php'))) {
    require $raiz . '/empresas/index.php';
    return true;
}
return false;
