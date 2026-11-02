<?php
/**
 * test_timbre_ecf.php — URL de ConsultaTimbre (qr_url de POST /api/integracion/ecf)
 * armada desde el XML firmado, sin base de datos ni red.
 *
 * Uso:
 *   php tools/test_timbre_ecf.php      (sale con 1 si algo falla)
 */

require_once __DIR__ . '/../src/Utils/FacturacionElectronica/ECFEmissionService.php';
require_once __DIR__ . '/../src/Utils/FacturacionElectronica/TimbreEcf.php';

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

/** e-CF firmado minimo con la forma de ECFXmlBuilder. $comprador = nodo hijo de Comprador ('' = sin Comprador). */
$xml = static function (string $tipo, string $eNcf, string $monto, string $comprador, string $firma = 'Ab+/Cd99xyz=='): string {
    $nodoComprador = $comprador !== '' ? "<Comprador>$comprador</Comprador>" : '';
    return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . "<ECF><Encabezado><Version>1.0</Version><IdDoc><TipoeCF>$tipo</TipoeCF><eNCF>$eNcf</eNCF></IdDoc>"
        . '<Emisor><RNCEmisor>131111111</RNCEmisor><FechaEmision>04-08-2026</FechaEmision></Emisor>'
        . $nodoComprador
        . "<Totales><MontoTotal>$monto</MontoTotal></Totales></Encabezado>"
        . '<FechaHoraFirma>04-08-2026 11:52:22</FechaHoraFirma>'
        . '<Signature xmlns="http://www.w3.org/2000/09/xmldsig#"><SignedInfo/>'
        . "<SignatureValue>$firma</SignatureValue></Signature></ECF>";
};

echo "TimbreEcf::urlDesdeXml\n";

$e31 = TimbreEcf::urlDesdeXml($xml('31', 'E310000000001', '8850.00', '<RNCComprador>131222444</RNCComprador>'), 'ecf');
$chk(
    'E31 produccion: URL completa, valores codificados, codigo con + y /',
    $e31 === 'https://ecf.dgii.gov.do/ecf/ConsultaTimbre?RncEmisor=131111111&RncComprador=131222444'
        . '&ENCF=E310000000001&FechaEmision=04-08-2026&MontoTotal=8850.00'
        . '&FechaFirma=04-08-2026%2011%3A52%3A22&CodigoSeguridad=Ab%2B%2FCd',
    $e31
);

$cert = TimbreEcf::urlDesdeXml($xml('31', 'E310000000001', '8850.00', '<RNCComprador>131222444</RNCComprador>'), 'certecf');
$chk('certecf -> CerteCF', is_string($cert) && str_starts_with($cert, 'https://ecf.dgii.gov.do/CerteCF/ConsultaTimbre?'), $cert);

$chk(
    'MontoTotal a 2 decimales',
    str_contains((string) TimbreEcf::urlDesdeXml($xml('31', 'E310000000001', '8850', '<RNCComprador>131222444</RNCComprador>'), 'ecf'), 'MontoTotal=8850.00&')
);

$fc = TimbreEcf::urlDesdeXml($xml('32', 'E320000000012', '1180.00', ''), 'ecf');
$chk('E32 < 250k: ConsultaTimbreFC', is_string($fc) && str_contains($fc, '/ecf/ConsultaTimbreFC?'), $fc);
$chk('E32 sin comprador: sin RncComprador', is_string($fc) && !str_contains($fc, 'RncComprador'), $fc);

$e32g = TimbreEcf::urlDesdeXml($xml('32', 'E320000000013', '250000.00', '<RNCComprador>131222444</RNCComprador>'), 'ecf');
$chk('E32 de 250,000 o mas: ConsultaTimbre (no FC) y con RncComprador',
    is_string($e32g) && str_contains($e32g, '/ecf/ConsultaTimbre?') && str_contains($e32g, 'RncComprador=131222444'), $e32g);

$e43 = TimbreEcf::urlDesdeXml($xml('43', 'E430000000001', '500.00', '<RNCComprador>131222444</RNCComprador>'), 'ecf');
$chk('E43 nunca lleva RncComprador', is_string($e43) && !str_contains($e43, 'RncComprador'), $e43);

$e47 = TimbreEcf::urlDesdeXml($xml('47', 'E470000000001', '500.00', '<IdentificadorExtranjero>X123</IdentificadorExtranjero>'), 'ecf');
$chk('E47 (IdentificadorExtranjero) sin RncComprador', is_string($e47) && !str_contains($e47, 'RncComprador'), $e47);

echo "\nCasos sin URL (null: no se inventa un QR que la DGII no encuentre)\n";
$chk('XML sin firma', TimbreEcf::urlDesdeXml('<ECF><Encabezado/></ECF>', 'ecf') === null);
$chk('XML vacio', TimbreEcf::urlDesdeXml('', 'ecf') === null);
$chk('XML mal formado', TimbreEcf::urlDesdeXml('<ECF><Encabezado>', 'ecf') === null);
$chk('sin MontoTotal', TimbreEcf::urlDesdeXml(str_replace('<MontoTotal>8850.00</MontoTotal>', '', $xml('31', 'E310000000001', '8850.00', '')), 'ecf') === null);
$chk('ambiente vacio', TimbreEcf::urlDesdeXml($xml('31', 'E310000000001', '8850.00', ''), '') === null);

printf("\n%d de %d pruebas OK\n", $total - $fallos, $total);
exit($fallos > 0 ? 1 : 0);
