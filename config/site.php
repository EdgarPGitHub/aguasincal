<?php
/**
 * Datos públicos del sitio. Los valores vacíos están pendientes del "Paso 1"
 * (datos del titular, teléfono y WhatsApp). Mientras estén vacíos, la web no
 * muestra los botones de llamada/WhatsApp y los textos legales quedan en borrador.
 *
 * Nada secreto aquí: contraseñas y claves van en config.local.php (servidor).
 */
return [
    'nombre' => 'AguaSinCal',
    'url' => 'https://aguasincal.es',
    'idioma' => 'es-ES',

    // Teléfono público (formato visible) y número para enlaces (solo dígitos, con prefijo 34).
    'telefono_visible' => '',
    'telefono_enlace' => '',
    // WhatsApp Business (solo dígitos, con prefijo 34).
    'whatsapp' => '',
    'email_publico' => '',

    // Titular de la web (aviso legal / privacidad).
    'titular' => [
        'nombre' => '',
        'nif' => '',
        'domicilio' => '',
        'email' => '',
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
