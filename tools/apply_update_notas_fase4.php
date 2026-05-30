<?php
// Aplica el backfill de InformacionReferencia a las notas E33/E34 del run final
// de fase 4. Extrae los valores del XML firmado (fuente de verdad) y los escribe
// en facturas, luego verifica leyendo de vuelta. Idempotente.
require_once __DIR__ . '/../src/Database.php';

$ids = [1277, 1278, 1279];

function xmlText(DOMDocument $doc, string $tag): ?string
{
    $n = $doc->getElementsByTagName($tag);
    if ($n->length === 0) return null;
    $v = trim($n->item(0)->textContent);
    return $v === '' ? null : $v;
}
function ddmmyyyyToDate(?string $v): ?string
{
    if (!$v) return null;
    $dt = DateTime::createFromFormat('d-m-Y', $v);
    return $dt ? $dt->format('Y-m-d') : null;
}

$db = Database::getInstance()->getConnection();
$db->beginTransaction();
try {
    foreach ($ids as $id) {
        $sel = $db->prepare('SELECT e_ncf, xml_firmado FROM facturas WHERE id = :id');
        $sel->execute([':id' => $id]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['xml_firmado'])) { echo "ID {$id}: sin XML, omitida\n"; continue; }

        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadXML($row['xml_firmado']);
        libxml_clear_errors();

        $ncfMod = xmlText($doc, 'NCFModificado');
        $fecha  = ddmmyyyyToDate(xmlText($doc, 'FechaNCFModificado'));
        $cod    = xmlText($doc, 'CodigoModificacion');
        $razon  = xmlText($doc, 'RazonModificacion');

        $upd = $db->prepare(
            'UPDATE facturas SET ncf_modificado = :n, fecha_ncf_modificado = :f,
                    codigo_modificacion = :c, razon_modificacion = :r WHERE id = :id'
        );
        $upd->execute([':n' => $ncfMod, ':f' => $fecha, ':c' => $cod, ':r' => $razon, ':id' => $id]);
        echo "ID {$id} ({$row['e_ncf']}): filas afectadas = {$upd->rowCount()}\n";
    }
    $db->commit();
    echo "\nCOMMIT OK\n\n";
} catch (Throwable $e) {
    $db->rollBack();
    echo "ROLLBACK: " . $e->getMessage() . "\n";
    exit(1);
}

// Verificacion: leer de vuelta
echo str_repeat('-', 70) . "\nVerificacion (valores ya guardados en BD):\n" . str_repeat('-', 70) . "\n";
$ver = $db->query('SELECT id, e_ncf, tipo_ecf, ncf_modificado, fecha_ncf_modificado, codigo_modificacion, razon_modificacion FROM facturas WHERE id IN (1277,1278,1279) ORDER BY id');
foreach ($ver->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}
