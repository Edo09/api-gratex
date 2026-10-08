<?php
/**
 * test_integracion_xml.php — Codigo de seguridad de un e-CF firmado, sin base
 * de datos ni red.
 *
 * GET /api/integracion/xml devuelve el XML del respaldo con su codigo de
 * seguridad, y integracion.html (paso 8) lo usa para verificar contra la DGII
 * que el XML integro de cada RFCE (E32 <250k) es el MISMO e-CF cuyo resumen
 * acepto. Tiene que dar exactamente lo que firmo la emision
 * (ECFEmissionService::extractCodigoSeguridad): 6 primeros caracteres de
 * SignatureValue, solo sin espacios.
 *
 * Uso:
 *   php tools/test_integracion_xml.php      (sale con 1 si algo falla)
 */

require_once __DIR__ . '/../src/Utils/FacturacionElectronica/ECFEmissionService.php';

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

$firmado = static fn(string $valor): string =>
    '<?xml version="1.0" encoding="UTF-8"?>' . "\n<ECF><Encabezado/><Signature xmlns=\"http://www.w3.org/2000/09/xmldsig#\">"
    . "<SignedInfo/><SignatureValue>$valor</SignatureValue></Signature></ECF>";

echo "ECFEmissionService::codigoSeguridadDeXml\n";
$chk('6 primeros caracteres de SignatureValue', ECFEmissionService::codigoSeguridadDeXml($firmado('RHq/pJabcdef==')) === 'RHq/pJ');
$chk('quita saltos de linea y espacios del base64', ECFEmissionService::codigoSeguridadDeXml($firmado("\n  r6\n7u Sx9999")) === 'r67uSx');
$chk('conserva + / = (nunca se quitan)', ECFEmissionService::codigoSeguridadDeXml($firmado('+/=AbCdEf')) === '+/=AbC');
$chk('sin firma: null (no inventa un codigo)', ECFEmissionService::codigoSeguridadDeXml('<ECF><Encabezado/></ECF>') === null);

// La emision usa el mismo calculo (extractCodigoSeguridad es privado: se llama por reflexion).
$m = new ReflectionMethod(ECFEmissionService::class, 'extractCodigoSeguridad');
$m->setAccessible(true);
$servicio = (new ReflectionClass(ECFEmissionService::class))->newInstanceWithoutConstructor();
$xml = $firmado("Ab+/\n Cd99");
$chk('la emision firma el mismo codigo que devuelve /integracion/xml',
    $m->invoke($servicio, $xml) === ECFEmissionService::codigoSeguridadDeXml($xml), $m->invoke($servicio, $xml));
$chk('sin firma la emision sigue usando su fallback sha1 (lo de siempre)',
    $m->invoke($servicio, '<ECF/>') === substr(sha1('<ECF/>'), 0, 6));

printf("\n%d de %d pruebas OK\n", $total - $fallos, $total);
exit($fallos > 0 ? 1 : 0);
