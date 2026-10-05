<?php
declare(strict_types=1);

/**
 * Genera la lista de keywords semilla (servicio × ciudad + genéricas) para medir su volumen con DataForSEO.
 *
 *   php scripts/semillas_keywords.php > docs/keywords/semillas.txt
 *   php scripts/dataforseo.php volumen docs/keywords/semillas.txt > docs/keywords/volumen.csv
 *
 * Ciudades: las principales de las provincias publicadas. Se amplía cuando haya datos del INE.
 */
$ciudades = [
    // Barcelona
    'barcelona', 'hospitalet', 'badalona', 'terrassa', 'sabadell', 'mataro', 'santa coloma de gramenet', 'cornella',
    'sant boi', 'sant cugat', 'rubi', 'manresa', 'vilanova i la geltru', 'viladecans', 'castelldefels', 'granollers',
    'cerdanyola', 'mollet', 'el prat de llobregat', 'gava', 'esplugues', 'sitges', 'vic', 'igualada', 'martorell',
    // Girona
    'girona', 'figueres', 'blanes', 'lloret de mar', 'olot', 'salt', 'palafrugell', 'sant feliu de guixols', 'roses',
    // Lleida
    'lleida', 'balaguer', 'tarrega', 'mollerussa', 'la seu d\'urgell',
    // Tarragona
    'tarragona', 'reus', 'cambrils', 'salou', 'tortosa', 'el vendrell', 'valls', 'calafell', 'amposta',
    // Málaga
    'malaga', 'marbella', 'mijas', 'fuengirola', 'velez malaga', 'torremolinos', 'benalmadena', 'estepona',
    'rincon de la victoria', 'antequera', 'alhaurin de la torre', 'ronda', 'nerja', 'torrox', 'coin', 'manilva',
];

$servicios = [
    'descalcificador', 'descalcificadores', 'osmosis inversa', 'osmosis', 'filtro agua', 'fuente de agua oficina',
    'dispensador agua', 'reparacion descalcificador', 'mantenimiento descalcificador', 'mantenimiento osmosis',
    'alquiler osmosis', 'dureza agua',
];

$genericas = [
    'descalcificador', 'descalcificador precio', 'precio descalcificador instalado', 'descalcificador agua casa',
    'mejor descalcificador', 'descalcificador alquiler', 'renting descalcificador', 'descalcificador no gasta sal',
    'descalcificador pierde agua', 'descalcificador gasta mucha sal', 'regenerar descalcificador', 'sal descalcificador',
    'mantenimiento descalcificador', 'cuanto dura un descalcificador', 'descalcificador o osmosis',
    'osmosis inversa', 'osmosis inversa precio', 'osmosis inversa instalacion', 'alquiler osmosis inversa',
    'osmosis inversa hosteleria', 'osmosis para restaurante', 'osmosis no para de tirar agua', 'osmosis pierde agua',
    'cambiar filtros osmosis', 'cada cuanto cambiar filtros osmosis', 'membrana osmosis', 'osmosis sin deposito',
    'fuente de agua para oficina', 'fuente agua oficina alquiler', 'dispensador agua osmotizada', 'fuente agua conectada a red',
    'fuente agua gimnasio', 'maquina agua filtrada empresa', 'agua filtrada restaurante', 'filtro agua cafetera industrial',
    'filtro agua entrada vivienda', 'filtro carbon activo agua', 'dureza del agua', 'agua dura', 'como saber la dureza del agua',
    'calculadora descalcificador', 'descalcificador comunidad de vecinos',
];

$lista = $genericas;
foreach ($servicios as $s) {
    foreach ($ciudades as $c) {
        $lista[] = "$s $c";
    }
}
echo implode("\n", array_values(array_unique($lista))), "\n";
