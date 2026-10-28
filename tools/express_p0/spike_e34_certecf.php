<?php
/**
 * spike_e34_certecf.php — script de los spikes de P0 de Express que emiten de
 * verdad contra la DGII a traves del API: S3a (E34 con un comprador distinto al
 * de la factura original) y S3b (E34 rechazada y el saldo de la original).
 * Tambien arma el payload minimo que usa la medicion de latencia (S2c, runbook
 * R6) en e31_minimo.json.
 *
 * Solo para el dueno, desde su terminal. Nada se emite sin que el ambiente
 * activo del tenant sea certecf o testecf (se lee de GET /api/facturas/stats y,
 * si es otro, el script se niega y sale con 2). El token de la app se toma SOLO
 * de la variable de entorno EXPRESS_P0_TOKEN: nunca por argumento y nunca se
 * imprime. Cada fase escribe lineas JSON (el estado_dgii y los mensajes de
 * rechazo del servidor, tal cual los devuelve) para pegarlas en el archivo de
 * resultados.
 *
 * Fases:
 *   original   emite una E31 (una linea libre de 1000) y consulta su estado cada
 *              15 s, hasta 10 min, hasta que la DGII la acepta.
 *   nota       emite una E34 que anula --original; con --forzar-rechazo lleva
 *              fecha_vencimiento_secuencia 31-12-2000 (para que la DGII la rechace).
 *   reintento  emite la E34 con la secuencia vencida y, solo si la DGII la
 *              RECHAZA, una E34 normal por el monto completo. Lee el saldo de la
 *              original (GET /api/facturas/modificables) antes, entre y despues.
 *              Si la de 2000 no queda RECHAZADA no hay segunda E34 y sale con 3:
 *              con ACEPTADO imprime INCONCLUSO (la DGII acepto la secuencia de
 *              2000); con cualquier otro estado (ERROR, ACEPTADO_CONDICIONAL, en
 *              proceso tras los 10 min) imprime SIN VEREDICTO y el estado_dgii
 *              observado, que tambien va en estado_dgii_forzada de la linea resultado.
 * Codigos de salida: 0 bien, 1 fallo o sin veredicto de una emision, 2 uso o
 * ambiente no permitido, 3 la nota de 2000 no quedo RECHAZADA (el spike no sigue).
 *
 * Incluirlo solo define funciones y constantes (las prueba
 * tools/test_express_p0_spikes_puro.php): el CLI corre unicamente cuando este es
 * el archivo que se ejecuta.
 *
 * El token no vence y viaja en la cabecera X-API-KEY: --api tiene que ser https (http
 * solo hacia 127.0.0.1, localhost o [::1], para probar contra un API local), y las
 * redirecciones no se siguen. Con un http hacia otro equipo el token saldria en claro.
 *
 * Uso:
 *   export EXPRESS_P0_TOKEN=<token de la app del tenant>
 *   php -d extension=curl tools/express_p0/spike_e34_certecf.php --api=https://<host>/api \
 *       --client-id=<id> --fase=original|nota|reintento [--original=<e-NCF>] \
 *       [--fecha-original=DD-MM-YYYY] [--forzar-rechazo] [--seco]
 *   --seco imprime los payloads y no envia nada (ni pide token ni toca la red).
 */

/** Veredictos finales de la DGII: con uno de estos deja de consultarse el estado. NO_ENCONTRADO no entra: puede ser demora de la DGII. */
const SPIKE_ESTADOS_FINALES = ['ACEPTADO', 'ACEPTADO_CONDICIONAL', 'RECHAZADO', 'ERROR'];
const SPIKE_ESPERA_INTERVALO_S = 15;
const SPIKE_ESPERA_MAXIMA_S = 600;
const SPIKE_VENCIMIENTO_FORZADO = '31-12-2000';
const SPIKE_INCONCLUSO = 'INCONCLUSO: la DGII acepto la secuencia de 2000';
const SPIKE_SIN_VEREDICTO = 'SIN VEREDICTO';
const SPIKE_USO = 'Uso: EXPRESS_P0_TOKEN=<token> php -d extension=curl tools/express_p0/spike_e34_certecf.php --api=<base https que termina en /api> '
    . '--client-id=<id> --fase=original|nota|reintento [--original=<e-NCF>] [--fecha-original=DD-MM-YYYY] [--forzar-rechazo] [--seco]';
/** Hosts de este equipo: el unico destino al que el token puede ir por http. */
const SPIKE_HOSTS_LOOPBACK = ['127.0.0.1', 'localhost', '[::1]'];

/** La unica linea de los spikes de nota: un servicio libre (sin product_id) de 1000, para que original y notas sumen lo mismo. */
function spike_items(): array
{
    return [[
        'nombre_item' => 'Spike P0 servicio',
        'indicador_facturacion' => 1,
        'indicador_bien_servicio' => 1,
        'cantidad' => 1,
        'precio_unitario' => 1000.0,
    ]];
}

/** E31 de la fase `original`: una linea libre de 1000 al cliente indicado. */
function spike_payload_original(int $clientId): array
{
    return ['tipo_ecf' => '31', 'client_id' => $clientId, 'tipo_pago' => 1, 'items' => spike_items()];
}

/**
 * E34 que anula (codigo 1) la factura $eNcfOriginal, con las mismas lineas que la
 * original (monto completo). rnc_otro_contribuyente va null: con el RNC del
 * comprador la DGII responde error 614. fecha_vencimiento_secuencia solo va si se
 * da (la fase de rechazo forzado manda una ya vencida).
 */
function spike_payload_nota(int $clientId, string $eNcfOriginal, string $fechaOriginalDDMMYYYY, ?string $fechaVencSecuencia = null): array
{
    $payload = [
        'tipo_ecf' => '34',
        'client_id' => $clientId,
        'tipo_pago' => 1,
        'items' => spike_items(),
        'informacion_referencia' => [
            'ncf_modificado' => $eNcfOriginal,
            'rnc_otro_contribuyente' => null,
            'fecha_ncf_modificado' => $fechaOriginalDDMMYYYY,
            'codigo_modificacion' => '1',
            'razon_modificacion' => 'Spike P0: anulacion',
        ],
    ];
    if ($fechaVencSecuencia !== null) {
        $payload['fecha_vencimiento_secuencia'] = $fechaVencSecuencia;
    }
    return $payload;
}

/** Solo certecf y testecf: en ecf (produccion) estos spikes no emiten. Un ambiente desconocido (null) tampoco. */
function spike_ambiente_permitido(?string $ambiente): bool
{
    return $ambiente === 'certecf' || $ambiente === 'testecf';
}

/**
 * La E34 de secuencia vencida cuenta como rechazo solo si la DGII la RECHAZO. Un
 * ACEPTADO deja el spike inconcluso (la original ya quedo acreditada por
 * completo); ERROR, null y los estados en vuelo no son un veredicto.
 */
function spike_rechazo_valido(?string $estadoDgii): bool
{
    return $estadoDgii === 'RECHAZADO';
}

/**
 * Que se imprime (y que 'veredicto' lleva la linea resultado) cuando la E34 de
 * secuencia vencida NO quedo RECHAZADA. Solo un ACEPTADO es "la DGII acepto la
 * secuencia de 2000" (INCONCLUSO). Cualquier otro estado (ERROR, ACEPTADO_CONDICIONAL,
 * EN_PROCESO o ENVIADO tras los 10 min, null) es SIN VEREDICTO y dice el
 * estado_dgii observado: no se puede afirmar que la DGII aceptara nada, y con ERROR
 * la nota ni siquiera cuenta en el saldo de la original (ESTADOS_NOTA_VIGENTE).
 *
 * @return array{veredicto:string,mensaje:string}
 */
function spike_resultado_sin_rechazo(?string $estadoDgii): array
{
    if ($estadoDgii === 'ACEPTADO') {
        return ['veredicto' => 'inconcluso', 'mensaje' => SPIKE_INCONCLUSO];
    }
    return [
        'veredicto' => 'sin_veredicto',
        'mensaje' => SPIKE_SIN_VEREDICTO . ': la nota de la secuencia de 2000 termino con estado_dgii=' . ($estadoDgii ?? 'desconocido')
            . ', no RECHAZADO; no se manda la segunda E34. Mira estado_dgii_forzada en la linea resultado.',
    ];
}

function spike_estado_final(?string $estado): bool
{
    return in_array($estado, SPIKE_ESTADOS_FINALES, true);
}

/**
 * true si el token de la app puede viajar a $url: https, o http solo hacia este equipo
 * (127.0.0.1, localhost o [::1]). El origen tiene que ser host[:puerto] pelado: un
 * "usuario@" delante ("http://127.0.0.1@otro.com/api") pasaria por loopback y mandaria el
 * token a otro host. El token no vence y va en la cabecera X-API-KEY: por http saldria en
 * claro, y una redireccion de un http cualquiera lo reenviaria al host nuevo.
 */
function spike_url_segura(string $url): bool
{
    if (preg_match('#^(https?)://(\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9.-]+)(?::\d{1,5})?(?:[/?\#]|$)#', $url, $m) !== 1) {
        return false;
    }
    return $m[1] === 'https' || in_array(strtolower($m[2]), SPIKE_HOSTS_LOOPBACK, true);
}

/** Una linea JSON por evento, con el texto del servidor sin tocar. */
function spike_linea(array $datos): void
{
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n";
}

/**
 * Lee los argumentos (sin el nombre del script). Devuelve las opciones o, si algo
 * esta mal, el texto del error.
 *
 * @return array{api:string,client_id:int,fase:string,original:?string,fecha_original:?string,forzar_rechazo:bool,seco:bool}|string
 */
function spike_opciones(array $args): array|string
{
    $o = ['api' => '', 'client_id' => '', 'fase' => '', 'original' => null, 'fecha_original' => null, 'forzar_rechazo' => false, 'seco' => false];
    foreach ($args as $a) {
        $a = (string) $a;
        if ($a === '--forzar-rechazo') {
            $o['forzar_rechazo'] = true;
        } elseif ($a === '--seco') {
            $o['seco'] = true;
        } elseif (preg_match('/^--(api|client-id|fase|original|fecha-original)=(.*)$/s', $a, $m) === 1) {
            $o[str_replace('-', '_', $m[1])] = $m[2];
        } else {
            // Solo el nombre de una opcion --nombre: un valor mal puesto, o un argumento suelto, podria ser un token.
            return str_starts_with($a, '--')
                ? 'Argumento desconocido: ' . explode('=', $a, 2)[0]
                : 'Argumento desconocido: un valor suelto (las opciones van como --nombre=valor).';
        }
    }
    $o['api'] = rtrim((string) $o['api'], '/');
    if (preg_match('#^https?://[^/\s]+(?:/\S*)?/api\z#', $o['api']) !== 1) {
        return '--api es obligatorio: la base del API, que termina en /api (por ejemplo https://<host>/api).';
    }
    if (!spike_url_segura($o['api'])) {
        return '--api debe ser https: el token de la app viaja en la cabecera X-API-KEY, no vence y por http saldria en claro. '
            . 'http solo se acepta hacia 127.0.0.1, localhost o [::1] (un API local), y sin usuario@ delante.';
    }
    if (preg_match('/^[1-9]\d*\z/', (string) $o['client_id']) !== 1) {
        return '--client-id es obligatorio: el id (entero positivo) del cliente de los spikes.';
    }
    $o['client_id'] = (int) $o['client_id'];
    if (!in_array($o['fase'], ['original', 'nota', 'reintento'], true)) {
        return '--fase es obligatoria: original, nota o reintento.';
    }
    if ($o['fase'] === 'original') {
        if ($o['original'] !== null || $o['fecha_original'] !== null || $o['forzar_rechazo']) {
            return 'La fase original no usa --original, --fecha-original ni --forzar-rechazo.';
        }
        return $o;
    }
    if (preg_match('/^E\d{12}\z/', (string) $o['original']) !== 1) {
        return 'La fase ' . $o['fase'] . ' necesita --original=<e-NCF de la factura a anular> (E y 12 digitos).';
    }
    if ($o['fecha_original'] !== null && preg_match('/^\d{2}-\d{2}-\d{4}\z/', $o['fecha_original']) !== 1) {
        return '--fecha-original va como DD-MM-YYYY.';
    }
    if ($o['fase'] === 'reintento' && $o['forzar_rechazo']) {
        return 'La fase reintento ya fuerza el rechazo por si sola: no lleva --forzar-rechazo.';
    }
    return $o;
}

/**
 * Una llamada al API con el token en X-API-KEY. Nunca lanza: devuelve el codigo
 * HTTP (0 si no hubo respuesta), el JSON si lo hay, el cuerpo crudo y el error de curl.
 *
 * @return array{status:int,json:?array,crudo:string,error:?string}
 */
function spike_http(string $metodo, string $url, string $token, ?array $cuerpo = null, int $timeout = 90): array
{
    // Ultima defensa: aunque quien llame se salte spike_opciones, el token no sale en claro hacia otro equipo.
    if (!spike_url_segura($url)) {
        return ['status' => 0, 'json' => null, 'crudo' => '', 'error' => 'URL rechazada: el token de la app solo viaja por https (http solo hacia este equipo)'];
    }
    $cabeceras = ['X-API-KEY: ' . $token, 'Accept: application/json'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $metodo,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false, // una redireccion reenviaria X-API-KEY al host nuevo
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => $timeout,
    ]);
    if ($cuerpo !== null) {
        $cabeceras[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $cabeceras);
    $crudo = curl_exec($ch);
    $error = $crudo === false ? curl_error($ch) : null;
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $json = is_string($crudo) ? json_decode($crudo, true) : null;
    return ['status' => $status, 'json' => is_array($json) ? $json : null, 'crudo' => is_string($crudo) ? $crudo : '', 'error' => $error];
}

/** Lee el ambiente activo del tenant y dice si se puede emitir. Imprime la razon cuando no. */
function spike_ambiente_ok(array $ctx): bool
{
    $r = spike_http('GET', $ctx['api'] . '/facturas/stats', $ctx['token'], null, 60);
    $ambiente = $r['json']['data']['ambiente_activo'] ?? null;
    $ambiente = is_string($ambiente) ? $ambiente : null;
    spike_linea(['evento' => 'ambiente', 'http' => $r['status'], 'ambiente_activo' => $ambiente, 'error' => $r['json']['error'] ?? $r['error']]);
    if (!spike_ambiente_permitido($ambiente)) {
        echo $ambiente === null
            ? "REHUSADO: no pude leer el ambiente activo del tenant (mira http y error arriba); sin saberlo no se emite.\n"
            : 'REHUSADO: el ambiente activo es ' . $ambiente . '; estos spikes solo emiten en certecf o testecf.' . "\n";
        return false;
    }
    return true;
}

/**
 * Una lectura del saldo: la fila de la original en GET /api/facturas/modificables
 * (total, notas_credito, saldo, fecha_emision). Imprime la consulta y la fila y la devuelve.
 */
function spike_saldo(array $ctx, string $momento, string $eNcf): ?array
{
    $consulta = '/facturas/modificables?' . http_build_query(['client_id' => $ctx['client_id'], 'query' => $eNcf, 'limit' => 1]);
    $r = spike_http('GET', $ctx['api'] . $consulta, $ctx['token'], null, 60);
    $fila = $r['json']['data'][0] ?? null;
    $fila = is_array($fila) ? $fila : null;
    spike_linea(['evento' => 'saldo', 'momento' => $momento, 'consulta' => 'GET /api' . $consulta, 'http' => $r['status'], 'fila' => $fila, 'error' => $r['json']['error'] ?? $r['error']]);
    return $fila;
}

/**
 * Manda el payload a POST /api/facturas. Devuelve factura_id, e_ncf y estado de la
 * fila que quedo guardada (un rechazo de la DGII tambien guarda fila), o null si no
 * hay fila que seguir: rechazo local del API, error de red o fallo del servidor.
 *
 * @return array{factura_id:int,e_ncf:?string,estado:?string}|null
 */
function spike_emitir(array $ctx, string $rotulo, array $payload): ?array
{
    $r = spike_http('POST', $ctx['api'] . '/facturas', $ctx['token'], $payload, 180);
    $data = is_array($r['json']['data'] ?? null) ? $r['json']['data'] : [];
    spike_linea([
        'evento' => 'emision', 'rotulo' => $rotulo, 'http' => $r['status'],
        'estado_dgii' => $data['estado_dgii'] ?? null, 'factura_id' => $data['factura_id'] ?? null,
        'e_ncf' => $data['e_ncf'] ?? null, 'track_id' => $data['track_id'] ?? null,
        'error' => $r['json']['error'] ?? $r['error'], 'dgii_response' => $data['dgii_response'] ?? null,
        'cuerpo_no_json' => $r['json'] === null && $r['crudo'] !== '' ? substr($r['crudo'], 0, 300) : null,
    ]);
    if ($r['error'] !== null || $r['status'] >= 500) {
        echo 'AVISO: no se sabe si la DGII recibio el comprobante. No lo emitas de nuevo: revisa Facturacion (filtro Pendientes) y el e-NCF que dice la linea de arriba.' . "\n";
        return null;
    }
    if (!isset($data['factura_id'])) {
        return null;
    }
    return ['factura_id' => (int) $data['factura_id'], 'e_ncf' => $data['e_ncf'] ?? null, 'estado' => $data['estado_dgii'] ?? null];
}

/**
 * Consulta GET /api/facturas/{id}/estado cada $intervaloS segundos, hasta $maxS,
 * mientras el estado no sea un veredicto final. Devuelve el ultimo estado conocido.
 */
function spike_esperar_veredicto(array $ctx, int $facturaId, ?string $estado, int $maxS = SPIKE_ESPERA_MAXIMA_S, int $intervaloS = SPIKE_ESPERA_INTERVALO_S): ?string
{
    $limite = time() + $maxS;
    while (!spike_estado_final($estado)) {
        $r = spike_http('GET', $ctx['api'] . '/facturas/' . $facturaId . '/estado', $ctx['token'], null, 60);
        $data = is_array($r['json']['data'] ?? null) ? $r['json']['data'] : [];
        if (is_string($data['estado_dgii'] ?? null)) {
            $estado = $data['estado_dgii'];
        }
        spike_linea([
            'evento' => 'consulta_estado', 'factura_id' => $facturaId, 'http' => $r['status'], 'estado_dgii' => $estado,
            'consulta' => $data['consulta'] ?? null, 'nota' => $data['nota'] ?? null, 'error' => $r['json']['error'] ?? $r['error'],
        ]);
        if (spike_estado_final($estado)) {
            break;
        }
        if (time() + $intervaloS > $limite) {
            echo 'AVISO: pasaron ' . $maxS . ' s sin veredicto de la DGII para la factura ' . $facturaId . ' (ultimo estado: ' . ($estado ?? 'desconocido') . ").\n";
            break;
        }
        sleep($intervaloS);
    }
    return $estado;
}

/** La fecha de la original: la del argumento o, si falta, la que dice modificables ($fila). null si no se sabe. */
function spike_fecha_original(array $o, ?array $fila): ?string
{
    $fecha = $o['fecha_original'] ?? ($fila['fecha_emision'] ?? null);
    return is_string($fecha) && preg_match('/^\d{2}-\d{2}-\d{4}\z/', $fecha) === 1 ? $fecha : null;
}

/** Fase original: E31 y espera a ACEPTADO. Sale con 0 solo si la DGII la acepto. */
function spike_fase_original(array $ctx): int
{
    $res = spike_emitir($ctx, 'original', spike_payload_original($ctx['client_id']));
    if ($res === null) {
        return 1;
    }
    $estado = spike_esperar_veredicto($ctx, $res['factura_id'], $res['estado']);
    if ($estado !== 'ACEPTADO') {
        echo 'La original no quedo ACEPTADA (estado: ' . ($estado ?? 'desconocido') . '): el spike no puede seguir con ella.' . "\n";
        return 1;
    }
    $fila = spike_saldo($ctx, 'despues_original', (string) $res['e_ncf']);
    $fecha = $fila['fecha_emision'] ?? '<DD-MM-YYYY de la factura>';
    echo 'Original lista. Siguiente paso: --fase=nota (o --fase=reintento) --original=' . $res['e_ncf'] . ' --fecha-original=' . $fecha . "\n";
    return 0;
}

/** Fase nota: una E34 (con --forzar-rechazo, con la secuencia vencida). Sale con 0 si la DGII dio un veredicto, sea cual sea. */
function spike_fase_nota(array $ctx, array $o): int
{
    $antes = spike_saldo($ctx, 'antes', $o['original']);
    $fecha = spike_fecha_original($o, $antes);
    if ($fecha === null) {
        echo 'No encuentro la fecha de la factura ' . $o['original'] . ' (no sale en modificables de este cliente): pasala con --fecha-original=DD-MM-YYYY.' . "\n";
        return 1;
    }
    $venc = $o['forzar_rechazo'] ? SPIKE_VENCIMIENTO_FORZADO : null;
    $res = spike_emitir($ctx, $venc === null ? 'nota' : 'nota_rechazo_forzado', spike_payload_nota($ctx['client_id'], $o['original'], $fecha, $venc));
    if ($res === null) {
        return 1;
    }
    $estado = spike_esperar_veredicto($ctx, $res['factura_id'], $res['estado']);
    spike_saldo($ctx, 'despues', $o['original']);
    spike_linea(['evento' => 'resultado', 'fase' => 'nota', 'forzar_rechazo' => $o['forzar_rechazo'], 'e_ncf_nota' => $res['e_ncf'], 'estado_dgii' => $estado]);
    return spike_estado_final($estado) ? 0 : 1;
}

/** Fase reintento: E34 de secuencia vencida y, solo si la DGII la rechaza, una E34 normal por el monto completo. */
function spike_fase_reintento(array $ctx, array $o): int
{
    $antes = spike_saldo($ctx, 'antes', $o['original']);
    $fecha = spike_fecha_original($o, $antes);
    if ($fecha === null) {
        echo 'No encuentro la fecha de la factura ' . $o['original'] . ' (no sale en modificables de este cliente): pasala con --fecha-original=DD-MM-YYYY.' . "\n";
        return 1;
    }
    $forzada = spike_emitir($ctx, 'nota_rechazo_forzado', spike_payload_nota($ctx['client_id'], $o['original'], $fecha, SPIKE_VENCIMIENTO_FORZADO));
    if ($forzada === null) {
        return 1;
    }
    $estadoForzada = spike_esperar_veredicto($ctx, $forzada['factura_id'], $forzada['estado']);
    spike_saldo($ctx, 'entre', $o['original']);
    if (!spike_rechazo_valido($estadoForzada)) {
        $sin = spike_resultado_sin_rechazo($estadoForzada);
        spike_linea(['evento' => 'resultado', 'fase' => 'reintento', 'e_ncf_forzada' => $forzada['e_ncf'], 'estado_dgii_forzada' => $estadoForzada, 'veredicto' => $sin['veredicto']]);
        echo $sin['mensaje'] . "\n";
        return 3;
    }
    $normal = spike_emitir($ctx, 'nota_normal', spike_payload_nota($ctx['client_id'], $o['original'], $fecha));
    if ($normal === null) {
        return 1;
    }
    $estadoNormal = spike_esperar_veredicto($ctx, $normal['factura_id'], $normal['estado']);
    spike_saldo($ctx, 'despues', $o['original']);
    spike_linea([
        'evento' => 'resultado', 'fase' => 'reintento',
        'e_ncf_forzada' => $forzada['e_ncf'], 'estado_dgii_forzada' => $estadoForzada,
        'e_ncf_normal' => $normal['e_ncf'], 'estado_dgii_normal' => $estadoNormal,
    ]);
    return spike_estado_final($estadoNormal) ? 0 : 1;
}

/** --seco: imprime los payloads de la fase y no envia nada. */
function spike_seco(array $o): int
{
    if ($o['fase'] === 'original') {
        spike_linea(['evento' => 'payload', 'rotulo' => 'original', 'payload' => spike_payload_original($o['client_id'])]);
        return 0;
    }
    $fecha = $o['fecha_original'] ?? 'DD-MM-YYYY';
    $forzada = $o['fase'] === 'reintento' || $o['forzar_rechazo'];
    spike_linea(['evento' => 'payload', 'rotulo' => $forzada ? 'nota_rechazo_forzado' : 'nota',
        'payload' => spike_payload_nota($o['client_id'], $o['original'], $fecha, $forzada ? SPIKE_VENCIMIENTO_FORZADO : null)]);
    if ($o['fase'] === 'reintento') {
        spike_linea(['evento' => 'payload', 'rotulo' => 'nota_normal', 'payload' => spike_payload_nota($o['client_id'], $o['original'], $fecha)]);
    }
    return 0;
}

/** Corre el CLI con los argumentos (sin el nombre del script) y devuelve el codigo de salida. */
function spike_cli(array $args): int
{
    $o = spike_opciones($args);
    if (is_string($o)) {
        echo $o . "\n" . SPIKE_USO . "\n";
        return 2;
    }
    if ($o['seco']) {
        return spike_seco($o);
    }
    if (!function_exists('curl_init')) {
        echo "Falta la extension curl: corre php -d extension=curl tools/express_p0/spike_e34_certecf.php ...\n";
        return 2;
    }
    $token = getenv('EXPRESS_P0_TOKEN');
    if (!is_string($token) || trim($token) === '') {
        echo "Falta la variable de entorno EXPRESS_P0_TOKEN (el token de la app del tenant; no se acepta por argumento).\n";
        return 2;
    }
    $ctx = ['api' => $o['api'], 'token' => trim($token), 'client_id' => $o['client_id']];
    if (!spike_ambiente_ok($ctx)) {
        return 2;
    }
    return match ($o['fase']) {
        'original' => spike_fase_original($ctx),
        'nota' => spike_fase_nota($ctx, $o),
        default => spike_fase_reintento($ctx, $o),
    };
}

// CLI propio: solo cuando se ejecuta este archivo, nunca al incluirlo.
if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(spike_cli(array_slice($_SERVER['argv'] ?? [], 1)));
}
