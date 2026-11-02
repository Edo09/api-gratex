<?php
/**
 * config/editions.php — Que incluye la edicion Express de Fiscalpoint.
 *
 * Una empresa (master.tenants) tiene una edicion: 'completa' (la app de siempre, sin restriccion alguna) o 'express'
 * (la app movil de facturacion: un subconjunto cerrado de modulos, tipos de comprobante y rutas). La columna es
 * master.tenants.edicion (migracion 015); este archivo dice QUE incluye Express y src/TenantEntitlements.php lo aplica.
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

    // Lista de rutas de la API que una empresa Express puede llamar: lo que no aparezca aqui se deniega (por defecto,
    // denegado: una ruta nueva de la API no llega a Express hasta que alguien la agregue a esta lista). Una fila por
    // ruta y metodo; decide la primera que coincide con los tres primeros campos:
    //   route    primer segmento de la URL despues de /api/ ('facturas'), exacto y con sus mayusculas, como lo lee el Router.
    //   method   GET, POST, PUT o DELETE, en mayusculas.
    //   path     expresion regular (delimitador #) sobre el resto de la URL, sin la ruta ni la consulta: '' para
    //            /api/facturas y '12/pdf' para /api/facturas/12/pdf. Siempre anclada con ^...$#D: la D evita que $
    //            acepte un salto de linea al final.
    //   modulos  al menos uno tiene que estar entre los modulos efectivos de la empresa; vacio = siempre (la sesion).
    //   admin    true: ademas el rol del usuario tiene que llamarse 'admin' (otro rol recibe 403 sin codigo).
    // El modulo lo da la empresa, no el rol: un administrador con '*' tampoco pasa por una fila cuyo modulo la empresa no
    // tiene. El login y el canje del POS son publicos (la sesion no se valida para ellos) y por eso no estan aqui.
    // La evaluacion de una URL contra esta lista es TenantEntitlements::evaluarRuta.
    'rutas' => [
        // Sesion: quien soy y cerrar sesion. Sin modulo: toda empresa Express las necesita.
        ['route' => 'auth', 'method' => 'GET',  'path' => '#^me$#D',      'modulos' => [], 'admin' => false],
        ['route' => 'auth', 'method' => 'POST', 'path' => '#^signout$#D', 'modulos' => [], 'admin' => false],

        // Facturas: listado, estadisticas, avisos, las que se pueden modificar, y pdf, xml y estado de una; emitir y
        // reenviar. Sin preview, sin PUT ni DELETE.
        ['route' => 'facturas', 'method' => 'GET',  'path' => '#^(|stats|avisos|modificables|\d+/(pdf|xml|estado))$#D', 'modulos' => ['facturacion'], 'admin' => false],
        ['route' => 'facturas', 'method' => 'POST', 'path' => '#^(|\d+/reenviar)$#D', 'modulos' => ['facturacion'], 'admin' => false],

        // Clientes y productos: leer, crear y editar (la raiz o un id). Sin DELETE y, en productos, sin 'imagen'.
        ['route' => 'clients', 'method' => 'GET',  'path' => '#^(|\d+)$#D', 'modulos' => ['clientes'], 'admin' => false],
        ['route' => 'clients', 'method' => 'POST', 'path' => '#^(|\d+)$#D', 'modulos' => ['clientes'], 'admin' => false],
        ['route' => 'clients', 'method' => 'PUT',  'path' => '#^(|\d+)$#D', 'modulos' => ['clientes'], 'admin' => false],
        ['route' => 'products', 'method' => 'GET',  'path' => '#^(|\d+)$#D', 'modulos' => ['productos'], 'admin' => false],
        ['route' => 'products', 'method' => 'POST', 'path' => '#^(|\d+)$#D', 'modulos' => ['productos'], 'admin' => false],
        ['route' => 'products', 'method' => 'PUT',  'path' => '#^(|\d+)$#D', 'modulos' => ['productos'], 'admin' => false],

        // Catalogos de solo lectura que leen las pantallas de facturar, clientes y productos.
        ['route' => 'rnc',             'method' => 'GET', 'path' => '#^consulta$#D', 'modulos' => ['clientes'], 'admin' => false],
        ['route' => 'unidades-medida', 'method' => 'GET', 'path' => '#^$#D', 'modulos' => ['facturacion', 'productos'], 'admin' => false],

        // Datos del emisor (solo lectura; trae el bloque informativo del certificado).
        ['route' => 'emisor', 'method' => 'GET', 'path' => '#^$#D', 'modulos' => ['facturacion', 'configuracion'], 'admin' => false],

        // Numeracion: solo los rangos (no la secuencia heredada de PUT /api/ncf). Registrar un rango es del administrador.
        ['route' => 'ncf', 'method' => 'GET',  'path' => '#^rangos$#D', 'modulos' => ['configuracion'], 'admin' => false],
        ['route' => 'ncf', 'method' => 'POST', 'path' => '#^rangos$#D', 'modulos' => ['configuracion'], 'admin' => true],

        // Usuarios de la empresa: todo es del administrador.
        ['route' => 'users', 'method' => 'GET',    'path' => '#^(|\d+)$#D', 'modulos' => ['usuarios'], 'admin' => true],
        ['route' => 'users', 'method' => 'POST',   'path' => '#^(|\d+)$#D', 'modulos' => ['usuarios'], 'admin' => true],
        ['route' => 'users', 'method' => 'PUT',    'path' => '#^(|\d+)$#D', 'modulos' => ['usuarios'], 'admin' => true],
        ['route' => 'users', 'method' => 'DELETE', 'path' => '#^(|\d+)$#D', 'modulos' => ['usuarios'], 'admin' => true],
    ],

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
