<?php
/**
 * config/editions.php — Que incluye la edicion Express de Fiscalpoint.
 *
 * Una empresa (master.tenants) tiene una edicion: 'completa' (la app de siempre, sin restriccion alguna) o 'express'
 * (la app movil de facturacion: un subconjunto cerrado de modulos, tipos de comprobante y rutas). La columna es
 * master.tenants.edicion (migracion 014); este archivo dice QUE incluye Express y src/TenantEntitlements.php lo aplica.
 *
 * Lo carga TenantEntitlements::config(), una sola vez por proceso. En las peticiones la clase solo se incluye despues
 * de comprobar que la empresa es Express, asi que una empresa completa nunca llega aqui. Un cambio en este archivo
 * vale para toda empresa Express desde su siguiente peticion.
 *
 * Los nombres de modulo ('facturacion', 'clientes', ...) son los de Express y se escriben EXACTOS (sin mayusculas ni
 * sinonimos): un token mal escrito en master.tenants.modulos_habilitados angosta y nunca ensancha. Los permisos (los
 * valores de 'permisos' y las entradas de 'rol_user') son tokens del catalogo de config/permissions.php.
 */
return [
    // Preset de modulos de Express, en el orden canonico: asi los devuelven modulosEfectivos y el bloque de login, sea
    // cual sea el orden en que un operador los escriba en master.tenants.modulos_habilitados. Esa columna solo puede
    // ANGOSTAR el preset (vacia o NULL = todo el preset); un token que no este aqui no suma nada.
    'modulos' => ['facturacion', 'clientes', 'productos', 'usuarios', 'configuracion'],

    // Tipos de e-CF que una empresa Express puede VENDER. Cada empresa trae los suyos en
    // master.tenants.tipos_ecf_habilitados (lista separada por comas; vacia o NULL = ninguno, que es cerrado):
    //   31 factura de credito fiscal, 32 factura de consumo, 45 comprobante gubernamental.
    // Los otros tipos que Express emite no se listan aqui: el 34 (nota de credito, para anular) se deriva de cualquier
    // tipo de venta, y el 33 (nota de debito) solo cuenta si la empresa lo trae en su lista. Ver
    // TenantEntitlements::tiposEfectivos.
    'tipos_venta' => ['31', '32', '45'],

    // Modulo de Express -> token de permiso de config/permissions.php. permisosEfectivos agrega ademas el token
    // compartido 'unidades' (catalogos: unidades de medida, consulta de RNC, provincias) siempre que facturacion,
    // clientes o productos este habilitado, porque esas pantallas leen esos catalogos.
    'permisos' => [
        'facturacion'   => 'facturas',
        'clientes'      => 'clients',
        'productos'     => 'products',
        'usuarios'      => 'users',
        'configuracion' => 'ncf',
    ],

    // Lista de rutas de la API que una empresa Express puede llamar: lo que no aparezca se deniega. Todavia vacia; la
    // llena la evaluacion de rutas de TenantEntitlements.
    'rutas' => [],

    // Permisos del rol de sistema 'user' (el Facturador) en una empresa Express: vender, ver y crear clientes y
    // productos y leer los catalogos compartidos. Nunca 'users' ni 'ncf': los usuarios y la numeracion son de
    // administrador (el rol 'admin' conserva '*'). Es la unica definicion de este preset: las herramientas de
    // aprovisionamiento lo siembran y lo verifican contra esta lista.
    'rol_user' => ['facturas', 'clients', 'products', 'unidades'],

    // Dias sin usar un token de una empresa Express antes de que venza ("Tu sesion vencio por inactividad"). Cuenta
    // desde su ultimo uso (o desde su creacion si nunca se uso); solo vence el que es estrictamente mas viejo.
    'TOKEN_INACTIVIDAD_DIAS' => 30,

    // Lo que la API anuncia en user.entitlements.capacidades para que el cliente muestre u oculte. 'certificado' es
    // true: GET /api/emisor trae el bloque informativo del certificado. 'idempotencia' y 'reconciliador' se encienden
    // cuando existan la clave de idempotencia y el reconciliador de envios, no antes: el cliente no debe contar con algo
    // que la API todavia no hace.
    'CAPACIDADES' => ['idempotencia' => false, 'reconciliador' => false, 'certificado' => true],

    // Dias de vigencia que le quedan al certificado para avisar. Es informativo, nunca bloquea nada: "por vencer" desde
    // CERT_POR_VENCER_DIAS y "critico" desde CERT_CRITICO_DIAS.
    'CERT_POR_VENCER_DIAS' => 60,
    'CERT_CRITICO_DIAS'    => 15,
];
