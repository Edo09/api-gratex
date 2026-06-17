<?php
/**
 * config/permissions.php — Catalogo de modulos + mapa ruta->modulo (RBAC).
 *
 * Modelo: el permiso es ACCESO A MODULO (ver/usar el modulo), no acciones
 * read/write separadas. Un rol = lista de modulos que puede acceder.
 *
 * Lo ESTATICO (en codigo, igual para todos los tenants):
 *   - catalog : modulos validos (para validar al asignar permisos a un rol).
 *   - routes  : que modulo cubre cada ruta (segmento de /api/<seg>) y metodo.
 *   - defaults: definicion de los 2 roles de sistema (admin/user) que siembra
 *               la migracion 003 y tools/create_tenant.php.
 *
 * Lo PER-TENANT (en la DB master: roles + role_permissions): que modulos tiene
 * cada rol de cada tenant. users.role guarda el NOMBRE del rol.
 *
 * Permiso = nombre de modulo ('facturas', 'gastos', ...) o comodin '*' (todos).
 *
 * Valores del mapa `routes` (por segmento, o por metodo dentro del segmento):
 *   '<modulo>'     -> ruta de usuario-app: exige token valido + acceso a ese modulo
 *   'public'       -> sin auth (login, docs)
 *   'dgii'         -> principal DGII entrante (firma/Bearer); el controller valida
 *   'integration'  -> principal integracion (X-API-SECRET); el controller valida
 */

// Modulos que un usuario operativo ve por defecto (sin administracion).
$USER_MODULES = [
    'facturas',
    'facturas-simples',
    'gastos',
    'clients',
    'products',
    'proveedores',
    'cotizaciones',
    'aprobaciones',
    'reportes',
    'ncf',
    'unidades',
];

// Modulos solo-admin (configuracion / administracion).
$ADMIN_MODULES = [
    'emisor',
    'branding',
    'landing',
    'users',
    'roles',
];

return [
    // Catalogo de modulos validos.
    'catalog' => array_merge($USER_MODULES, $ADMIN_MODULES),

    // Mapa ruta -> modulo requerido. Toda ruta de la app DEBE estar aqui:
    // una ruta ausente es deny (fail-closed) en modo enforce.
    'routes' => [
        'auth'                     => 'public',
        'landing'                  => ['GET' => 'public', '*' => 'landing'],

        'clients'                  => 'clients',
        'products'                 => 'products',
        'proveedores'              => 'proveedores',
        'unidades-medida'          => 'unidades',
        'cotizaciones'             => 'cotizaciones',
        'facturas'                 => 'facturas',
        'facturas-simples'         => 'facturas-simples',
        'gastos'                   => 'gastos',
        'ncf'                      => 'ncf',
        'facturacion-electronica'  => 'facturas',
        'aprobaciones-comerciales' => 'aprobaciones',
        'reportes'                 => 'reportes',
        'emisor'                   => 'emisor',
        'branding'                 => 'branding',
        'users'                    => 'users',
        'roles'                    => 'roles',

        // Mixta: GET = listado de recibidos/aprobaciones para la app; POST = e-CF
        // entrante de DGII (firma/Bearer, lo valida el controller).
        'ecf'                      => ['GET' => 'aprobaciones', '*' => 'dgii'],
        // Solo principal integracion (X-API-SECRET); el controller filtra por tenant.
        'integracion'              => 'integration',
    ],

    // Roles de sistema sembrados por tenant (migracion 003 + create_tenant.php).
    'defaults' => [
        'admin' => ['*'],
        'user'  => $USER_MODULES,
    ],
];
