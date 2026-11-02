<?php
/**
 * test_tenant_edicion_web_scratch.php — la version web de tenant_edicion.php contra un servidor de verdad y un MySQL de verdad: php -S sirviendo
 * public/tenant_edicion.php sobre las bases scratch del write-ahead y del POS (la master es wa_scratch_sin_gate; la empresa A vive en wa_scratch). Su mitad sin
 * base ni red, que prueba cada puerta con datos, es tools/test_tenant_edicion_web.php.
 *
 * Que dice cada seccion:
 *   1) las puertas por HTTP: un GET es 405, un token malo es 403 tras esperar y NO escribe nada en la master, y las respuestas llevan no-store, nosniff y ninguna
 *      cabecera CORS ni el token.
 *   2) meta: las empresas campo por campo (la fila de A lleva una ruta de certificado, una clave cifrada y el hash de integracion, y nada de eso sale), las
 *      migraciones 015 y 017 puestas y el preset.
 *   3) ensayo, aplicar y revertir sobre la empresa A contra la base de verdad: el ensayo no cambia nada; aplicar con una confirmacion falsificada, de otros datos o
 *      repetida es 409 y no cambia nada; aplicar bien deja A en ambas con UNA fila de auditoria de la biblioteca (modulo edicion) y UNA de la web (edicion_web, con
 *      la IP y sin el token) y NO toca el POS, la credencial de integracion ni el rol user; un tipo que Express no vende se rechaza; volver a completa tambien
 *      pasa por ensayo y confirmacion; verificar da su informe sin secretos.
 *   4) el freno: cinco tokens malos bloquean incluso al token correcto (429 con su Retry-After) y al borrar el estado vuelve a entrar.
 *
 * Nunca produccion: wa_pdo() se niega a usar una base sin la tabla marca zz_write_ahead_scratch y pos_scratch_exigir() una que no se llame wa_scratch o
 * wa_scratch_sin_gate. Las credenciales salen de tools/.env (o del entorno) y no se imprimen. Al terminar, A y B quedan completas como las suites las esperan.
 * No corras a la vez otra suite que use esas bases. El servidor usa el puerto 8296.
 *
 * Uso:
 *   php -d extension=curl -d extension=openssl -d extension=pdo_mysql tools/test_tenant_edicion_web_scratch.php
 * Sale con 1 si algo falla.
 */

require_once __DIR__ . '/pos_scratch_prepare.php';
// pos_scratch_env_api() y pos_scratch_env_base_por_defecto(): el entorno de la API de las suites del POS. Incluirlo solo define funciones.
require_once __DIR__ . '/test_pos_scratch.php';
require_once __DIR__ . '/../src/Admin/TenantEdicionWeb.php';   // solo para saber en que archivo guarda el freno su estado

const TED_MIGRACION_015 = __DIR__ . '/../db/master_migrations/015_tenants_edicion.sql';
const TED_MIGRACION_017 = __DIR__ . '/../db/master_migrations/017_tenants_edicion_ambas.sql';

/** Corre un archivo SQL entero en una sola llamada, como cuando se pega en phpMyAdmin (nextRowset() hace saltar el error de una sentencia intermedia). */
function ted_correr_sql(PDO $pdo, string $sql): void
{
    $stmt = $pdo->query($sql);
    do {
        if ($stmt->columnCount() > 0) {
            $stmt->fetchAll();
        }
    } while ($stmt->nextRowset());
}

/**
 * Deja las bases scratch como las suites del POS las esperan y la master con las migraciones 015 y 017 y las dos empresas de prueba como completas, y deja
 * registrado lo mismo para cuando termine el proceso (tambien si una prueba falla).
 *
 * @return array{master:string, dbA:string, dbB:string, tenantA:int, tenantB:int, cred:array, pdo:PDO}
 */
function ted_preparar(): array
{
    static $restauracionPuesta = false;
    $reiniciar = static function (PDO $pdo): void {
        ted_correr_sql($pdo, (string) file_get_contents(TED_MIGRACION_015));
        ted_correr_sql($pdo, (string) file_get_contents(TED_MIGRACION_017));
        $rncs = array_column(POS_SCRATCH_TENANTS, 'rnc');
        $pdo->prepare(
            "UPDATE tenants SET edicion = 'completa', modulos_habilitados = NULL, tipos_ecf_habilitados = NULL, cert_vence = NULL,
                    cert_path = NULL, cert_pass_encrypted = NULL, grupo_id = NULL
             WHERE rnc IN (" . implode(',', array_fill(0, count($rncs), '?')) . ')'
        )->execute($rncs);
    };
    $info = pos_scratch_preparar();
    $pdo = wa_pdo(POS_SCRATCH_BASE_MASTER);
    $reiniciar($pdo);
    if (!$restauracionPuesta) {
        $restauracionPuesta = true;
        register_shutdown_function(static function () use ($reiniciar): void {
            try {
                pos_scratch_preparar();
                $reiniciar(wa_pdo(POS_SCRATCH_BASE_MASTER));
            } catch (Throwable $e) {
                echo '[FALLO] no se pudo dejar limpia la master scratch al terminar: ' . wa_describir_excepcion($e) . "\n";
                exit(1);
            }
        });
    }
    return $info + ['pdo' => $pdo];
}

$raiz = dirname(__DIR__);
if (is_file($raiz . '/.env')) {
    echo "[FALLO] la raiz del API trae un .env: el servidor lo cargaria y una peticion sin empresa podria caer en la base que diga\n";
    exit(1);
}
$ctx = ted_preparar();
$pdo = $ctx['pdo'];
$idA = (int) $ctx['tenantA'];
$cred = wa_credenciales();
$secretos = pos_scratch_secretos($cred['pass']);
$masterBase = wa_base_real(POS_SCRATCH_BASE_MASTER);
$token = bin2hex(random_bytes(24));

// El estado del freno: lo borra esta suite al empezar y al terminar para no dejar bloqueada a la herramienta (ni heredar el bloqueo de otra corrida).
$archivoFreno = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'tenant_edicion_web_' . substr(hash('sha256', dirname((new ReflectionClass('TenantEdicionWeb'))->getFileName())), 0, 12) . '.json';
$limpiarFreno = static function () use ($archivoFreno): void {
    @unlink($archivoFreno);
};
$limpiarFreno();
register_shutdown_function($limpiarFreno);

$env = array_merge(
    pos_scratch_env_api($cred, $masterBase, $secretos, 'http://127.0.0.1:1', wa_cert_prueba(), false),
    pos_scratch_env_base_por_defecto($cred, $masterBase),
    ['TENANT_EDICION_TOKEN' => $token]
);
$url = wa_servidor('tenant_edicion_web_' . substr(md5($token), 0, 8), 'public/tenant_edicion.php', 8296, $env, '', ['-d', 'date.timezone=' . date_default_timezone_get()]);

/** Una peticion como la manda la pagina: formulario, sin cookies. */
$post = static fn (array $campos): array => wa_http('POST', $url, http_build_query($campos), ['Content-Type: application/x-www-form-urlencoded']);
/** Lo mismo, pero devolviendo las cabeceras de la respuesta (wa_http no las trae): [lineas de cabecera, cuerpo]. */
$conCabeceras = static function (array $campos) use ($url): array {
    $contexto = stream_context_create(['http' => [
        'method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n", 'content' => http_build_query($campos), 'ignore_errors' => true, 'timeout' => 60,
    ]]);
    $cuerpo = (string) @file_get_contents($url, false, $contexto);
    $cabeceras = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
    return [$cabeceras, $cuerpo];
};
$ver = static fn (array $r): array => [$r['status'], pos_scratch_enmascarar(substr($r['body'], 0, 400), array_merge([$token, $cred['pass'], $cred['user'], $cred['host']], array_values($secretos)))];
$cuenta = static fn (string $sql, array $params = []): int => (function () use ($pdo, $sql, $params): int {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (int) $st->fetchColumn();
})();
$fila = static function (int $id) use ($pdo): array {
    $st = $pdo->prepare('SELECT * FROM tenants WHERE id = :id');
    $st->execute([':id' => $id]);
    return (array) $st->fetch();
};
$auditoria = static function (string $modulo) use ($pdo, $idA): array {
    $st = $pdo->prepare('SELECT action, username, ip_address, user_agent, new_values, description, success FROM audit_logs WHERE tenant_id = :t AND module = :m ORDER BY id');
    $st->execute([':t' => $idA, ':m' => $modulo]);
    return $st->fetchAll();
};
$rolUser = static function () use ($pdo, $idA): array {
    $st = $pdo->prepare("SELECT rp.permission FROM role_permissions rp JOIN roles r ON r.id = rp.role_id WHERE r.tenant_id = :t AND r.name = 'user' ORDER BY rp.permission");
    $st->execute([':t' => $idA]);
    return array_column($st->fetchAll(), 'permission');
};
$buenos = ['token' => $token];

// ---------------------------------------------------------------------------
// 1) Las puertas por HTTP
// ---------------------------------------------------------------------------
echo "\n== 1) Las puertas por HTTP ==\n";
$r = wa_http('GET', $url);
wa_chk('un GET es 405 con un cuerpo JSON (status false)', $r['status'] === 405 && ($r['json']['status'] ?? null) === false, $ver($r));
$escriturasAntes = [$cuenta('SELECT COUNT(*) FROM audit_logs'), $cuenta('SELECT COUNT(*) FROM api_tokens')];
$t0 = microtime(true);
$r = $post(['token' => $token . 'x', 'action' => 'meta']);
$tardo = microtime(true) - $t0;
wa_chk('un token malo es 403 "Token invalido." y tarda al menos 0.4 s (frena el tanteo)', $r['status'] === 403 && ($r['json']['error'] ?? null) === 'Token invalido.' && $tardo >= 0.4, [$ver($r), round($tardo, 2)]);
$r2 = $post(['action' => 'meta']);
$r3 = $post(['token' => '', 'action' => 'ensayo', 'tenant' => (string) $idA, 'modo' => 'completa']);
wa_chk('sin token, o con el token vacio, tambien 403 y la peticion no llega a ninguna accion',
    $r2['status'] === 403 && $r3['status'] === 403 && ($r3['json']['status'] ?? null) === false, [$ver($r2), $ver($r3)]);
wa_chk('...y ninguna de las peticiones sin token valido escribio nada en la master (ni auditoria ni sesiones)',
    [$cuenta('SELECT COUNT(*) FROM audit_logs'), $cuenta('SELECT COUNT(*) FROM api_tokens')] === $escriturasAntes, [$cuenta('SELECT COUNT(*) FROM audit_logs'), $escriturasAntes]);
[$cabeceras, $cuerpo] = $conCabeceras(['token' => $token . 'x', 'action' => 'meta']);
$cabecerasTexto = strtolower(implode("\n", $cabeceras));
wa_chk('la respuesta lleva Cache-Control no-store, nosniff, noindex y Referrer-Policy, ninguna cabecera Access-Control y ni rastro del token',
    str_contains($cabecerasTexto, 'cache-control: no-store') && str_contains($cabecerasTexto, 'x-content-type-options: nosniff') && str_contains($cabecerasTexto, 'x-robots-tag: noindex')
    && str_contains($cabecerasTexto, 'referrer-policy: no-referrer') && !str_contains($cabecerasTexto, 'access-control') && !str_contains($cuerpo . implode('', $cabeceras), $token), $cabeceras);
$limpiarFreno();   // los intentos malos de arriba no cuentan para la seccion 4

// ---------------------------------------------------------------------------
// 2) meta
// ---------------------------------------------------------------------------
echo "\n== 2) meta ==\n";
// La fila de A trae una ruta de certificado, una clave cifrada y el hash de integracion: nada de eso puede salir en la lista.
$pdo->prepare('UPDATE tenants SET cert_path = :p, cert_pass_encrypted = :c WHERE id = :id')->execute([':p' => 'certificado_dgii/MARCA-RUTA-SECRETA.p12', ':c' => 'MARCA-CLAVE-CIFRADA', ':id' => $idA]);
$hashIntegracion = (string) ($fila($idA)['api_secret_hash'] ?? '');
$r = $post($buenos + ['action' => 'meta']);
$d = $r['json']['data'] ?? [];
$empresaA = null;
foreach ((array) ($d['empresas'] ?? []) as $e) {
    if (($e['id'] ?? null) === $idA) {
        $empresaA = $e;
    }
}
wa_chk('meta: 200 con las empresas, el preset (los cinco modulos y los tipos 31, 32 y 45 con el 33 opcional) y las migraciones 015 y 017 puestas',
    $r['status'] === 200 && ($r['json']['status'] ?? null) === true && count((array) ($d['empresas'] ?? [])) >= 2 && is_array($empresaA)
    && ($d['presets']['modulos'] ?? null) === ['facturacion', 'clientes', 'productos', 'usuarios', 'configuracion'] && ($d['presets']['tipos_venta'] ?? null) === ['31', '32', '45'] && ($d['presets']['tipo_opcional'] ?? null) === '33'
    && ($d['migraciones'] ?? null) === ['m015' => true, 'm017' => true], $ver($r));
wa_chk('...la empresa A sale con sus campos (completa, app, activa) y SOLO esos: id, nombre, rnc, tipo, activo, ambiente, edicion, edicion_valida, tipos, modulos, cert_vence, pos y en_grupo',
    is_array($empresaA) && $empresaA['edicion'] === 'completa' && $empresaA['tipo'] === 'app' && $empresaA['activo'] === true
    && array_keys($empresaA) === ['id', 'nombre', 'rnc', 'tipo', 'activo', 'ambiente', 'edicion', 'edicion_valida', 'tipos', 'modulos', 'cert_vence', 'pos', 'en_grupo'], $empresaA);
wa_chk('...y la lista no lleva la ruta del certificado, su clave cifrada, el hash de integracion ni ninguna clave de la base (la fila de tenants no se serializa)',
    !str_contains($r['body'], 'MARCA') && !str_contains($r['body'], 'cert_path') && !str_contains($r['body'], 'db_pass') && !str_contains($r['body'], 'api_secret')
    && ($hashIntegracion === '' || !str_contains($r['body'], $hashIntegracion)) && !str_contains($r['body'], $token), $ver($r));

// ---------------------------------------------------------------------------
// 3) Ensayo, aplicar y revertir sobre la empresa A
// ---------------------------------------------------------------------------
echo "\n== 3) Ensayo, aplicar y revertir ==\n";
$p = ['tenant' => (string) $idA, 'modo' => 'ambas', 'tipos' => '31,45', 'modulos' => ''];
$antes = $fila($idA);
$rolAntes = $rolUser();
$audEdicionAntes = count($auditoria('edicion'));
$r = $post($buenos + ['action' => 'ensayo'] + $p);
$d = $r['json']['data'] ?? [];
$confirmacion = (string) ($d['confirmacion'] ?? '');
$salida = implode("\n", (array) ($d['lineas'] ?? []));
wa_chk('ensayo de A a ambas: 200, ok, nada aplicado, el informe de siempre ([CORR] edicion y DRY-RUN, ningun [RECH]) y la confirmacion',
    $r['status'] === 200 && ($d['ok'] ?? null) === true && ($d['aplicado'] ?? null) === false && preg_match('/^\[CORR\] edicion:/m', $salida) === 1 && !str_contains($salida, '[RECH]')
    && str_contains($salida, 'DRY-RUN') && preg_match('/^[0-9]{9,12}\.[0-9a-f]{64}$/D', $confirmacion) === 1, $ver($r));
wa_chk('...y no cambio nada: la fila de A es la de antes y no hay filas de auditoria nuevas', $fila($idA) === $antes && count($auditoria('edicion')) === $audEdicionAntes && count($auditoria('edicion_web')) === 0, null);

foreach ([
    'una confirmacion con una letra cambiada' => [$buenos + ['action' => 'aplicar', 'confirmacion' => substr($confirmacion, 0, -1) . (str_ends_with($confirmacion, '0') ? '1' : '0')] + $p],
    'otros tipos que los del ensayo' => [$buenos + ['action' => 'aplicar', 'confirmacion' => $confirmacion, 'tipos' => '31,32,45'] + $p],
    'otro modo que el del ensayo (solo Express)' => [$buenos + ['action' => 'aplicar', 'confirmacion' => $confirmacion, 'modo' => 'express'] + $p],
    'modulos que el ensayo no tenia' => [$buenos + ['action' => 'aplicar', 'confirmacion' => $confirmacion, 'modulos' => 'facturacion,clientes'] + $p],
] as $desc => [$campos]) {
    $r = $post($campos);
    wa_chk("aplicar con {$desc}: 409 y la fila de A sigue como estaba", $r['status'] === 409 && ($r['json']['status'] ?? null) === false && $fila($idA) === $antes, $ver($r));
}
$r = $post($buenos + ['action' => 'aplicar'] + $p);
wa_chk('aplicar sin confirmacion: 400', $r['status'] === 400 && $fila($idA) === $antes, $ver($r));

$r = $post($buenos + ['action' => 'aplicar', 'confirmacion' => $confirmacion] + $p);
$d = $r['json']['data'] ?? [];
$despues = $fila($idA);
$cambiaron = array_keys(array_filter($despues, static fn ($v, $k): bool => ($antes[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH));
sort($cambiaron);
wa_chk('aplicar con la confirmacion del ensayo: 200, aplicado, "APLICADO: el tenant #A ahora es ambas." y A queda ambas con los tipos 31,45',
    $r['status'] === 200 && ($d['ok'] ?? null) === true && ($d['aplicado'] ?? null) === true && in_array("APLICADO: el tenant #{$idA} ahora es ambas.", (array) ($d['lineas'] ?? []), true)
    && $despues['edicion'] === 'ambas' && $despues['tipos_ecf_habilitados'] === '31,45', $ver($r));
wa_chk('...en su fila solo cambian edicion, tipos_ecf_habilitados y (si se pudo leer el certificado) cert_vence: el POS, la credencial de integracion, el grupo y los datos del certificado quedan, y el rol user conserva sus permisos',
    in_array('edicion', $cambiaron, true) && array_diff($cambiaron, ['edicion', 'tipos_ecf_habilitados', 'cert_vence']) === [] && $rolUser() === $rolAntes, [$cambiaron, $rolUser() === $rolAntes]);
$libreria = $auditoria('edicion');
$web = $auditoria('edicion_web');
wa_chk('...con UNA fila de auditoria nueva de la biblioteca (modulo edicion, EDICION_CAMBIO) y UNA de la web (edicion_web, WEB_APLICAR) con la IP del servidor local, el navegador y los parametros, sin el token',
    count($libreria) === $audEdicionAntes + 1 && $libreria[count($libreria) - 1]['action'] === 'EDICION_CAMBIO' && count($web) === 1 && $web[0]['action'] === 'WEB_APLICAR' && $web[0]['username'] === 'web:tenant_edicion'
    && $web[0]['ip_address'] === '127.0.0.1' && (int) $web[0]['success'] === 1 && str_contains((string) $web[0]['new_values'], '"modo":"ambas"') && !str_contains((string) json_encode($web), $token), [$libreria, $web]);
$r = $post($buenos + ['action' => 'aplicar', 'confirmacion' => $confirmacion] + $p);
wa_chk('repetir exactamente la misma peticion de aplicar: 409 (ya no hay nada que aplicar) y no se escribe una segunda fila', $r['status'] === 409 && count($auditoria('edicion_web')) === 1 && count($auditoria('edicion')) === $audEdicionAntes + 1, $ver($r));
$r = $post($buenos + ['action' => 'ensayo'] + $p);
$d = $r['json']['data'] ?? [];
wa_chk('un ensayo con lo mismo dice SIN CAMBIOS y no da confirmacion', $r['status'] === 200 && ($d['ok'] ?? null) === true && array_key_exists('confirmacion', $d) && $d['confirmacion'] === null && str_contains(implode("\n", (array) ($d['lineas'] ?? [])), 'SIN CAMBIOS:'), [$r['status'], $d['ok'] ?? null, $d['confirmacion'] ?? 'ausente', array_slice((array) ($d['lineas'] ?? []), -2)]);

// lo que se rechaza
$r = $post($buenos + ['action' => 'ensayo', 'tenant' => (string) $idA, 'modo' => 'express', 'tipos' => '99', 'modulos' => '']);
$d = $r['json']['data'] ?? [];
wa_chk('un tipo que Express no vende (99): 200 pero ok false con [RECH] tipos y sin confirmacion (el [RECH] m036 de siempre tambien sale: la migracion de tenant 036 es del plan 04)',
    $r['status'] === 200 && ($d['ok'] ?? null) === false && array_key_exists('confirmacion', $d) && $d['confirmacion'] === null && str_contains(implode("\n", (array) ($d['lineas'] ?? [])), '[RECH] tipos:'),
    [$r['status'], $d['ok'] ?? null, $d['confirmacion'] ?? 'ausente', preg_grep('/^\[RECH\]/', (array) ($d['lineas'] ?? []))]);
$r = $post($buenos + ['action' => 'ensayo', 'tenant' => '2147483000', 'modo' => 'completa']);
$d = $r['json']['data'] ?? [];
wa_chk('una empresa que no existe: ok false con [RECH] tenant', $r['status'] === 200 && ($d['ok'] ?? null) === false && str_contains(implode("\n", (array) ($d['lineas'] ?? [])), '[RECH] tenant:'), $ver($r));
$r = $post($buenos + ['action' => 'ensayo', 'tenant' => 'abc', 'modo' => 'completa']);
wa_chk('una empresa mal escrita (abc): 400 antes de tocar la base', $r['status'] === 400, $ver($r));

// verificar: el informe de solo lectura, sin secretos
$r = $post($buenos + ['action' => 'verificar']);
$d = $r['json']['data'] ?? [];
wa_chk('verificar: 200 con el codigo de verificar_express (0 o 1) y sus lineas, y sin secretos ni el token',
    $r['status'] === 200 && in_array($d['codigo'] ?? null, [0, 1], true) && is_array($d['lineas'] ?? null) && $d['lineas'] !== [] && !str_contains($r['body'], 'MARCA') && !str_contains($r['body'], $token), $ver($r));

// volver a completa: tambien por ensayo y confirmacion
$pc = ['tenant' => (string) $idA, 'modo' => 'completa', 'tipos' => '', 'modulos' => ''];
$r = $post($buenos + ['action' => 'ensayo'] + $pc);
$d = $r['json']['data'] ?? [];
$confirmacionC = (string) ($d['confirmacion'] ?? '');
wa_chk('ensayo de volver a completa: ok, con la advertencia de que la app completa no cambia y la confirmacion', ($d['ok'] ?? null) === true && str_contains(implode("\n", (array) ($d['lineas'] ?? [])), 'la app completa no cambia') && $confirmacionC !== '', $ver($r));
$r = $post($buenos + ['action' => 'aplicar', 'confirmacion' => $confirmacion] + $pc);
wa_chk('la confirmacion del ensayo de ambas no sirve para volver a completa (otro modo): 409 y A sigue ambas', $r['status'] === 409 && $fila($idA)['edicion'] === 'ambas', $ver($r));
$r = $post($buenos + ['action' => 'aplicar', 'confirmacion' => $confirmacionC] + $pc);
wa_chk('aplicar con la confirmacion de volver a completa: A queda completa y la web dejo su segunda fila de auditoria',
    $r['status'] === 200 && ($r['json']['data']['aplicado'] ?? null) === true && $fila($idA)['edicion'] === 'completa' && count($auditoria('edicion_web')) === 2, $ver($r));

// ---------------------------------------------------------------------------
// 4) El freno al tanteo
// ---------------------------------------------------------------------------
echo "\n== 4) El freno al tanteo ==\n";
$limpiarFreno();
$estados = [];
for ($i = 0; $i < TenantEdicionWeb::MAX_FALLOS_IP; $i++) {
    $estados[] = $post(['token' => 'malo-' . $i, 'action' => 'meta'])['status'];
}
wa_chk('cinco tokens malos seguidos dan 403 los cinco', $estados === [403, 403, 403, 403, 403], $estados);
[$cabeceras, $cuerpo] = $conCabeceras($buenos + ['action' => 'meta']);
$json = json_decode($cuerpo, true);
$cabecerasTexto = strtolower(implode("\n", $cabeceras));
wa_chk('el siguiente intento, aun con el token CORRECTO, es 429 con su retry_after (en el cuerpo y en la cabecera Retry-After) y no llega a la base',
    str_contains($cabecerasTexto, ' 429 ') && ($json['status'] ?? null) === false && is_int($json['retry_after'] ?? null) && $json['retry_after'] > 0 && $json['retry_after'] <= TenantEdicionWeb::VENTANA
    && str_contains($cabecerasTexto, 'retry-after: ') && !isset($json['data']), [$cabeceras, $cuerpo]);
$limpiarFreno();
$r = $post($buenos + ['action' => 'meta']);
wa_chk('al borrar el estado del freno el token correcto vuelve a entrar (meta 200)', $r['status'] === 200 && ($r['json']['status'] ?? null) === true, $ver($r));

// Limpieza: A y B completas (tambien lo hace el cierre del proceso) y fuera las filas de auditoria que dejo esta suite.
foreach (['edicion', 'edicion_web'] as $modulo) {
    $pdo->prepare('DELETE FROM audit_logs WHERE tenant_id = :t AND module = :m')->execute([':t' => $idA, ':m' => $modulo]);
}
wa_chk('al terminar, A sigue completa y el rol user de A conserva sus permisos de siempre', $fila($idA)['edicion'] === 'completa' && $rolUser() === $rolAntes, [$fila($idA)['edicion'], $rolUser() === $rolAntes]);

wa_fin();
