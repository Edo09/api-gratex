<?php
require_once __DIR__ . '/../TenantResolver.php';
require_once __DIR__ . '/../Models/EmisorConfigModel.php';

/**
 * Identidad de los correos que manda el sistema (cotizaciones, facturas,
 * bienvenida) segun el tenant de la peticion.
 *
 * Antes todo salia "de Gratex <info@gratex.net>" y con copia a su personal,
 * fuera cual fuera el tenant: los clientes de otro contribuyente recibian
 * correo de Gratex y Gratex recibia sus documentos.
 *
 *   - Gratex (sin tenant resuelto, o tenant con su RNC): exactamente lo de
 *     antes — mismo From, mismo -f, mismas copias.
 *   - Otro tenant: From = "<nombre comercial> <emisor_config.correo>", -f con
 *     ese mismo correo (los rebotes le llegan a el) y sin copias a Gratex. Sin
 *     correo valido no hay remitente y no se envia: es mejor que mandar en
 *     nombre de Gratex. OJO: si el correo es de un dominio publico (hotmail,
 *     gmail...) el servidor no esta autorizado por su SPF/DMARC y el proveedor
 *     del destinatario puede mandarlo a spam o rechazarlo; ver
 *     docs/integrations/alta-tenant-runbook.md.
 *
 * Los metodos que reciben $tenant/$emisor no tocan la DB, para que
 * tools/test_tenant_mail.php los pruebe sin conexion.
 */
final class TenantMail
{
    /**
     * Gratex se reconoce por RNC y no por id: el id es un auto-increment del
     * master y cambiaria si el tenant se volviera a dar de alta.
     */
    public const GRATEX_RNC = '131256432';
    public const GRATEX_NOMBRE = 'Gratex';
    public const GRATEX_CORREO = 'info@gratex.net';

    /** Copias internas de Gratex en cotizaciones y facturas. */
    public const COPIAS_GRATEX_DOCUMENTOS = ['edwin@gratex.net', 'omareogm09@gmail.com', 'info@gratex.net'];

    /** Tenant de la peticion (null en single-tenant / sin resolver). */
    public static function tenantActual(): ?array
    {
        return TenantResolver::current();
    }

    /**
     * Sin tenant resuelto es Gratex: es el modo single-tenant de siempre (la
     * misma regla que siguen los generadores de PDF).
     */
    public static function esGratex(?array $tenant): bool
    {
        return $tenant === null || (string) ($tenant['rnc'] ?? '') === self::GRATEX_RNC;
    }

    /**
     * Remitente para el tenant dado.
     *
     * @param array|null $emisor Fila de emisor_config del tenant (no se usa
     *                           para Gratex).
     * @return array{nombre:string, correo:string}|null null si un tenant que no
     *         es Gratex no tiene un correo valido: no se debe enviar.
     */
    public static function remitente(?array $tenant, ?array $emisor): ?array
    {
        if (self::esGratex($tenant)) {
            return ['nombre' => self::GRATEX_NOMBRE, 'correo' => self::GRATEX_CORREO];
        }
        $correo = trim((string) ($emisor['correo'] ?? ''));
        if (!self::correoParaEnvelope($correo)) {
            return null;
        }
        // El mismo nombre que encabeza el PDF (nombre comercial, si no la razon
        // social); el de master como ultimo recurso.
        $nombre = '';
        foreach ([$emisor['nombre_comercial'] ?? null, $emisor['razon_social'] ?? null, $tenant['nombre'] ?? null] as $candidato) {
            $nombre = self::limpiarNombre((string) $candidato);
            if ($nombre !== '') {
                break;
            }
        }
        return ['nombre' => $nombre, 'correo' => $correo];
    }

    /**
     * Remitente del tenant de la peticion; lee su emisor_config si no es
     * Gratex. null (con el detalle en error_log) si no se debe enviar.
     */
    public static function remitenteDelTenant(): ?array
    {
        $tenant = self::tenantActual();
        if (self::esGratex($tenant)) {
            return self::remitente($tenant, null);
        }
        $idTenant = $tenant['id'] ?? '?';
        try {
            $emisor = (new EmisorConfigModel())->get();
        } catch (\Throwable $e) {
            error_log('[TenantMail] no se pudo leer emisor_config del tenant ' . $idTenant . ': ' . $e->getMessage());
            return null;
        }
        $remitente = self::remitente($tenant, $emisor);
        if ($remitente === null) {
            error_log('[TenantMail] el tenant ' . $idTenant . ' no tiene un emisor_config.correo valido; no se envia el correo');
        }
        return $remitente;
    }

    /**
     * Lista "To" para mail(): el cliente y, solo para Gratex, sus copias. Un
     * correo de cliente invalido se descarta (con CR/LF inyectaria cabeceras).
     * Cadena vacia = nadie a quien enviar.
     */
    public static function destinatarios(?array $tenant, string $clientEmail, array $copiasGratex): string
    {
        $lista = [];
        $cliente = trim($clientEmail);
        if ($cliente !== '' && filter_var($cliente, FILTER_VALIDATE_EMAIL) !== false) {
            $lista[] = $cliente;
        }
        if (self::esGratex($tenant)) {
            $lista = array_merge($lista, $copiasGratex);
        }
        return implode(', ', $lista);
    }

    /** Linea "From: ...\r\n" del remitente. */
    public static function cabeceraFrom(array $remitente): string
    {
        $nombre = self::limpiarNombre((string) ($remitente['nombre'] ?? ''));
        $correo = (string) $remitente['correo'];
        if ($nombre === '') {
            return "From: {$correo}\r\n";
        }
        return 'From: ' . self::nombreParaCabecera($nombre) . " <{$correo}>\r\n";
    }

    /** Quinto parametro de mail(): el envelope sender (Return-Path). */
    public static function parametroEnvelope(array $remitente): string
    {
        return '-f' . $remitente['correo'];
    }

    /**
     * Carpeta donde se guarda el PDF que se adjunta: <raiz>/<carpeta>/<tenant_id>/
     * para que dos tenants con el mismo codigo no se pisen el archivo. Sin
     * tenant, la carpeta de siempre.
     */
    public static function rutaCarpetaPdf(?array $tenant, string $carpeta): string
    {
        $ruta = dirname(__DIR__, 2) . '/' . $carpeta . '/';
        $idTenant = (int) ($tenant['id'] ?? 0);
        return $idTenant > 0 ? $ruta . $idTenant . '/' : $ruta;
    }

    /** rutaCarpetaPdf(), creandola si no existe. */
    public static function carpetaPdf(?array $tenant, string $carpeta): string
    {
        $ruta = self::rutaCarpetaPdf($tenant, $carpeta);
        if (!is_dir($ruta) && !mkdir($ruta, 0755, true) && !is_dir($ruta)) {
            error_log('[TenantMail] no se pudo crear la carpeta de PDF ' . $ruta . ' (revisa permisos)');
        }
        return $ruta;
    }

    /**
     * El correo va tambien al -f de sendmail, asi que ademas de ser valido se
     * limita a caracteres corrientes: FILTER_VALIDATE_EMAIL acepta ' / | etc.
     * en la parte local.
     */
    private static function correoParaEnvelope(string $correo): bool
    {
        return filter_var($correo, FILTER_VALIDATE_EMAIL) !== false
            && preg_match('/^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/', $correo) === 1;
    }

    /** Sin caracteres de control (CR/LF inyectarian cabeceras) ni espacios de mas. */
    private static function limpiarNombre(string $nombre): string
    {
        if (preg_match('//u', $nombre) !== 1) {
            // UTF-8 invalido: preg_split('//u') fallaria al codificar.
            $nombre = preg_replace('/[\x80-\xFF]/', '', $nombre);
        }
        $nombre = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $nombre);
        return trim(preg_replace('/\s+/u', ' ', $nombre));
    }

    /**
     * Nombre listo para el From. ASCII sin "specials" va tal cual (asi
     * "Gratex" queda identico a antes); lo demas (acentos, la coma de
     * "S.R.L.") como palabras RFC 2047 de hasta 75 caracteres: 45 bytes de
     * UTF-8 son 60 de base64, + 12 del envoltorio.
     */
    private static function nombreParaCabecera(string $nombre): string
    {
        if (preg_match('/^[A-Za-z0-9!#$%&\'*+\/=?^_`{|}~ -]+$/', $nombre) === 1) {
            return $nombre;
        }
        $trozos = [];
        $actual = '';
        foreach (preg_split('//u', $nombre, -1, PREG_SPLIT_NO_EMPTY) as $caracter) {
            if (strlen($actual) + strlen($caracter) > 45) {
                $trozos[] = $actual;
                $actual = '';
            }
            $actual .= $caracter;
        }
        if ($actual !== '') {
            $trozos[] = $actual;
        }
        return implode(' ', array_map(fn(string $t) => '=?UTF-8?B?' . base64_encode($t) . '?=', $trozos));
    }
}
