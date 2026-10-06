<?php
/**
 * Datos públicos del sitio. Nada secreto aquí: contraseñas y claves van en config.local.php (servidor).
 *
 * Teléfono y WhatsApp: NO se editan aquí. Se configuran con las variables TELEFONO y WHATSAPP
 * de GitHub (Settings → Secrets and variables → Actions → Variables) y se aplican al desplegar.
 * Mientras estén vacías, la web no muestra los botones de llamar ni de WhatsApp.
 */
return [
    'nombre' => 'AguaSinCal',
    'url' => 'https://aguasincal.es',
    'idioma' => 'es-ES',

    // Se rellenan desde las variables TELEFONO y WHATSAPP (ver arriba).
    'telefono_visible' => '',
    'telefono_enlace' => '',
    'whatsapp' => '',
    'email_publico' => 'info@aguasincal.es',

    // Titular de la web (aviso legal / privacidad).
    'titular' => [
        'nombre' => 'Edgar Ponce Anducas',
        'nif' => '47642272A',
        'domicilio' => 'Pau Claris 3, 08950 Esplugues de Llobregat (Barcelona)',
        'email' => 'info@aguasincal.es',
    ],

    // Persona que revisa técnicamente las guías (sin foto).
    'revisor_tecnico' => [
        'nombre' => '',
        'cargo' => '',
        'empresa' => '',
    ],

    // Marcas propias / colaboradoras para la franja de confianza.
    // ['nombre' => '', 'logo' => '/img/marcas/x.svg', 'url' => 'https://...']
    'marcas' => [],
];
