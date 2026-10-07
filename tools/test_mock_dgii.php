<?php
/**
 * test_mock_dgii.php — la DGII falsa (tools/mock_dgii.php) contestada por las
 * clases reales: DgiiAuthService (semilla) y DgiiReceptionService (recepcion
 * y consultas). Sin base de datos y sin red: todo en 127.0.0.1.
 *
 * Uso:
 *   php -d extension=curl -d extension=openssl tools/test_mock_dgii.php
 */

require_once __DIR__ . '/write_ahead_test_lib.php';
require_once __DIR__ . '/../src/Utils/FacturacionElectronica/DgiiReceptionService.php';

$mock = wa_servidor('mock_dgii', 'tools/mock_dgii.php', 8099, ['MOCK_DGII_CUELGA_SEG' => '5']);
$opc = ['base_url' => $mock, 'fc_base_url' => $mock, 'environment' => 'certecf', 'timeout' => 3];
$auth = new DgiiAuthService();
$rec = new DgiiReceptionService($auth);
$xml = '<ECF><Encabezado><IdDoc><TipoeCF>31</TipoeCF><eNCF>E310000000001</eNCF></IdDoc></Encabezado></ECF>';

wa_mock_modo($mock, 'acepta');
wa_mock_log($mock, true);

echo "1) Semilla y token\n";
$s = $auth->obtenerSemilla($opc);
wa_chk('semilla: trae fecha', ($s['fecha'] ?? null) !== null, $s);
$t = $auth->validarSemillaFirmada('<x/>', $opc);
wa_chk('validarsemilla: token tok-mock', ($t['token'] ?? null) === 'tok-mock', $t);

echo "2) Recepcion por modo\n";
/** recibir() en un modo: devuelve [resultado|null, excepcion|null, segundos]. */
$recibir = static function (string $modo) use ($mock, $rec, $xml, $opc): array {
    wa_mock_modo($mock, $modo);
    $t0 = microtime(true);
    try {
        return [$rec->recibir($xml, 'tok-mock', $opc), null, microtime(true) - $t0];
    } catch (Throwable $e) {
        return [null, $e, microtime(true) - $t0];
    }
};

[$r] = $recibir('acepta');
wa_chk('acepta: Aceptado con trackId track-E310000000001',
    ($r['status_code'] ?? 0) === 200 && ($r['data']['estado'] ?? null) === 'Aceptado'
    && ($r['data']['trackId'] ?? null) === 'track-E310000000001', $r);
[$r] = $recibir('acepta_condicional');
wa_chk('acepta_condicional: codigo 4', ($r['data']['codigo'] ?? null) === 4, $r);
[$r] = $recibir('rechaza');
wa_chk('rechaza: HTTP 400 con secuenciaUtilizada false',
    ($r['status_code'] ?? 0) === 400 && ($r['data']['secuenciaUtilizada'] ?? null) === false, $r);
[$r] = $recibir('rechaza_usada');
wa_chk('rechaza_usada: secuenciaUtilizada true', ($r['data']['secuenciaUtilizada'] ?? null) === true, $r);
[$r, $e, $seg] = $recibir('cuelga');
wa_chk("cuelga: RuntimeException 'HTTP request failed'",
    $e instanceof RuntimeException && str_contains($e->getMessage(), 'HTTP request failed'), $e?->getMessage() ?? $r);
wa_chk('cuelga: corta a los ~3 s del timeout', $seg >= 2.5 && $seg < 4.9, round($seg, 2));
[$r, $e] = $recibir('html500');
$prefijo = 'DGII authenticated request failed: ';
wa_chk('html500: RuntimeException de peticion autenticada fallida',
    $e instanceof RuntimeException && str_starts_with($e->getMessage(), $prefijo . 'HTTP 500'), $e?->getMessage() ?? $r);
wa_chk('html500: el resumen del cuerpo queda en 500 caracteres',
    $e !== null && strlen($e->getMessage()) <= strlen($prefijo . 'HTTP 500 - ') + 503, $e === null ? null : strlen($e->getMessage()));
[$r] = $recibir('raw200');
wa_chk("raw200: data es el texto 'Recibido'", ($r['data'] ?? null) === 'Recibido', $r);

echo "3) RFCE y consultas en acepta\n";
wa_mock_modo($mock, 'acepta');
$r = $rec->recibirResumen($xml, 'tok-mock', $opc);
wa_chk('recibirResumen: Aceptado', ($r['data']['estado'] ?? null) === 'Aceptado', $r);
$r = $rec->consultarEstado('track-E310000000001', '000000000', 'E310000000001', 'tok-mock', $opc);
wa_chk('ConsultaResultado: Aceptado', ($r['data']['estado'] ?? null) === 'Aceptado', $r);
$r = $rec->consultarResumenRFCE('000000000', 'E320000000001', 'ABC123', 'tok-mock', $opc);
wa_chk('consultarfce: Aceptado', ($r['data']['estado'] ?? null) === 'Aceptado', $r);

echo "4) Log de peticiones\n";
$log = wa_mock_log($mock);
$recep = '/certecf/Recepcion/api/FacturasElectronicas';
wa_chk('log: cada ruta DGII en orden', array_column($log, 'ruta') === [
    '/certecf/autenticacion/api/autenticacion/semilla',
    '/certecf/autenticacion/api/autenticacion/validarsemilla',
    $recep, $recep, $recep, $recep, $recep, $recep, $recep,
    '/certecf/RecepcionFC/api/recepcion/ecf',
    '/certecf/ConsultaResultado/api/Consultas/Estado',
    '/certecf/consultarfce/api/Consultas/Consulta',
], array_column($log, 'ruta'));
wa_chk('log: cada recepcion con su modo', array_slice(array_column($log, 'modo'), 2, 7)
    === ['acepta', 'acepta_condicional', 'rechaza', 'rechaza_usada', 'cuelga', 'html500', 'raw200'], array_column($log, 'modo'));

echo "5) TrackIds\n";
$tid = static fn(): array => wa_http('GET', $mock . '/certecf/consultatrackids/api/TrackIds/Consulta?RncEmisor=000000000&Encf=E310000000001');
$r = $tid();
wa_chk('TrackIds en acepta: un trackId Aceptado',
    ($r['json'][0]['trackId'] ?? null) === 'track-E310000000001' && ($r['json'][0]['estado'] ?? null) === 'Aceptado', $r);
wa_mock_modo($mock, 'trackids_vacio');
$r = $tid();
wa_chk('trackids_vacio: lista vacia', $r['status'] === 200 && $r['json'] === [], $r);
wa_mock_modo($mock, 'trackids_404');
$r = $tid();
wa_chk('trackids_404: HTTP 404', $r['status'] === 404, $r);
wa_mock_modo($mock, 'trackids_rechazado');
$r = $rec->consultarEstado('track-E310000000001', '000000000', 'E310000000001', 'tok-mock', $opc);
wa_chk('trackids_rechazado: Estado Rechazado sin consumir la secuencia',
    ($r['data']['estado'] ?? null) === 'Rechazado' && ($r['data']['secuenciaUtilizada'] ?? null) === false, $r);

echo "6) Control del mock\n";
$r = wa_http('GET', $mock . '/__mock/modo?valor=rechaza');
wa_chk('/__mock/modo?valor=rechaza lo cambia', ($r['json']['modo'] ?? null) === 'rechaza', $r);
$r = wa_http('GET', $mock . '/__mock/modo');
wa_chk('/__mock/modo lo lee', ($r['json']['modo'] ?? null) === 'rechaza', $r);
$r = wa_http('GET', $mock . '/__mock/modo?valor=no_existe');
wa_chk('/__mock/modo con un modo desconocido: 400', $r['status'] === 400, $r);
$r = wa_http('GET', $mock . '/certecf/no/existe');
wa_chk('ruta desconocida: 404', $r['status'] === 404, $r);

wa_mock_modo($mock, 'acepta');
wa_fin();
