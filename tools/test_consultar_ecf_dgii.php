<?php
/**
 * test_consultar_ecf_dgii.php — Pruebas sin red de las funciones puras de
 * tools/consultar_ecf_dgii.php: rutas de los servicios, normalizacion de la
 * respuesta de TrackIds, fechas en los formatos que devuelve DGII, resumen
 * listo para reconstruir la fila de facturas y la URL de ConsultaTimbre.
 *
 * Uso:
 *   php tools/test_consultar_ecf_dgii.php      (sale con 1 si algo falla)
 */

require_once __DIR__ . '/consultar_ecf_dgii.php';

$fallos = 0;
$total = 0;
$chk = function (string $desc, bool $ok, $obtenido = null) use (&$fallos, &$total) {
    $total++;
    if (!$ok) {
        $fallos++;
    }
    printf("  [%s] %s\n", $ok ? 'OK  ' : 'FALLO', $desc);
    if (!$ok && $obtenido !== null) {
        echo '         obtenido: ' . json_encode($obtenido, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }
};

// ---------------------------------------------------------------------------
// Rutas (relativas al ambiente; DgiiAuthService antepone base/{ambiente}/)
// ---------------------------------------------------------------------------
$r = cecfRutaTrackIds('131256432', 'E340000000001');
$chk('ruta TrackIds', $r === 'consultatrackids/api/TrackIds/Consulta?RncEmisor=131256432&Encf=E340000000001', $r);

$r = cecfRutaEstado('131256432', 'E340000000001');
$chk('ruta Estado sin opcionales', $r === 'consultaestado/api/Consultas/Estado?RncEmisor=131256432&NcfElectronico=E340000000001', $r);

$r = cecfRutaEstado('131256432', 'E340000000001', '131880681', 'AbC123');
$chk('ruta Estado con comprador y codigo',
    $r === 'consultaestado/api/Consultas/Estado?RncEmisor=131256432&NcfElectronico=E340000000001&RncComprador=131880681&CodigoSeguridad=AbC123', $r);

// ---------------------------------------------------------------------------
// TrackIds: DGII devuelve un objeto, o una lista cuando el e-NCF se envio
// mas de una vez. Cualquier otra cosa (texto, null) es "sin trackIds".
// ---------------------------------------------------------------------------
$uno = cecfNormalizarTrackIds(['trackId' => 'abc', 'estado' => 'Aceptado', 'fechaRecepcion' => '5/27/2026 3:31:10 PM']);
$chk('trackIds: objeto -> 1 elemento', count($uno) === 1 && $uno[0]['trackId'] === 'abc', $uno);

$dos = cecfNormalizarTrackIds([
    ['trackId' => 'a', 'estado' => 'Rechazado', 'fechaRecepcion' => 'x'],
    ['trackId' => 'b', 'estado' => 'Aceptado', 'fechaRecepcion' => 'y'],
]);
$chk('trackIds: lista -> 2 elementos en orden', count($dos) === 2 && $dos[0]['trackId'] === 'a' && $dos[1]['trackId'] === 'b', $dos);

$chk('trackIds: texto -> []', cecfNormalizarTrackIds('Not Found') === []);
$chk('trackIds: null -> []', cecfNormalizarTrackIds(null) === []);

$incompleto = cecfNormalizarTrackIds(['trackId' => 'solo']);
$chk('trackIds: claves faltantes salen como null',
    array_key_exists('estado', $incompleto[0]) && $incompleto[0]['estado'] === null
    && array_key_exists('fechaRecepcion', $incompleto[0]) && $incompleto[0]['fechaRecepcion'] === null, $incompleto);

// ---------------------------------------------------------------------------
// Fechas. DGII mezcla formatos (visto en produccion el 2026-10-05, E34 de
// Ferreventura): ConsultaResultado da .NET en-US "M/d/yyyy h:mm:ss tt"
// ("10/5/2026 10:10:38 AM"), ConsultaTrackIds da es-DO "dd/MM/yyyy" sin hora
// ("05/10/2026"), el XML "dd-MM-yyyy HH:mm:ss", y hay ISO 8601.
// Regla: barra con AM/PM = mes primero; barra sin AM/PM = dia primero;
// guiones = dia primero.
// ---------------------------------------------------------------------------
$casos = [
    ['5/27/2026 3:31:10 PM', true, '2026-05-27 15:31:10', 'US con AM/PM'],
    ['12/1/2026 1:02:03 AM', true, '2026-12-01 01:02:03', 'US: barra con AM/PM = mes primero'],
    ['10/5/2026 10:10:38 AM', true, '2026-10-05 10:10:38', 'ConsultaResultado real (E340000000001)'],
    ['05/10/2026', false, '2026-10-05', 'ConsultaTrackIds real: dd/MM/yyyy, dia primero'],
    ['05/10/2026', true, null, 'ConsultaTrackIds sin hora cuando se exige hora -> null'],
    ['05/10/2026 14:03:30', true, '2026-10-05 14:03:30', 'barra con hora de 24 h: dia primero'],
    ['5/27/2026', false, null, 'barra sin AM/PM y 27 como mes -> null, no un dia inventado'],
    ['27-05-2026 15:31:10', true, '2026-05-27 15:31:10', 'XML DGII d-m-Y H:i:s'],
    ['01-12-2026 09:05:00', true, '2026-12-01 09:05:00', 'guion = dia primero'],
    ['2026-05-27T15:31:10', true, '2026-05-27 15:31:10', 'ISO sin zona'],
    ['2026-05-27T15:31:10.1234567', true, '2026-05-27 15:31:10', 'ISO con fraccion .NET'],
    ['2026-05-27T15:31:10-04:00', true, '2026-05-27 15:31:10', 'ISO con zona (hora local tal cual)'],
    ['2026-05-27 15:31:10', true, '2026-05-27 15:31:10', 'Y-m-d H:i:s'],
    ['27-05-2026', false, '2026-05-27', 'solo fecha d-m-Y'],
    ['2026-05-27', false, '2026-05-27', 'solo fecha ISO'],
    ['5/27/2026 3:31:10 PM', false, '2026-05-27', 'con hora pedida sin hora -> solo fecha'],
    ['27-05-2026', true, null, 'sin hora cuando se exige hora -> null'],
    ['', true, null, 'vacio -> null'],
    [null, false, null, 'null -> null'],
    ['ayer', true, null, 'basura -> null'],
    ['31-02-2026 10:00:00', true, null, 'fecha inexistente -> null'],
];
foreach ($casos as [$entrada, $conHora, $esperado, $desc]) {
    $r = cecfNormalizarFecha($entrada, $conHora);
    $chk("fecha: {$desc}", $r === $esperado, $r);
}

// ---------------------------------------------------------------------------
// Mapeo del estado DGII al vocabulario de facturas.estado_dgii
// ---------------------------------------------------------------------------
$chk('estado: Aceptado', cecfMapEstado('Aceptado', null) === 'ACEPTADO');
$chk('estado: Aceptado Condicional gana sobre Aceptado', cecfMapEstado('Aceptado Condicional', null) === 'ACEPTADO_CONDICIONAL');
$chk('estado: Rechazado', cecfMapEstado('Rechazado', null) === 'RECHAZADO');
$chk('estado: En Proceso', cecfMapEstado('En Proceso', null) === 'EN_PROCESO');
$chk('estado: No Encontrado', cecfMapEstado('No encontrado', null) === 'NO_ENCONTRADO');
$chk('estado: sin texto, codigo 2 -> RECHAZADO', cecfMapEstado(null, 2) === 'RECHAZADO');
$chk('estado: sin texto, codigo "4" -> ACEPTADO_CONDICIONAL', cecfMapEstado('', '4') === 'ACEPTADO_CONDICIONAL');
$chk('estado: nada -> null', cecfMapEstado(null, null) === null);

// ---------------------------------------------------------------------------
// Resumen: lo que hace falta para la fila de facturas
// ---------------------------------------------------------------------------
$trackIds = cecfNormalizarTrackIds([
    ['trackId' => 't-1', 'estado' => 'Aceptado', 'fechaRecepcion' => '10/5/2026 2:03:30 PM'],
]);
$estado = [
    'codigo' => 1, 'estado' => 'Aceptado', 'rncEmisor' => '131256432', 'ncfElectronico' => 'E340000000001',
    'montoTotal' => 1180, 'totalITBIS' => 180, 'fechaEmision' => '05-10-2026', 'fechaFirma' => '05-10-2026 14:03:22',
    'rncComprador' => '131880681', 'codigoSeguridad' => 'AbC123', 'idExtranjero' => null,
];
$resultados = [
    ['trackId' => 't-1', 'data' => [
        'trackId' => 't-1', 'codigo' => '1', 'estado' => 'Aceptado', 'rnc' => '131256432', 'encf' => 'E340000000001',
        'secuenciaUtilizada' => true, 'fechaRecepcion' => '10/5/2026 2:03:30 PM',
        'mensajes' => [['valor' => '', 'codigo' => 0]],
    ]],
];
$res = cecfResumen('E340000000001', $trackIds, $estado, $resultados);
$chk('resumen: e_ncf', ($res['e_ncf'] ?? null) === 'E340000000001', $res);
$chk('resumen: tipo_ecf del e-NCF', ($res['tipo_ecf'] ?? null) === '34', $res);
$chk('resumen: estado_dgii ACEPTADO', ($res['estado_dgii'] ?? null) === 'ACEPTADO', $res);
$chk('resumen: track_id = primero', ($res['track_id'] ?? null) === 't-1', $res);
$chk('resumen: codigo_seguridad', ($res['codigo_seguridad'] ?? null) === 'AbC123', $res);
$chk('resumen: fecha_emision_dgii = fechaFirma normalizada', ($res['fecha_emision_dgii'] ?? null) === '2026-10-05 14:03:22', $res);
$chk('resumen: fecha_emision (date) normalizada', ($res['fecha_emision'] ?? null) === '2026-10-05', $res);
$chk('resumen: total a 2 decimales', ($res['total'] ?? null) === '1180.00', $res);
$chk('resumen: total_itbis a 2 decimales', ($res['total_itbis'] ?? null) === '180.00', $res);
$chk('resumen: rnc_comprador', ($res['rnc_comprador'] ?? null) === '131880681', $res);
$chk('resumen: secuencia_utilizada true', ($res['secuencia_utilizada'] ?? null) === true, $res);
$chk('resumen: fecha_recepcion normalizada', ($res['fecha_recepcion'] ?? null) === '2026-10-05 14:03:30', $res);
$chk('resumen: mensajes vacios se descartan', ($res['mensajes'] ?? ['x']) === [], $res);

// Sin ConsultaEstado (fallo o certecf): el estado sale de TrackIds y no hay codigo.
$res2 = cecfResumen('E340000000002', cecfNormalizarTrackIds(['trackId' => 't-2', 'estado' => 'Rechazado', 'fechaRecepcion' => null]), null, [
    ['trackId' => 't-2', 'data' => [
        'codigo' => '2', 'estado' => 'Rechazado', 'secuenciaUtilizada' => false,
        'mensajes' => [['valor' => 'El NCF Modificado no existe', 'codigo' => 123], ['valor' => '', 'codigo' => 0]],
    ]],
]);
$chk('resumen sin Estado: estado_dgii desde TrackIds', ($res2['estado_dgii'] ?? null) === 'RECHAZADO', $res2);
$chk('resumen sin Estado: codigo_seguridad null', array_key_exists('codigo_seguridad', $res2) && $res2['codigo_seguridad'] === null, $res2);
$chk('resumen sin Estado: total null', array_key_exists('total', $res2) && $res2['total'] === null, $res2);
$chk('resumen sin Estado: secuencia_utilizada false', ($res2['secuencia_utilizada'] ?? null) === false, $res2);
$chk('resumen: mensajes con texto se conservan', ($res2['mensajes'] ?? null) === [['codigo' => 123, 'valor' => 'El NCF Modificado no existe']], $res2);

// Sin nada (DGII no encontro el e-NCF)
$res3 = cecfResumen('E340000000009', [], null, []);
$chk('resumen vacio: estado_dgii null y track_id null', $res3['estado_dgii'] === null && $res3['track_id'] === null, $res3);

// Respuesta real del 2026-10-05 (E340000000001): TrackIds trae solo la fecha
// (dd/MM/yyyy) y ConsultaResultado la fecha con hora (en-US). Gana la de hora.
$res4 = cecfResumen('E340000000001',
    cecfNormalizarTrackIds([['trackId' => '303fabde-0e83-4050-82f6-40091bc6ce1f', 'estado' => 'Aceptado', 'fechaRecepcion' => '05/10/2026']]),
    null,
    [['trackId' => '303fabde-0e83-4050-82f6-40091bc6ce1f', 'data' => [
        'trackId' => '303fabde-0e83-4050-82f6-40091bc6ce1f', 'codigo' => '1', 'estado' => 'Aceptado',
        'rnc' => '132615123', 'encf' => 'E340000000001', 'secuenciaUtilizada' => true,
        'fechaRecepcion' => '10/5/2026 10:10:38 AM', 'mensajes' => [['valor' => '', 'codigo' => 0]],
    ]]]);
$chk('resumen real: fecha_recepcion con hora, de ConsultaResultado', ($res4['fecha_recepcion'] ?? null) === '2026-10-05 10:10:38', $res4);
$chk('resumen real: ACEPTADO y secuencia utilizada', $res4['estado_dgii'] === 'ACEPTADO' && $res4['secuencia_utilizada'] === true, $res4);

// ConsultaEstado sin codigo de seguridad: la DGII lo exige para algunos e-CF
// (respuesta real del 2026-10-05). No es un fallo de la consulta.
$chk('ConsultaEstado: el HTTP 400 que pide Cod_Seguridad se reconoce',
    cecfEstadoExigeCodigo('DGII authenticated request failed: HTTP 400 - "Para consultar el estado de esta factura, es necesario completar el campo Cod_Seguridad."'));
$chk('ConsultaEstado: un fallo de red no se confunde con eso',
    !cecfEstadoExigeCodigo('HTTP request failed: Operation timed out after 30001 milliseconds'));

// ---------------------------------------------------------------------------
// URL de ConsultaTimbre: mismo armado que EcfDocumento::timbre()
// ---------------------------------------------------------------------------
$url = cecfUrlTimbre($res, '131256432', 'ecf');
$chk('timbre: URL completa produccion',
    $url === 'https://ecf.dgii.gov.do/ecf/ConsultaTimbre?RncEmisor=131256432&RncComprador=131880681&ENCF=E340000000001'
        . '&FechaEmision=05-10-2026&MontoTotal=1180.00&FechaFirma=05-10-2026%2014%3A03%3A22&CodigoSeguridad=AbC123', $url);

$url = cecfUrlTimbre($res, '131256432', 'certecf');
$chk('timbre: ambiente certecf -> CerteCF', is_string($url) && str_starts_with($url, 'https://ecf.dgii.gov.do/CerteCF/ConsultaTimbre?'), $url);

$res43 = $res;
$res43['e_ncf'] = 'E430000000001';
$res43['tipo_ecf'] = '43';
$url = cecfUrlTimbre($res43, '131256432', 'ecf');
$chk('timbre: E43 nunca lleva RncComprador', is_string($url) && !str_contains($url, 'RncComprador'), $url);

$res32 = $res;
$res32['e_ncf'] = 'E320000000001';
$res32['tipo_ecf'] = '32';
$url = cecfUrlTimbre($res32, '131256432', 'ecf');
$chk('timbre: E32 < 250k usa ConsultaTimbreFC', is_string($url) && str_contains($url, '/ConsultaTimbreFC?'), $url);

$sinCodigo = $res;
$sinCodigo['codigo_seguridad'] = null;
$chk('timbre: sin codigo de seguridad -> null', cecfUrlTimbre($sinCodigo, '131256432', 'ecf') === null);

$sinFirma = $res;
$sinFirma['fecha_emision_dgii'] = null;
$chk('timbre: sin fecha de firma -> null', cecfUrlTimbre($sinFirma, '131256432', 'ecf') === null);

// ---------------------------------------------------------------------------
// Ambiente: los dos servicios no existen en certificacion
// ---------------------------------------------------------------------------
$chk('aviso: certecf avisa', cecfAvisoAmbiente('certecf') !== null);
$chk('aviso: ecf no avisa', cecfAvisoAmbiente('ecf') === null);
$chk('aviso: testecf no avisa', cecfAvisoAmbiente('testecf') === null);

// ---------------------------------------------------------------------------
// Validacion de e-NCF de entrada
// ---------------------------------------------------------------------------
$chk('encf: lista separada por comas, con espacios y minusculas',
    cecfParsearEncfs(' e340000000001, E340000000002 ') === ['E340000000001', 'E340000000002']);
$chk('encf: duplicados se quitan', cecfParsearEncfs('E340000000001,E340000000001') === ['E340000000001']);
$chk('encf: invalido se rechaza', cecfParsearEncfs('E34000001') === []);
$chk('encf: vacio -> []', cecfParsearEncfs('') === []);

printf("\n%d/%d OK\n", $total - $fallos, $total);
exit($fallos === 0 ? 0 : 1);
