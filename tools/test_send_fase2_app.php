<?php
/**
 * test_send_fase2_app.php — La fase 2 en modo APP de punta a punta, sin red:
 * corre main() de tools/send_fase2.php sobre un xlsx armado aqui, con el envio,
 * la consulta de estado, GET /emisor y el sleep falsos (parametro $red de main).
 *
 * Origen: empresa RNC 133454394, 2026-11-12. En su primera corrida tres RFCE no
 * tuvieron respuesta de RecepcionFC (timeout), quedaron sin confirmar y el set
 * no se pudo completar; otra corrida uso el xlsx de otra empresa. Aqui se
 * repite cada caso y se revisa lo que el runner manda, lo que deja en el
 * reporte y con que codigo sale.
 *
 * Las piezas sueltas (f2ClaseEnvio, f2ReintentarPendientes, el pre-flight...)
 * se prueban en tools/test_send_fase2_espera.php.
 *
 * Uso:
 *   php tools/test_send_fase2_app.php      (sale con 1 si algo falla)
 * Necesita la extension zip (la misma que el runner para leer el xlsx).
 */

require_once __DIR__ . '/send_fase2.php';

if (!class_exists('ZipArchive')) {
    echo "SALTADA: falta la extension zip (el runner tampoco puede leer el xlsx sin ella).\n";
    exit(0);
}

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

$temporales = [];
register_shutdown_function(static function () use (&$temporales): void {
    foreach ($temporales as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }
});

/** Hoja de un xlsx: la primera fila son los encabezados; todo va como texto (inlineStr). */
function f2app_hoja(array $encabezados, array $filas): string
{
    $letra = static fn(int $i): string => chr(65 + $i);
    $fila = static function (int $n, array $valores) use ($letra): string {
        $celdas = '';
        foreach (array_values($valores) as $i => $v) {
            $celdas .= '<c r="' . $letra($i) . $n . '" t="inlineStr"><is><t>' . htmlspecialchars((string) $v, ENT_XML1) . '</t></is></c>';
        }
        return '<row r="' . $n . '">' . $celdas . '</row>';
    };
    $xml = $fila(1, $encabezados);
    foreach (array_values($filas) as $i => $f) {
        $xml .= $fila($i + 2, array_map(static fn(string $col) => $f[$col] ?? '', $encabezados));
    }
    return '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
        . $xml . '</sheetData></worksheet>';
}

/** Un set de pruebas minimo: hoja ECF (y RFCE para los E32) con el RNCEmisor dado. Devuelve la ruta. */
function f2app_xlsx(string $rncEmisor, array &$temporales): string
{
    $comun = ['RNCEmisor' => $rncEmisor, 'RazonSocialEmisor' => 'EMPRESA EN CERTIFICACION SRL', 'FechaEmision' => '12-11-2026',
        'RNCComprador' => '131880681', 'RazonSocialComprador' => 'DOCUMENTOS ELECTRONICOS DE 03', 'NombreItem[1]' => 'Servicio',
        'CantidadItem[1]' => '1', 'PrecioUnitarioItem[1]' => '100.00', 'MontoItem[1]' => '100.00', 'MontoTotal' => '118.00'];
    $caso = static fn(string $encf, array $extra = []): array => $extra + ['CasoPrueba' => $rncEmisor . $encf,
        'TipoeCF' => substr($encf, 1, 2), 'ENCF' => $encf] + $comun;
    $ecf = [
        $caso('E310000000009'),
        // La nota va ANTES que su original en el archivo: el runner la ordena al final.
        $caso('E340000000016', ['NCFModificado' => 'E320000000011', 'FechaNCFModificado' => '12-11-2026', 'CodigoModificacion' => '3']),
        $caso('E320000000011', ['MontoTotal' => '40120.00']),
        $caso('E320000000013', ['MontoTotal' => '112100.00']),
        $caso('E330000000002', ['NCFModificado' => 'E310000000009', 'FechaNCFModificado' => '12-11-2026', 'CodigoModificacion' => '3']),
    ];
    $colsEcf = ['CasoPrueba', 'TipoeCF', 'ENCF', 'RNCEmisor', 'RazonSocialEmisor', 'FechaEmision', 'RNCComprador', 'RazonSocialComprador',
        'NCFModificado', 'FechaNCFModificado', 'CodigoModificacion', 'NombreItem[1]', 'CantidadItem[1]', 'PrecioUnitarioItem[1]', 'MontoItem[1]', 'MontoTotal'];
    $colsRfce = ['ENCF', 'RNCEmisor', 'RazonSocialEmisor', 'RNCComprador', 'RazonSocialComprador'];
    $rfce = array_values(array_filter($ecf, static fn(array $f): bool => $f['TipoeCF'] === '32'));

    $ruta = (string) tempnam(sys_get_temp_dir(), 'f2app_');
    $temporales[] = $ruta;
    $zip = new ZipArchive();
    $zip->open($ruta, ZipArchive::OVERWRITE);
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'
        . '<sheet name="ECF" sheetId="1" r:id="rId1"/><sheet name="RFCE" sheetId="2" r:id="rId2"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', f2app_hoja($colsEcf, $ecf));
    $zip->addFromString('xl/worksheets/sheet2.xml', f2app_hoja($colsRfce, $rfce));
    $zip->close();
    return $ruta;
}

// --- Respuestas del API (las mismas formas que facturaController) ---
$apiOk = static fn(string $encf, int $id, string $estado = 'ENVIADO', bool $conTrack = true): array => ['http_status' => 200, 'body' => [
    'status' => true, 'data' => ['factura_id' => $id, 'e_ncf' => $encf, 'track_id' => $conTrack ? 'trk-' . $encf : null,
        'estado_dgii' => $estado, 'codigo_seguridad' => 'Ab12Cd', 'tipo_ecf' => substr($encf, 1, 2), 'ambiente' => 'certecf']]];
$apiIncierto = static fn(string $encf, int $id): array => ['http_status' => 502, 'body' => ['status' => false,
    'error' => "No se pudo confirmar si la DGII recibió la factura {$encf}. No la emitas de nuevo: ya quedó guardada.",
    'data' => ['factura_id' => $id, 'e_ncf' => $encf, 'tipo_ecf' => substr($encf, 1, 2), 'estado_dgii' => 'ENVIO_INCIERTO']]];
$estadoApp = static fn(string $estado, array $extra = []): array => ['http_status' => 200, 'body' => ['status' => true,
    'data' => ['estado_dgii' => $estado] + $extra]];
$emisor = static fn(string $rnc = '133454394', ?string $ambiente = 'certecf'): array => ['http_status' => 200, 'body' => ['status' => true,
    'data' => ['rnc' => $rnc, 'razon_social' => 'EMPRESA EN CERTIFICACION SRL', 'fuente' => 'emisor_config'] + ($ambiente !== null ? ['ambiente' => $ambiente] : [])]];

/**
 * Red falsa de una corrida. $porEncf: e-NCF => respuestas del POST en orden (la
 * ultima se repite). $porFactura: factura_id => respuesta de GET /estado.
 * Anota en $bitacora cada llamada: ['post', e-NCF, payload] | ['estado', factura_id] | ['emisor'] | ['dormir', s].
 */
$red = static function (array $porEncf, array $porFactura, array $respuestaEmisor) use (&$bitacora, $apiOk, $estadoApp): array {
    $bitacora = [];
    $vez = [];
    $siguienteId = 500;
    return [
        'enviar' => static function (array $payload) use (&$bitacora, &$vez, &$siguienteId, $porEncf, $apiOk): array {
            $encf = (string) ($payload['e_ncf'] ?? '');
            $bitacora[] = ['post', $encf, $payload];
            $n = $vez[$encf] = ($vez[$encf] ?? -1) + 1;
            $pasos = $porEncf[$encf] ?? null;
            return $pasos === null ? $apiOk($encf, $siguienteId++) : $pasos[min($n, count($pasos) - 1)];
        },
        'consultar' => static function ($api, $key, $secret, string $encf, array $s) use (&$bitacora, $porFactura, $estadoApp): array {
            $id = (int) ($s['factura_id'] ?? 0);
            $bitacora[] = ['estado', $id, $secret];
            return $porFactura[$id] ?? $estadoApp('ACEPTADO');
        },
        'emisor' => static function ($api, $key) use (&$bitacora, $respuestaEmisor): array {
            $bitacora[] = ['emisor', $key];
            return $respuestaEmisor;
        },
        'dormir' => static function (int $s) use (&$bitacora): void {
            $bitacora[] = ['dormir', $s];
        },
    ];
};
$posts = static fn(array $bitacora): array => array_values(array_map(static fn(array $l) => $l[1],
    array_filter($bitacora, static fn(array $l): bool => $l[0] === 'post')));
$de = static fn(array $bitacora, string $tipo): array => array_values(array_filter($bitacora, static fn(array $l): bool => $l[0] === $tipo));

/** Corre main() en modo app y devuelve [codigo de salida, reporte (por e-NCF) o null si no se escribio]. */
$correr = static function (string $xlsx, array $redFalsa, array $args = []) use (&$temporales): array {
    $salida = (string) tempnam(sys_get_temp_dir(), 'f2app_out_');
    @unlink($salida);
    $temporales[] = $salida;
    $argv = array_merge(['send_fase2', $xlsx, '--api=https://x/api', '--api-key=token-de-prueba', '--client-id=5', '--output=' . $salida], $args);
    $codigo = main($argv, $redFalsa);
    $filas = is_file($salida) ? json_decode((string) file_get_contents($salida), true) : null;
    return [$codigo, is_array($filas) ? array_column($filas, null, 'e_ncf') : null];
};

$xlsx = f2app_xlsx('133454394', $temporales);
$xlsxAjeno = f2app_xlsx('130968837', $temporales);

echo "1) Dos RFCE sin respuesta de la DGII (timeout): se resuelven en la misma corrida y las notas salen\n";
// E320000000011: la DGII si lo recibio (la consulta lo dice). E320000000013: no lo tiene, se manda otra vez.
$r = $red(
    ['E320000000011' => [$apiIncierto('E320000000011', 8)],
        'E320000000013' => [$apiIncierto('E320000000013', 10), $apiOk('E320000000013', 201, 'RFCE_ACEPTADO', false)],
        'E310000000009' => [$apiOk('E310000000009', 21)]],
    [8 => $estadoApp('RFCE_ACEPTADO', ['flujo' => 'RFCE']), 10 => $estadoApp('RFCE_NO_ENCONTRADO', ['flujo' => 'RFCE'])],
    $emisor()
);
[$codigo, $rep] = $correr($xlsx, $r, ['--ambiente=certecf']);
$chk('sale con 0: el set quedo completo en una sola corrida', $codigo === 0, $codigo);
$chk('verifica la empresa una vez, antes de cualquier envio', count($de($bitacora, 'emisor')) === 1 && $bitacora[0][0] === 'emisor', array_column($bitacora, 0));
$chk('envios: los 3 originales, el reenvio del que la DGII no tenia, y al final las 2 notas',
    $posts($bitacora) === ['E310000000009', 'E320000000011', 'E320000000013', 'E320000000013', 'E340000000016', 'E330000000002'], $posts($bitacora));
$chk('E320000000011 no se reenvia: queda con el estado que dio la DGII y su fila original',
    ($rep['E320000000011']['ok'] ?? null) === true && $rep['E320000000011']['estado_dgii'] === 'RFCE_ACEPTADO'
    && $rep['E320000000011']['factura_id'] === 8 && ($rep['E320000000011']['resuelto'] ?? null) === 'consulta', $rep['E320000000011'] ?? null);
$chk('E320000000013 queda emitido en el segundo envio', ($rep['E320000000013']['ok'] ?? null) === true
    && $rep['E320000000013']['factura_id'] === 201 && $rep['E320000000013']['intentos'] === 2, $rep['E320000000013'] ?? null);
$chk('las dos notas salieron (ninguna frenada)', ($rep['E340000000016']['ok'] ?? null) === true && ($rep['E330000000002']['ok'] ?? null) === true
    && empty($rep['E340000000016']['skipped']) && empty($rep['E330000000002']['skipped']), [$rep['E340000000016'] ?? null, $rep['E330000000002'] ?? null]);
$estados = array_column($de($bitacora, 'estado'), 1);
$chk('consulta las dos filas inciertas (8 y 10) y despues, antes de las notas, lo que seguia en proceso (21)',
    array_slice($estados, 0, 2) === [8, 10] && in_array(21, $estados, true), $estados);
$chk('todas las consultas van por la ruta de app (sin api_secret)', array_unique(array_column($de($bitacora, 'estado'), 2)) === [''], $de($bitacora, 'estado'));
$primerPost = $de($bitacora, 'post')[0][2];
$chk('cada payload lleva certecf, strict_input, el client_id y el emisor del set',
    ($primerPost['ambiente'] ?? null) === 'certecf' && ($primerPost['strict_input'] ?? null) === true && ($primerPost['client_id'] ?? null) === 5
    && ($primerPost['emisor']['rnc'] ?? null) === '133454394', $primerPost);
$chk('una sola pausa: la de la ronda de reintentos (10 s por defecto)', $de($bitacora, 'dormir') === [['dormir', 10]], $de($bitacora, 'dormir'));
$chk('al terminar no falta nada', f2QueFalta(array_values($rep)) === '', f2QueFalta(array_values($rep)));

echo "\n2) El xlsx es de otra empresa (130968837 con el token de 133454394): no se envia nada\n";
[$codigo, $rep] = $correr($xlsxAjeno, $red([], [], $emisor()), ['--ambiente=certecf']);
$chk('sale con 2 y sin reporte', $codigo === 2 && $rep === null, [$codigo, $rep]);
$chk('ni un envio ni una consulta de estado', $posts($bitacora) === [] && $de($bitacora, 'estado') === [], array_column($bitacora, 0));

echo "\n3) Token vencido: se corta antes del primer caso (antes fallaban los 25, uno por uno)\n";
[$codigo, $rep] = $correr($xlsx, $red([], [], ['http_status' => 401, 'body' => ['status' => false, 'error' => 'Token inválido']]), ['--ambiente=certecf']);
$chk('sale con 2, sin envios', $codigo === 2 && $posts($bitacora) === [], [$codigo, $posts($bitacora)]);

echo "\n4) Empresa ya en produccion (ecf) y corrida sin --ambiente: el set no sale\n";
[$codigo] = $correr($xlsx, $red([], [], $emisor('133454394', 'ecf')));
$chk('sale con 2, sin envios', $codigo === 2 && $posts($bitacora) === [], [$codigo, $posts($bitacora)]);
[$codigo] = $correr($xlsx, $red([], [], $emisor('133454394', 'ecf')), ['--ambiente=certecf']);
$chk('con --ambiente=certecf (lo que manda cert_run.php) si corre', $codigo === 0 && count($posts($bitacora)) === 5, [$codigo, $posts($bitacora)]);

echo "\n5) La DGII sigue sin contestar: se insiste hasta el tope, las notas no salen y queda dicho que falta\n";
$r = $red(
    ['E320000000011' => [$apiIncierto('E320000000011', 8), $apiIncierto('E320000000011', 9), $apiIncierto('E320000000011', 12)]],
    [8 => $estadoApp('ENVIO_INCIERTO'), 9 => $estadoApp('ENVIO_INCIERTO'), 12 => $estadoApp('ENVIO_INCIERTO')],
    $emisor()
);
[$codigo, $rep] = $correr($xlsx, $r, ['--ambiente=certecf', '--reintento-pausa=0']);
$chk('sale con 1', $codigo === 1, $codigo);
$chk('E320000000011: 3 envios (1 + 2 reintentos) y sigue sin confirmar', count(array_keys($posts($bitacora), 'E320000000011', true)) === 3
    && ($rep['E320000000011']['ok'] ?? null) === false && ($rep['E320000000011']['intentos'] ?? null) === 3
    && str_contains((string) $rep['E320000000011']['error'], 'sin confirmar'), $rep['E320000000011'] ?? null);
$chk('las notas no se mandan', !in_array('E340000000016', $posts($bitacora), true) && !in_array('E330000000002', $posts($bitacora), true)
    && !empty($rep['E340000000016']['skipped']) && !empty($rep['E330000000002']['skipped']), $posts($bitacora));
$chk('el motivo de la nota no manda a la consola de integracion.html', !str_contains((string) $rep['E340000000016']['error'], 'consola')
    && str_contains((string) $rep['E340000000016']['error'], 'E320000000011'), $rep['E340000000016']['error'] ?? null);
$falta = f2QueFalta(array_values($rep));
$chk('lo que falta: el incierto y las dos notas, para --case; no lo que la DGII ya tiene',
    str_contains($falta, 'E320000000011') && str_contains($falta, 'E340000000016') && str_contains($falta, 'E330000000002')
    && !str_contains($falta, 'E310000000009') && !str_contains($falta, 'E320000000013') && !str_contains($falta, 'COMPLETA'), $falta);
$chk('--reintento-pausa=0: sin pausas de reintento', $de($bitacora, 'dormir') === [], $de($bitacora, 'dormir'));

echo "\n6) Segunda corrida solo con lo que falto (--case): no reenvia lo que la DGII ya acepto\n";
[$codigo, $rep] = $correr($xlsx, $red([], [], $emisor()), ['--ambiente=certecf', '--case=E320000000011,E340000000016,E330000000002']);
$chk('sale con 0', $codigo === 0, $codigo);
$chk('solo esos tres, el original antes que las notas', $posts($bitacora) === ['E320000000011', 'E340000000016', 'E330000000002'], $posts($bitacora));
$chk('el reporte solo tiene esos tres', is_array($rep) && array_keys($rep) === ['E320000000011', 'E340000000016', 'E330000000002'], is_array($rep) ? array_keys($rep) : null);

echo "\n7) --reintentos=0: como antes, sin volver sobre nada\n";
$r = $red(['E320000000011' => [$apiIncierto('E320000000011', 8)]], [], $emisor());
[$codigo, $rep] = $correr($xlsx, $r, ['--ambiente=certecf', '--reintentos=0']);
$chk('un solo envio de E320000000011, sin consultar su fila, y sale con 1',
    count(array_keys($posts($bitacora), 'E320000000011', true)) === 1 && !in_array(8, array_column($de($bitacora, 'estado'), 1), true) && $codigo === 1,
    [$posts($bitacora), $de($bitacora, 'estado'), $codigo]);

echo "\n8) La DGII rechaza un original en el acto (422): no se reintenta, las notas no salen y hay que correr todo\n";
$r = $red(['E320000000013' => [['http_status' => 422, 'body' => ['status' => false, 'error' => 'La DGII rechazó la factura: MontoTotal.',
    'data' => ['factura_id' => 77, 'e_ncf' => 'E320000000013', 'estado_dgii' => 'RFCE_RECHAZADO']]]]], [], $emisor());
[$codigo, $rep] = $correr($xlsx, $r, ['--ambiente=certecf']);
$chk('un solo envio del rechazado y sale con 1', count(array_keys($posts($bitacora), 'E320000000013', true)) === 1 && $codigo === 1, [$posts($bitacora), $codigo]);
$chk('las notas no salen', !empty($rep['E340000000016']['skipped']) && !empty($rep['E330000000002']['skipped']), $rep);
$chk('y dice que hay que correr la fase completa', str_contains(f2QueFalta(array_values($rep), 1), 'COMPLETA'), f2QueFalta(array_values($rep), 1));

echo "\n9) Dry-run: no verifica contra el API ni envia; integracion en dry-run sigue igual\n";
[$codigo, $rep] = $correr($xlsx, $red([], [], $emisor()), ['--dry-run']);
$chk('app: sale con 0, sin llamadas, con los 5 payloads en el reporte', $codigo === 0 && $bitacora === [] && is_array($rep) && count($rep) === 5
    && ($rep['E310000000009']['payload']['client_id'] ?? null) === 5, [$codigo, $bitacora]);
[$codigo, $rep] = $correr($xlsx, $red([], [], $emisor()), ['--dry-run', '--api-secret=secreto', '--xml-dir=' . sys_get_temp_dir()]);
$chk('integracion: sale con 0, sin llamadas, y el payload no lleva client_id', $codigo === 0 && $bitacora === [] && is_array($rep)
    && !array_key_exists('client_id', $rep['E310000000009']['payload'] ?? ['client_id' => 1]), [$codigo, $bitacora]);

printf("\n%d de %d pruebas OK\n", $total - $fallos, $total);
exit($fallos > 0 ? 1 : 0);
