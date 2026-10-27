<?php
/**
 * express_golden_lib.php — Nucleo puro de la captura dorada de Express (P0):
 * compara estados HTTP y la FORMA de los JSON (claves y tipos de valor), nunca
 * los valores, porque tokens, ids, fechas y e-NCF cambian en cada corrida.
 *
 *   - golden_forma: forma de un JSON decodificado (escalares a su tipo, objetos
 *     con claves ordenadas, listas como la union de la forma de sus filas).
 *   - golden_unir / golden_diff: union de dos formas y diferencias entre dos.
 *   - golden_paso: un paso registrado (id, metodo, ruta, http y forma).
 *   - golden_matriz / golden_matriz_escrituras: pares ruta x rol para la matriz
 *     de la compuerta; las escrituras son sondas con cuerpo vacio que se
 *     rechazan antes de tocar una fila.
 *   - golden_aviso_php: aviso cuando la base se capturo con otra version de PHP.
 *
 * Incluirlo solo define constantes y funciones: no corre nada, no abre
 * conexiones, no arranca servidores. PHP 8.3, sin dependencias.
 *
 * Uso:
 *   require_once __DIR__ . '/express_golden_lib.php';
 *   $paso = golden_paso('login', 'POST', '/api/auth/login', 200, $cuerpo);
 *   $dif = golden_diff($base['pasos'][0], $paso);
 */

/** Version del formato de golden.json. */
const GOLDEN_VERSION = 1;

/**
 * Sondas de escritura de la matriz de la compuerta (solo modo enforce). Cuerpo
 * vacio ('' o '{}'): ninguna lleva datos ni un id existente, asi que cada una se
 * rechaza (405 o error de validacion) antes de tocar una fila.
 *   - facturas: el controlador responde 405 a todos (facturaController.php:157-171).
 *   - clients, products y users: sin id, error de validacion antes de tocar nada
 *     (clientController.php:173,225; productController.php:316; userController.php:139).
 *   - ncf: "Factura ID is required" (ncfController.php:159-166).
 */
const GOLDEN_ESCRITURAS = [
    ['metodo' => 'POST', 'ruta' => '/api/facturas', 'cuerpo' => '{}', 'roles' => ['user', 'anonimo']],
    ['metodo' => 'PUT', 'ruta' => '/api/facturas/5', 'cuerpo' => '{}', 'roles' => ['admin', 'user', 'anonimo']],
    ['metodo' => 'DELETE', 'ruta' => '/api/facturas/5', 'cuerpo' => '', 'roles' => ['admin', 'user', 'anonimo']],
    ['metodo' => 'POST', 'ruta' => '/api/clients', 'cuerpo' => '{}', 'roles' => ['user', 'anonimo']],
    ['metodo' => 'PUT', 'ruta' => '/api/clients', 'cuerpo' => '{}', 'roles' => ['user', 'anonimo']],
    ['metodo' => 'DELETE', 'ruta' => '/api/clients', 'cuerpo' => '{}', 'roles' => ['user', 'anonimo']],
    ['metodo' => 'POST', 'ruta' => '/api/products', 'cuerpo' => '{}', 'roles' => ['user', 'anonimo']],
    ['metodo' => 'PUT', 'ruta' => '/api/products', 'cuerpo' => '{}', 'roles' => ['user', 'anonimo']],
    ['metodo' => 'DELETE', 'ruta' => '/api/products', 'cuerpo' => '{}', 'roles' => ['user', 'anonimo']],
    ['metodo' => 'POST', 'ruta' => '/api/users', 'cuerpo' => '{}', 'roles' => ['user', 'anonimo']],
    ['metodo' => 'PUT', 'ruta' => '/api/users', 'cuerpo' => '{}', 'roles' => ['user', 'anonimo']],
    ['metodo' => 'DELETE', 'ruta' => '/api/users', 'cuerpo' => '{}', 'roles' => ['user', 'anonimo']],
    ['metodo' => 'POST', 'ruta' => '/api/ncf/rangos', 'cuerpo' => '{}', 'roles' => ['user', 'anonimo']],
    ['metodo' => 'PUT', 'ruta' => '/api/ncf', 'cuerpo' => '{}', 'roles' => ['user', 'anonimo']],
];

/**
 * Forma de un JSON decodificado (json_decode con assoc): los escalares pasan a
 * su tipo ('number' para int y float, 'string', 'bool', 'null'), los objetos
 * conservan sus claves ordenadas y una lista pasa a ['__lista__' => <union de
 * la forma de sus filas>] (['__lista__' => null] si esta vacia). Dos respuestas
 * con distintos tokens, ids, fechas o e-NCF dan la misma forma.
 */
function golden_forma(mixed $v): mixed
{
    if (is_array($v)) {
        if (array_is_list($v)) {
            $union = null;
            foreach ($v as $fila) {
                $union = golden_unir($union, golden_forma($fila));
            }
            return ['__lista__' => $union];
        }
        $forma = [];
        foreach ($v as $clave => $valor) {
            $forma[$clave] = golden_forma($valor);
        }
        ksort($forma, SORT_STRING);
        return $forma;
    }
    return match (true) {
        is_int($v), is_float($v) => 'number',
        is_string($v) => 'string',
        is_bool($v) => 'bool',
        default => 'null',
    };
}

/**
 * Union de dos formas, igual sin importar el orden de los argumentos (el orden
 * de las filas de una lista cambia entre corridas):
 *   - iguales: se queda como esta;
 *   - null (una lista vacia, sin filas que mirar) cede ante la otra forma;
 *   - dos arrays: se unen clave por clave, con las claves ordenadas;
 *   - dos escalares distintos: 'a|b' con las alternativas ordenadas y sin repetir;
 *   - un array contra un escalar: 'mixto', que absorbe cualquier union posterior.
 */
function golden_unir(mixed $a, mixed $b): mixed
{
    if ($a === $b || $b === null) {
        return $a;
    }
    if ($a === null) {
        return $b;
    }
    if (is_array($a) && is_array($b)) {
        $union = $a;
        foreach ($b as $clave => $valor) {
            $union[$clave] = array_key_exists($clave, $a) ? golden_unir($a[$clave], $valor) : $valor;
        }
        ksort($union, SORT_STRING);
        return $union;
    }
    if (is_array($a) || is_array($b) || $a === 'mixto' || $b === 'mixto') {
        return 'mixto';
    }
    $alternativas = array_unique(array_merge(explode('|', (string) $a), explode('|', (string) $b)));
    sort($alternativas, SORT_STRING);
    return implode('|', $alternativas);
}

/**
 * Diferencias entre dos estructuras (una captura base y una nueva), en el orden
 * de las claves de la base y luego las claves nuevas. Cada linea es
 * "<ruta>: <json base> -> <json nuevo>", "<ruta>: falta" (esta en la base y no
 * en la nueva) o "<ruta>: sobra" (esta en la nueva y no en la base); los
 * segmentos de la ruta van unidos por ".". Vacio si son iguales.
 *
 * @return list<string>
 */
function golden_diff(mixed $base, mixed $nuevo, string $ruta = ''): array
{
    if (!is_array($base) || !is_array($nuevo)) {
        if ($base === $nuevo) {
            return [];
        }
        // Rutas y acentos sin escapar; un UTF-8 roto no impide informar la diferencia.
        $hoja = static fn (mixed $v): string => (string) json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        return ["{$ruta}: " . $hoja($base) . ' -> ' . $hoja($nuevo)];
    }
    $camino = static fn (int|string $clave): string => $ruta === '' ? (string) $clave : "{$ruta}.{$clave}";
    $dif = [];
    foreach ($base as $clave => $valor) {
        if (!array_key_exists($clave, $nuevo)) {
            $dif[] = $camino($clave) . ': falta';
            continue;
        }
        array_push($dif, ...golden_diff($valor, $nuevo[$clave], $camino($clave)));
    }
    foreach ($nuevo as $clave => $_) {
        if (!array_key_exists($clave, $base)) {
            $dif[] = $camino($clave) . ': sobra';
        }
    }
    return $dif;
}

/**
 * Un paso registrado de la captura: sin los valores de la respuesta, solo su
 * forma. 'vacio' si el cuerpo viene vacio o es null, 'no-json' si no se puede
 * decodificar como JSON.
 *
 * @return array{id: string, metodo: string, ruta: string, http: int, forma: mixed}
 */
function golden_paso(string $id, string $metodo, string $ruta, int $http, ?string $cuerpo): array
{
    if ($cuerpo === null || trim($cuerpo) === '') {
        $forma = 'vacio';
    } else {
        $decodificado = json_decode($cuerpo, true);
        $forma = json_last_error() === JSON_ERROR_NONE ? golden_forma($decodificado) : 'no-json';
    }
    return ['id' => $id, 'metodo' => $metodo, 'ruta' => $ruta, 'http' => $http, 'forma' => $forma];
}

/**
 * Matriz de la compuerta: una fila por ruta y rol, con las rutas por fuera y los
 * roles por dentro.
 *
 * @param list<string> $rutas claves de config/permissions.php (se publican como '/api/<clave>')
 * @param list<string> $roles
 * @return list<array{metodo: string, ruta: string, rol: string}>
 */
function golden_matriz(array $rutas, array $roles, string $metodo = 'GET'): array
{
    $filas = [];
    foreach ($rutas as $clave) {
        foreach ($roles as $rol) {
            $filas[] = ['metodo' => $metodo, 'ruta' => '/api/' . $clave, 'rol' => $rol];
        }
    }
    return $filas;
}

/**
 * GOLDEN_ESCRITURAS expandida a una fila por sonda y rol, con las sondas por
 * fuera y los roles por dentro.
 *
 * @return list<array{metodo: string, ruta: string, rol: string, cuerpo: string}>
 */
function golden_matriz_escrituras(): array
{
    $filas = [];
    foreach (GOLDEN_ESCRITURAS as $sonda) {
        foreach ($sonda['roles'] as $rol) {
            $filas[] = ['metodo' => $sonda['metodo'], 'ruta' => $sonda['ruta'], 'rol' => $rol, 'cuerpo' => $sonda['cuerpo']];
        }
    }
    return $filas;
}

/**
 * Aviso cuando la base dorada se capturo con otra version de PHP que la actual
 * (round() cambia entre menores): null si coinciden. Misma redaccion que
 * baseline_comparar en tools/express_baseline.php.
 */
function golden_aviso_php(string $base, string $ahora): ?string
{
    return $base === $ahora ? null : "PHP {$base} en la base, {$ahora} ahora";
}
