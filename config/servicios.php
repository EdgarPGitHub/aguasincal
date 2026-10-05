<?php
/**
 * Catálogo de servicios. La clave es el slug del servicio (y de su URL hub).
 * La cobertura del panel se define por provincia × clave de servicio.
 *
 * - clientes: tipos de cliente que se ofrecen en el formulario.
 * - modalidades: compra y/o alquiler (vacío = no aplica).
 * - personas: si el formulario pide nº de personas (dimensionado de equipos).
 * - hub: false si el servicio no tiene página propia todavía.
 */
return [
    'descalcificadores' => [
        'nombre' => 'Descalcificadores',
        'singular' => 'descalcificador',
        'formulario' => 'Descalcificador',
        'clientes' => ['particular', 'empresa', 'hosteleria'],
        'modalidades' => ['compra', 'alquiler'],
        'personas' => true,
        'hub' => true,
    ],
    'osmosis-inversa' => [
        'nombre' => 'Ósmosis inversa',
        'singular' => 'equipo de ósmosis inversa',
        'formulario' => 'Ósmosis inversa',
        'clientes' => ['particular', 'empresa', 'hosteleria'],
        'modalidades' => ['compra', 'alquiler'],
        'personas' => true,
        'hub' => true,
    ],
    'filtros-agua' => [
        'nombre' => 'Filtros de agua',
        'singular' => 'filtro de agua',
        'formulario' => 'Filtros de agua',
        'clientes' => ['particular', 'empresa', 'hosteleria'],
        'modalidades' => [],
        'personas' => false,
        'hub' => true,
    ],
    'fuentes-agua-empresas' => [
        'nombre' => 'Fuentes y dispensadores de agua',
        'singular' => 'fuente de agua',
        'formulario' => 'Fuente o dispensador de agua',
        'clientes' => ['empresa', 'hosteleria'],
        'modalidades' => ['compra', 'alquiler'],
        'personas' => false,
        'hub' => true,
    ],
    'mantenimiento-reparacion' => [
        'nombre' => 'Mantenimiento y reparación',
        'singular' => 'mantenimiento o reparación',
        'formulario' => 'Mantenimiento o reparación',
        'clientes' => ['particular', 'empresa', 'hosteleria'],
        'modalidades' => [],
        'personas' => false,
        'hub' => true,
    ],
    // Comunidades de vecinos: sin profesional por ahora (siempre lista de espera).
    'comunidades' => [
        'nombre' => 'Comunidades de vecinos',
        'singular' => 'tratamiento de agua para comunidades',
        'formulario' => 'Comunidad de vecinos / edificio',
        'clientes' => ['comunidad'],
        'modalidades' => [],
        'personas' => false,
        'hub' => false,
    ],
];
