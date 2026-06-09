<?php
/**
 * import_recibido.php — Importacion manual de e-CF recibidos a ecf_recibidos.
 *
 * Sirve directo (bajo /api/public/). Para cuando un emisor te envia un e-CF
 * pero su sistema NO completa el handshake de autenticacion contra tu servicio
 * de recepcion (semilla -> ValidacionCertificado -> Bearer), por lo que tu
 * endpoint /api/ecf/recepcion lo rechaza (401) y nunca queda guardado.
 *
 * Subes aqui el/los XML firmados y se guardan en ecf_recibidos con
 * estado='RECIBIDO' y aprobacion_comercial=NULL (pendiente de decidir), para
 * que luego puedas aprobar/rechazar (POST /api/aprobaciones-comerciales).
 *
 *   https://gratex.net/api/public/import_recibido.php
 *
 * Multi-tenant: el e-CF se guarda en la DB del tenant cuyo RNC == RNCComprador
 * del XML (debe existir en master.tenants, activo). Token-gated + usar HTTPS.
 *
 * Edita IMPORT_RECIBIDO_TOKEN antes de usar.
 */

const IMPORT_RECIBIDO_TOKEN = 'gratextoken.';

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Models/ecfRecibidoModel.php';
require_once __DIR__ . '/../src/Utils/FacturacionElectronica/IncomingXmlValidator.php';

// .env al inicio: ecfRecibidoModel/TenantResolver leen MULTI_TENANT_ENABLED y
// credenciales de DB; sin esto resolverian la DB equivocada.
Database::loadEnv();

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

if (!$isPost) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Importar e-CF recibido</title>'
        . '<style>body{font-family:system-ui,sans-serif;background:#0f172a;color:#e2e8f0;display:flex;'
        . 'justify-content:center;padding:32px}form{background:#1e293b;border:1px solid #334155;'
        . 'border-radius:12px;padding:24px;width:100%;max-width:480px}label{display:block;font-size:13px;'
        . 'color:#94a3b8;margin:12px 0 4px}input,select{width:100%;padding:10px;background:#0b1220;color:#e2e8f0;'
        . 'border:1px solid #334155;border-radius:8px;box-sizing:border-box}button{width:100%;margin-top:18px;'
        . 'padding:12px;background:#38bdf8;color:#06283d;border:none;border-radius:8px;font-weight:600;cursor:pointer}'
        . 'h1{font-size:18px}p{font-size:13px;color:#94a3b8;line-height:1.5}</style></head><body>'
        . '<form method="post" enctype="multipart/form-data">'
        . '<h1>Importar e-CF recibido</h1>'
        . '<p>Sube el/los XML firmados que te emitieron. Se guardan en tu bandeja '
        . '(ecf_recibidos) como pendientes de aprobar/rechazar.</p>'
        . '<label>Token</label><input type="password" name="token" autocomplete="off" required>'
        . '<label>Archivos XML (puedes seleccionar varios)</label>'
        . '<input type="file" name="xml[]" accept=".xml,text/xml,application/xml" multiple required>'
        . '<label>Ambiente</label><select name="ambiente">'
        . '<option value="">(usar DGII_ECF_ENVIRONMENT del servidor)</option>'
        . '<option value="ecf">ecf (produccion)</option>'
        . '<option value="certecf">certecf</option>'
        . '<option value="testecf">testecf</option>'
        . '</select>'
        . '<button type="submit">Importar</button>'
        . '</form></body></html>';
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

if (!hash_equals(IMPORT_RECIBIDO_TOKEN, (string) ($_POST['token'] ?? ''))) {
    http_response_code(403);
    exit("Token invalido.\n");
}

$ambienteOverride = trim((string) ($_POST['ambiente'] ?? ''));
$ambienteDefault = $ambienteOverride !== '' ? $ambienteOverride : (getenv('DGII_ECF_ENVIRONMENT') ?: null);

$multiTenant = filter_var(
    getenv('MULTI_TENANT_ENABLED') ?: ($_ENV['MULTI_TENANT_ENABLED'] ?? false),
    FILTER_VALIDATE_BOOLEAN
);
if ($multiTenant) {
    require_once __DIR__ . '/../src/TenantResolver.php';
}

$files = normalizeUploadedFiles($_FILES['xml'] ?? null);
if (!$files) {
    http_response_code(400);
    exit("No se recibio ningun archivo XML en el campo 'xml'.\n");
}

$ok = 0;
$dup = 0;
$err = 0;
$lastImport = []; // metadatos del ultimo XML procesado (lo llena importarUno via global)

foreach ($files as $file) {
    $name = $file['name'] !== '' ? $file['name'] : '(sin nombre)';

    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo "ERROR  {$name}: fallo de carga (codigo {$file['error']}).\n";
        $err++;
        continue;
    }

    $xml = (string) file_get_contents($file['tmp_name']);
    if (trim($xml) === '') {
        echo "ERROR  {$name}: archivo vacio.\n";
        $err++;
        continue;
    }

    try {
        $result = importarUno($xml, $ambienteDefault, $multiTenant);
        if ($result === 'DUP') {
            echo "DUP    {$name}: {$lastImport['e_ncf']} de {$lastImport['rnc_emisor']} ya existe. Omitido.\n";
            $dup++;
        } else {
            echo "OK     {$name}: {$lastImport['e_ncf']} de {$lastImport['rnc_emisor']} guardado "
                . "(track_id {$result}, firma {$lastImport['firma']}, ambiente {$lastImport['ambiente']}).\n";
            $ok++;
        }
    } catch (Throwable $e) {
        echo "ERROR  {$name}: " . $e->getMessage() . "\n";
        $err++;
    }
}

echo "\nResumen: {$ok} guardado(s), {$dup} duplicado(s), {$err} error(es).\n";

// --- helpers --------------------------------------------------------------

/**
 * Importa un e-CF a ecf_recibidos. Devuelve el track_id, o 'DUP' si ya existia.
 * Lanza excepcion con mensaje legible ante datos faltantes o tenant no resuelto.
 */
function importarUno(string $xml, ?string $ambienteDefault, bool $multiTenant): string
{
    global $lastImport;
    $lastImport = ['e_ncf' => '?', 'rnc_emisor' => '?', 'firma' => '?', 'ambiente' => '?'];

    $dom = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $loaded = $dom->loadXML($xml);
    libxml_use_internal_errors($prev);
    if (!$loaded) {
        throw new RuntimeException('XML mal formado, no se pudo parsear.');
    }

    $root = $dom->documentElement ? $dom->documentElement->localName : '';
    if (strcasecmp($root, 'ECF') !== 0) {
        throw new RuntimeException("El XML no es un ECF (root: {$root}).");
    }

    $rncEmisor    = firstTag($dom, 'RNCEmisor');
    $eNcf         = firstTag($dom, 'eNCF');
    $rncComprador = firstTag($dom, 'RNCComprador');
    $tipoEcf      = firstTag($dom, 'TipoeCF');
    $razonSocial  = firstTag($dom, 'RazonSocialEmisor');
    $montoTotal   = firstTag($dom, 'MontoTotal');
    $fechaEmision = firstTag($dom, 'FechaEmision');

    if ($rncEmisor === null || $eNcf === null) {
        throw new RuntimeException('El XML no contiene RNCEmisor o eNCF.');
    }

    // Firma: best-effort (puede no verificarse offline por cadena/OCSP). No bloquea.
    $firma = 'NO_VALIDADA';
    try {
        $validation = (new IncomingXmlValidator())->loadAndValidate($xml);
        if (is_array($validation) && isset($validation['firma'])) {
            $firma = (string) $validation['firma'];
        }
    } catch (Throwable $e) {
        $firma = 'NO_VALIDADA';
    }

    // Multi-tenant: el e-CF pertenece al tenant cuyo RNC == RNCComprador.
    if ($multiTenant) {
        if ($rncComprador === null || $rncComprador === '') {
            throw new RuntimeException('El XML no trae RNCComprador; no se puede determinar el tenant destino.');
        }
        if (!TenantResolver::resolveByRnc($rncComprador)) {
            throw new RuntimeException("RNCComprador ({$rncComprador}) no esta registrado como tenant en master. "
                . 'Registralo (o revisa su RNC/activo) antes de importar.');
        }
        if (TenantResolver::isIntegration()) {
            throw new RuntimeException("El tenant del RNC {$rncComprador} es tipo integracion (sin DB propia). "
                . 'Esta herramienta importa a la DB de un tenant tipo app.');
        }
    }

    $ambiente = $ambienteDefault;
    $lastImport = [
        'e_ncf' => $eNcf,
        'rnc_emisor' => $rncEmisor,
        'firma' => $firma,
        'ambiente' => $ambiente ?? '(null)',
    ];

    // Modelo despues de resolver el tenant: se ata a la DB correcta.
    $model = new ecfRecibidoModel();
    if ($model->exists($rncEmisor, $eNcf)) {
        return 'DUP';
    }

    $trackId = strtoupper(bin2hex(random_bytes(16)));
    $model->save([
        'track_id' => $trackId,
        'tipo_ecf' => $tipoEcf,
        'e_ncf' => $eNcf,
        'rnc_emisor' => $rncEmisor,
        'razon_social_emisor' => $razonSocial,
        'rnc_comprador' => $rncComprador,
        'monto_total' => $montoTotal !== null ? (float) $montoTotal : null,
        'fecha_emision' => normalizeFecha($fechaEmision),
        'estado' => 'RECIBIDO',
        'codigo_resultado' => null,
        'mensaje_resultado' => 'Importado manualmente (sin recepcion automatica).',
        'xml_firmado' => $xml,
        'validacion_firma' => $firma,
        'ambiente' => $ambiente,
    ]);

    return $trackId;
}

/** Texto del primer elemento con ese nombre local (ignora namespaces). */
function firstTag(DOMDocument $dom, string $tag): ?string
{
    $nodes = $dom->getElementsByTagName($tag);
    if ($nodes->length === 0) {
        return null;
    }
    $val = trim($nodes->item(0)->textContent);
    return $val === '' ? null : $val;
}

/** Convierte dd-mm-YYYY (formato e-CF) a YYYY-mm-dd para la columna DATE. */
function normalizeFecha(?string $fecha): ?string
{
    if ($fecha === null || $fecha === '') {
        return null;
    }
    if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $fecha, $m)) {
        return "{$m[3]}-{$m[2]}-{$m[1]}";
    }
    return $fecha; // ya viene en otro formato; dejar que la DB lo maneje
}

/** Normaliza $_FILES['xml'] (single o multiple) a una lista uniforme. */
function normalizeUploadedFiles($field): array
{
    if (!is_array($field) || !isset($field['name'])) {
        return [];
    }
    $out = [];
    if (is_array($field['name'])) {
        $count = count($field['name']);
        for ($i = 0; $i < $count; $i++) {
            if (($field['name'][$i] ?? '') === '' && ($field['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = [
                'name' => (string) $field['name'][$i],
                'tmp_name' => (string) ($field['tmp_name'][$i] ?? ''),
                'error' => (int) ($field['error'][$i] ?? UPLOAD_ERR_NO_FILE),
            ];
        }
    } else {
        if (($field['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $out[] = [
                'name' => (string) $field['name'],
                'tmp_name' => (string) ($field['tmp_name'] ?? ''),
                'error' => (int) ($field['error'] ?? UPLOAD_ERR_NO_FILE),
            ];
        }
    }
    return $out;
}
