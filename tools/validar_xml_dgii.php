<?php
/**
 * validar_xml_dgii.php — Valida e-CF firmados contra los XSD oficiales de DGII.
 *
 * Cuando DGII rechaza con "El formato del XML no es válido" no dice que campo
 * esta mal. Esto si: valida cada XML contra el XSD de su tipo y muestra el
 * elemento y la regla que falla (ej. "RazonSocialComprador: minLength 1").
 *
 * Fuentes (elige una):
 *   --dir=tools/xml_integracion   carpeta con los XML (default)
 *   --file=ruta/al/E310000000039.xml
 *   --tenant-id=3                 los saca del respaldo master.ecf_integracion_backup
 *
 * Uso CLI:
 *   php tools/validar_xml_dgii.php --dir=tools/xml_integracion
 *   php tools/validar_xml_dgii.php --tenant-id=3 --limit=25
 *
 * Por web (server sin shell): public/validar_xml_dgii.php?token=<CERT_RUN_TOKEN>
 *
 * Nota sobre los XSD de DGII: el de e-CF 31 trae un typo — declara
 * `name=" IndicadorServicioTodoIncluidoType"` con un espacio al inicio, y por eso
 * ni siquiera carga como esquema. Se corrige en memoria antes de validar; los
 * archivos de samples/ no se tocan.
 */

const VXD_TIPOS = ['31', '32', '33', '34', '41', '43', '44', '45', '46', '47'];

/**
 * @param array $o dir, file, tenant_id, limit, xsd_dir
 * @return int 0 si todos validan, 1 si alguno falla
 */
function validarXmlsDgii(array $o): int
{
    $raiz = dirname(__DIR__);
    $xsdDir = (string) ($o['xsd_dir'] ?? ($raiz . '/samples'));
    $limit = max(1, (int) ($o['limit'] ?? 100));

    $docs = [];   // [nombre => xml]
    if (!empty($o['file'])) {
        $f = (string) $o['file'];
        if (!is_file($f)) {
            vxdOut("No existe el archivo: $f\n");
            return 1;
        }
        $docs[basename($f, '.xml')] = (string) file_get_contents($f);
    } elseif (!empty($o['tenant_id'])) {
        $docs = vxdDesdeMaster((int) $o['tenant_id'], $limit);
        if ($docs === null) {
            return 1;
        }
    } else {
        $dir = (string) ($o['dir'] ?? ($raiz . '/tools/xml_integracion'));
        if (!is_dir($dir)) {
            vxdOut("No existe la carpeta: $dir\n");
            return 1;
        }
        $archivos = glob(rtrim($dir, "/\\") . DIRECTORY_SEPARATOR . '*.xml') ?: [];
        sort($archivos);
        foreach (array_slice($archivos, 0, $limit) as $f) {
            $docs[basename($f, '.xml')] = (string) file_get_contents($f);
        }
        vxdOut("Carpeta: $dir\n");
    }

    if ($docs === []) {
        vxdOut("No hay XML que validar.\n");
        return 1;
    }

    vxdOut(count($docs) . " documento(s)\n\n");
    libxml_use_internal_errors(true);

    $ok = 0;
    $fallan = 0;
    $sinXsd = 0;
    foreach ($docs as $nombre => $xml) {
        $tipo = vxdTipoDesdeNombre($nombre, $xml);
        $xsd = vxdXsdParaTipo($tipo, $xsdDir);
        if ($xsd === null) {
            vxdOut(sprintf("%-18s SIN XSD (tipo %s)\n", $nombre, $tipo ?: '?'));
            $sinXsd++;
            continue;
        }

        $doc = new DOMDocument();
        if (!$doc->loadXML($xml)) {
            vxdOut(sprintf("%-18s XML MAL FORMADO\n", $nombre));
            vxdErrores();
            $fallan++;
            continue;
        }
        libxml_clear_errors();

        if ($doc->schemaValidateSource($xsd)) {
            vxdOut(sprintf("%-18s OK  (E%s)\n", $nombre, $tipo));
            $ok++;
            continue;
        }
        vxdOut(sprintf("%-18s FALLA  (E%s)\n", $nombre, $tipo));
        vxdErrores();
        $fallan++;
    }

    vxdOut("\n" . str_repeat('=', 58) . "\n");
    vxdOut(sprintf("%d OK · %d con errores · %d sin XSD\n", $ok, $fallan, $sinXsd));
    if ($fallan > 0) {
        vxdOut("\nSi el unico error es \"Missing child element(s)\" al final del ECF,\n"
            . "el XML esta sin firmar: el XSD exige la Signature, que se agrega al firmar.\n");
    }
    return $fallan > 0 ? 1 : 0;
}

/** Tipo de e-CF: del nombre (E31...) o, si no, del propio XML. */
function vxdTipoDesdeNombre(string $nombre, string $xml): ?string
{
    if (preg_match('/^E(\d{2})\d{10}$/', $nombre, $m) && in_array($m[1], VXD_TIPOS, true)) {
        return $m[1];
    }
    if (preg_match('#<TipoeCF>\s*(\d{2})\s*</TipoeCF>#', $xml, $m) && in_array($m[1], VXD_TIPOS, true)) {
        return $m[1];
    }
    if (preg_match('#<eNCF>\s*E(\d{2})#', $xml, $m) && in_array($m[1], VXD_TIPOS, true)) {
        return $m[1];
    }
    return null;
}

/** Devuelve el XSD del tipo, ya corregido en memoria. Null si no esta. */
function vxdXsdParaTipo(?string $tipo, string $xsdDir): ?string
{
    static $cache = [];
    if ($tipo === null) {
        return null;
    }
    if (array_key_exists($tipo, $cache)) {
        return $cache[$tipo];
    }
    $ruta = rtrim($xsdDir, "/\\") . DIRECTORY_SEPARATOR . "e-CF $tipo v.1.0.xsd";
    if (!is_file($ruta)) {
        return $cache[$tipo] = null;
    }
    $xsd = (string) file_get_contents($ruta);
    // Typo de DGII: name=" IndicadorServicioTodoIncluidoType" (espacio al inicio)
    // hace que el esquema entero no cargue. Se normaliza cualquier name=/type=
    // con espacios sobrantes, que es el mismo error en distintas versiones.
    $xsd = preg_replace('/\b(name|type)="\s+([^"]*?)\s*"/', '$1="$2"', $xsd);
    return $cache[$tipo] = $xsd;
}

/** Imprime los errores de libxml, sin repetir el mismo mensaje. */
function vxdErrores(int $max = 6): void
{
    $vistos = [];
    foreach (libxml_get_errors() as $e) {
        $msg = trim($e->message);
        if ($msg === '' || isset($vistos[$msg])) {
            continue;
        }
        $vistos[$msg] = true;
        vxdOut("     - $msg\n");
        if (count($vistos) >= $max) {
            break;
        }
    }
    libxml_clear_errors();
}

/** XML firmados del respaldo del master, para un tenant de integracion. */
function vxdDesdeMaster(int $tenantId, int $limit): ?array
{
    require_once __DIR__ . '/../src/MasterDatabase.php';
    try {
        $pdo = MasterDatabase::getInstance()->getConnection();
        $st = $pdo->prepare(
            'SELECT e_ncf, xml_firmado FROM ecf_integracion_backup
             WHERE tenant_id = :t AND xml_firmado IS NOT NULL
             ORDER BY id DESC LIMIT ' . $limit
        );
        $st->execute([':t' => $tenantId]);
    } catch (Throwable $e) {
        vxdOut('No se pudo leer el respaldo del master: ' . $e->getMessage() . "\n");
        return null;
    }
    $docs = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $docs[(string) $row['e_ncf']] = (string) $row['xml_firmado'];
    }
    vxdOut("Fuente: master.ecf_integracion_backup (tenant $tenantId)\n");
    return $docs;
}

function vxdOut(string $s): void
{
    if (PHP_SAPI === 'cli') {
        fwrite(STDOUT, $s);
        return;
    }
    echo $s;
    @flush();
}

// Entrada CLI. Por web entra por public/validar_xml_dgii.php.
if (PHP_SAPI === 'cli' && isset($argv) && realpath($argv[0]) === realpath(__FILE__)) {
    $o = getopt('', ['dir::', 'file::', 'tenant-id::', 'limit::', 'xsd-dir::']);
    exit(validarXmlsDgii([
        'dir' => $o['dir'] ?? null,
        'file' => $o['file'] ?? null,
        'tenant_id' => $o['tenant-id'] ?? null,
        'limit' => $o['limit'] ?? 100,
        'xsd_dir' => $o['xsd-dir'] ?? null,
    ]));
}
