<?php
declare(strict_types=1);
define('AGUASINCAL_PUBLICA', dirname(__DIR__));
$app = require dirname(__DIR__) . '/_app.php';
(new AguaSinCal\Controladores\Evento($app))->manejar();
