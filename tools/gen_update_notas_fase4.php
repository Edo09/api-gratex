<?php
// Extrae la InformacionReferencia REAL del XML firmado (lo que DGII valido) de
// las notas E33/E34 del run final de fase 4 y genera el UPDATE para rellenar
// ncf_modificado / fecha_ncf_modificado / codigo_modificacion / razon_modificacion.
// Fuente de verdad = xml_firmado (no payloads de prueba).
require_once __DIR__ . '/../src/Database.php';

$ids = [1277, 1278, 1279]; // E330000000310, E340000000329, E340000000330

function xmlText(DOMDocument $doc, string $tag): ?string
{
    $nodes = $doc->getElementsByTagName($tag);
    if ($nodes->length === 0) {
        return null;
    }
    $val = trim($nodes->item(0)->textContent);
    return $val === '' ? null : $val;
}

function ddmmyyyyToDate(?string $v): ?string
{
    if ($v === null || $v === '') return null;
    $dt = DateTime::createFromFormat('d-m-Y', $v);
    return $dt ? $dt->format('Y-m-d') : null;
}

function sqlVal(?string $v): string
{
    if ($v === null) return 'NULL';
    return "'" . str_replace("'", "''", $v) . "'";
}

$db = Database::getInstance()->getConnection();
$updates = [];

echo str_repeat('=', 70) . "\n";
echo "InformacionReferencia extraida del XML firmado\n";
echo str_repeat('=', 70) . "\n";

foreach ($ids as $id) {
    $stmt = $db->prepare('SELECT id, e_ncf, tipo_ecf, xml_firmado FROM facturas WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { echo "ID {$id}: NO ENCONTRADA\n"; continue; }
    if (empty($row['xml_firmado'])) { echo "ID {$id} ({$row['e_ncf']}): SIN xml_firmado\n"; continue; }

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadXML($row['xml_firmado']);
    libxml_clear_errors();

    $ncfMod   = xmlText($doc, 'NCFModificado');
    $fechaXml = xmlText($doc, 'FechaNCFModificado');
    $codMod   = xmlText($doc, 'CodigoModificacion');
    $razon    = xmlText($doc, 'RazonModificacion');
    $fechaSql = ddmmyyyyToDate($fechaXml);

    echo "\nID {$id} | {$row['e_ncf']} | E{$row['tipo_ecf']}\n";
    echo "  NCFModificado      : " . ($ncfMod ?? '(vacio)') . "\n";
    echo "  FechaNCFModificado : " . ($fechaXml ?? '(vacio)') . "  -> {$fechaSql}\n";
    echo "  CodigoModificacion : " . ($codMod ?? '(vacio)') . "\n";
    echo "  RazonModificacion  : " . ($razon ?? '(vacio)') . "\n";

    $updates[] = sprintf(
        "UPDATE facturas SET ncf_modificado = %s, fecha_ncf_modificado = %s, codigo_modificacion = %s, razon_modificacion = %s WHERE id = %d; -- %s",
        sqlVal($ncfMod), sqlVal($fechaSql), sqlVal($codMod), sqlVal($razon), $id, $row['e_ncf']
    );
}

$sql = "-- Backfill InformacionReferencia (NCF modificado / motivo) para las notas\n"
     . "-- E33/E34 del run final de fase 4. Valores extraidos del XML firmado real\n"
     . "-- (lo que DGII valido). Generado: " . date('Y-m-d H:i:s') . "\n\n"
     . implode("\n", $updates) . "\n";

$outFile = __DIR__ . '/update_notas_fase4.sql';
file_put_contents($outFile, $sql);

echo "\n" . str_repeat('=', 70) . "\n";
echo "SQL generado en: {$outFile}\n";
echo str_repeat('=', 70) . "\n";
echo $sql;
