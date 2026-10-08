<?php

require_once __DIR__ . '/DgiiAuthService.php';
require_once __DIR__ . '/DgiiXmlSigner.php';
require_once __DIR__ . '/DgiiReceptionService.php';
require_once __DIR__ . '/ECFXmlBuilder.php';
require_once __DIR__ . '/RFCEXmlBuilder.php';
require_once __DIR__ . '/EcfItemMapper.php';
require_once __DIR__ . '/EcfUsuarioException.php';
require_once __DIR__ . '/EstadosEcf.php';
require_once __DIR__ . '/EcfNumeroOcupadoException.php';
require_once __DIR__ . '/EcfEnvioInciertoException.php';
require_once __DIR__ . '/InformacionReferencia.php';
require_once __DIR__ . '/../../AuditLogger.php';
require_once __DIR__ . '/../../CertResolver.php';
require_once __DIR__ . '/../../Database.php';
require_once __DIR__ . '/../../Models/EmisorConfigModel.php';
require_once __DIR__ . '/../../Models/ncfModel.php';
require_once __DIR__ . '/../../Models/provinciaMunicipioModel.php';

/**
 * Orchestrates the full e-CF emission flow:
 *   1. Reserve next e-NCF for the requested type
 *   2. Build the XML
 *   3. Sign with the certificate
 *   4. Get DGII auth token
 *   5. POST signed XML to DGII reception
 *   6. Return result with track_id, status, signed XML and codigo_seguridad
 */
class ECFEmissionService
{
    private const RFCE_THRESHOLD = 250000.00;

    /** Claves de proyectarResultado(), en este orden. */
    private const CLAVES_PROYECCION = ['estado', 'track_id', 'dgii_status_code', 'dgii_response', 'rfce_estado', 'rfce_track_id', 'rfce_status_code', 'secuencia_utilizada'];

    /** Tope de un dgii_response que llego como texto (cuerpo crudo, DgiiAuthService.php:150). */
    private const MAX_RESPUESTA = 4000;

    // Nombre de cada tipo como lo ve el usuario, para los mensajes de
    // EcfUsuarioException (el texto tecnico sigue diciendo "E31").
    private const NOMBRES_TIPO = [
        '31' => 'Factura de Crédito Fiscal',
        '32' => 'Factura de Consumo',
        '33' => 'Nota de Débito',
        '34' => 'Nota de Crédito',
        '41' => 'Comprobante de Compras',
        '43' => 'Gastos Menores',
        '44' => 'Regímenes Especiales',
        '45' => 'Gubernamental',
        '46' => 'Comprobante de Exportaciones',
        '47' => 'Comprobante para Pagos al Exterior',
    ];

    private DgiiAuthService $auth;
    private DgiiXmlSigner $signer;
    private DgiiReceptionService $reception;
    private ECFXmlBuilder $builder;
    private RFCEXmlBuilder $rfceBuilder;
    // Perezosos a proposito: sus constructores abren la conexion a la DB DEL
    // TENANT, y un tenant tipo integracion no tiene. Instanciarlos aqui hacia
    // que la sola construccion del servicio intentara conectar (cayendo a las
    // credenciales globales del .env) y matara la emision antes de empezar.
    private ?EmisorConfigModel $emisorModel = null;
    private ?ncfModel $ncfModel = null;

    /**
     * Filas reservadas cuyo envio sigue en curso en ESTE proceso:
     * factura_id => [callable $persistencia, array $reserva, array $ctxSinXml].
     * Estatico a proposito: cubre a cualquier instancia del servicio en la misma
     * peticion. El registro de cierre las marca ENVIO_INCIERTO si el proceso
     * muere (fatal, memoria, timeout) antes de 'confirmar'/'incierto'.
     */
    private static array $enVuelo = [];
    private static bool $shutdownRegistrado = false;

    /**
     * Las cuatro dependencias son opcionales: sin argumentos queda igual que
     * antes. Las pruebas inyectan dobles (tools/test_write_ahead_ecf.php). Un
     * ncfModel no inyectado sigue perezoso (ncfModel()).
     */
    public function __construct(
        ?DgiiAuthService $auth = null,
        ?DgiiXmlSigner $signer = null,
        ?DgiiReceptionService $reception = null,
        ?ncfModel $ncf = null
    ) {
        $this->auth = $auth ?? new DgiiAuthService();
        $this->signer = $signer ?? new DgiiXmlSigner();
        // La recepcion por defecto usa el MISMO auth, inyectado o no (como antes).
        $this->reception = $reception ?? new DgiiReceptionService($this->auth);
        $this->ncfModel = $ncf;
        $this->builder = new ECFXmlBuilder();
        $this->rfceBuilder = new RFCEXmlBuilder();
    }

    /** emisor_config vive en la DB del tenant: solo para tenants tipo app. */
    private function emisorModel(): EmisorConfigModel
    {
        return $this->emisorModel ??= new EmisorConfigModel();
    }

    /** ncf_sequences vive en la DB del tenant: solo para tenants tipo app. */
    private function ncfModel(): ncfModel
    {
        return $this->ncfModel ??= new ncfModel();
    }

    /**
     * true si el e-NCF ya lo ocupa, en ESTE ambiente, una factura que NO se
     * puede re-emitir. Certificacion y produccion numeran aparte (migracion
     * 027): contar los de prueba hacia saltar el rango que la DGII autoriza en
     * produccion. Una fila sin ambiente (anterior a la columna) cuenta como
     * ocupada: no se sabe de cual era. Una fila en vuelo (PENDIENTE_ENVIO,
     * ENVIO_INCIERTO) tambien: no esta en REINTENTABLES.
     * Mismos estados reintentables (EstadosEcf::REINTENTABLES) que
     * facturaModel::reservarFacturaECF, para que los dos chequeos no puedan
     * discrepar. Fail-open: ante un error de consulta devuelve false; la
     * reserva (SELECT ... FOR UPDATE) y la clave unica uk_e_ncf_amb siguen
     * siendo la red.
     */
    private function eNcfYaUsado(string $eNcf, string $ambiente): bool
    {
        try {
            $stmt = Database::getInstance()->getConnection()->prepare(
                'SELECT estado_dgii FROM facturas
                 WHERE e_ncf = :e AND (ambiente_dgii = :amb OR ambiente_dgii IS NULL)'
            );
            $stmt->execute([':e' => $eNcf, ':amb' => $ambiente]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $estado) {
                if (!in_array((string) $estado, EstadosEcf::REINTENTABLES, true)) {
                    return true;
                }
            }
            return false;
        } catch (Throwable $e) {
            error_log('[ECF] eNcfYaUsado fallo: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * La reserva de la fila COMMITEADA en PENDIENTE_ENVIO de este e-NCF en este
     * ambiente (el que guarda la reserva), o null si no hay. Es el unico lugar
     * que decide quien es duena del numero cuando el gancho no cumplio: la base
     * manda, no lo que el gancho devolvio ni si lanzo despues de commitear. La
     * llama el catch previo al envio, con la transaccion colgada ya cerrada.
     * Con fila, el catch no devuelve el numero a la secuencia; sin ella, si. Si
     * la consulta falla no se sabe: se asume duena (factura_id 0), porque
     * quemar un numero es mejor que repartirlo dos veces.
     * @return array{factura_id:int, e_ncf:string, desde:string}|null
     */
    private function reservaEnLaBase(string $eNcf, string $ambiente, string $desde): ?array
    {
        try {
            $stmt = Database::getInstance()->getConnection()->prepare(
                'SELECT id FROM facturas WHERE e_ncf = :e AND ambiente_dgii = :amb AND estado_dgii = :estado'
            );
            $stmt->execute([':e' => $eNcf, ':amb' => $ambiente, ':estado' => EstadosEcf::PENDIENTE_ENVIO]);
            $id = $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('[ECF e-NCF=' . $eNcf . '] consulta de la fila reservada fallo: ' . $e->getMessage());
            $id = 0;
        }
        return $id === false ? null : ['factura_id' => (int) $id, 'e_ncf' => $eNcf, 'desde' => $desde];
    }

    /**
     * Resuelve municipio/provincia de emisor y comprador al código DGII de 6
     * dígitos usando el catálogo. Fail-open: valores sin match quedan intactos.
     */
    private function resolveUbicaciones(array &$xmlData): void
    {
        $cat = new provinciaMunicipioModel();
        foreach (['emisor', 'comprador'] as $parte) {
            if (!is_array($xmlData[$parte] ?? null)) {
                continue;
            }
            if (isset($xmlData[$parte]['municipio']) && $xmlData[$parte]['municipio'] !== '') {
                $xmlData[$parte]['municipio'] = $cat->resolve($xmlData[$parte]['municipio'], 'MUNICIPIO');
            }
            if (isset($xmlData[$parte]['provincia']) && $xmlData[$parte]['provincia'] !== '') {
                $xmlData[$parte]['provincia'] = $cat->resolve($xmlData[$parte]['provincia'], 'PROVINCIA');
            }
        }
    }

    /**
     * @param array $payload Required keys:
     *   tipo_ecf, comprador (assoc), items (array of assoc), totales (assoc).
     * @param callable|null $persistencia Gancho write-ahead,
     *   fn(string $fase, array $ctx): mixed. Sin el (integracion, gastos) la
     *   emision queda como antes. Con el:
     *   - 'reservar': ultima sentencia del try protegido, despues de autenticar.
     *       ctx: tipo_ecf, e_ncf, ambiente (el del token: el que guarda la fila),
     *       ambiente_secuencia (el del dispensador), signed_xml, codigo_seguridad,
     *       fecha_emision_dgii (Y-m-d H:i:s), flujo (ECF|RFCE), rfce_xml (?string),
     *       dispensado (bool), rango_id (?int), secuencia_valor (?int; null con
     *       un e_ncf override), desde (Y-m-d H:i:s del servidor).
     *       Tiene que COMMITEAR la fila (XML e items) y devolver
     *       ['factura_id' => int, 'e_ncf' => string, 'desde' => string], sin
     *       dejar una transaccion abierta.
     *   - 'confirmar': despues de mapEstado/extractTrackId y ANTES de reclamar
     *       la secuencia. ctx: lo de 'reservar' + reserva, estado (con RFCE_ en
     *       el flujo RFCE), track_id, dgii_response, dgii_status_code,
     *       rfce_track_id, rfce_estado, rfce_response, rfce_status_code,
     *       secuencia_utilizada (?bool). Devuelve bool: false = otra escritura
     *       ya movio la fila (CONFIRM_RACE: la secuencia no se toca).
     *   - 'incierto': lanzo el envio, el mapeo o 'confirmar', o el proceso murio
     *       con la fila en vuelo (registro de cierre). ctx: ctxSinXml(lo de
     *       'reservar') + reserva, fase_fallida (envio|confirmar|shutdown),
     *       clase, error (<= 2000 caracteres), resultado_parcial
     *       (proyectarResultado o null; el cierre no la manda). Devuelve bool:
     *       true = la fila quedo ENVIO_INCIERTO.
     * @return array {
     *   e_ncf, tipo_ecf, signed_xml, codigo_seguridad, track_id, estado,
     *   ambiente, fecha_emision_dgii, dgii_response, dgii_status_code,
     *   flujo (ECF|RFCE), factura_id (?int: la fila reservada; null sin gancho);
     *   en el flujo RFCE ademas: monto_total, rfce_xml, rfce_track_id,
     *   rfce_estado, rfce_response, rfce_status_code, aviso
     * }
     * @throws InvalidArgumentException integracion con gancho (no hay DB de tenant)
     * @throws EcfNumeroOcupadoException otra fila ya es duena del e-NCF: el
     *   contador queda avanzado y el reintento toma el siguiente
     * @throws EcfEnvioInciertoException con fila reservada, el envio o su
     *   confirmacion fallaron: la DGII puede tener el e-CF
     */
    public function emitir(array $payload, ?callable $persistencia = null): array
    {
        $tipoEcf = (string) ($payload['tipo_ecf'] ?? '');
        if (!preg_match('/^(31|32|33|34|41|43|44|45|46|47)$/', $tipoEcf)) {
            throw new RuntimeException('tipo_ecf invalido: ' . $tipoEcf);
        }

        // Modo integracion: sin DB propia. El emisor viene en el payload y el
        // cliente envia el e_ncf (no dispensamos secuencia ni leemos emisor_config).
        $integration = !empty($payload['integration']);
        if ($integration && $persistencia !== null) {
            // Un tenant de integracion no tiene DB propia: no hay donde reservar.
            throw new InvalidArgumentException('write-ahead no aplica a integracion (sin DB de tenant)');
        }
        if ($integration) {
            $emisor = is_array($payload['emisor'] ?? null) ? $payload['emisor'] : [];
            if (empty($emisor['rnc']) || empty($emisor['razon_social']) || empty($emisor['direccion'])) {
                throw new RuntimeException('Integracion: el payload debe incluir emisor con rnc, razon_social y direccion.');
            }
            $ambienteEarly = (string) ($payload['ambiente'] ?? 'ecf');
            // La ruta app normaliza los items en facturaController antes de llegar
            // aqui; la de integracion no pasa por ese controller. Sin esto los
            // items entran crudos y <MontoItem> sale 0.00 (DGII lo rechaza por el
            // MinInclusive de Decimal18D2ValidationTypeMayor).
            $payload['items'] = EcfItemMapper::map(
                is_array($payload['items'] ?? null) ? $payload['items'] : [],
                !empty($payload['strict_input'])
            );
            // Y lo mismo con los Totales: el cliente puede mandar solo las tasas
            // (o nada). Sin los montos derivados de los items, DGII rechaza con
            // "El campo MontoGravadoI1 / MontoExento ... no es valido".
            // Mismas reglas de override que facturaController: en modo estricto
            // (set de pruebas) manda lo que venga en el payload, tal cual.
            $totalesOverride = is_array($payload['totales'] ?? null)
                ? array_filter($payload['totales'], fn($v) => $v !== null && $v !== '')
                : [];
            $totalesCalc = EcfItemMapper::totales($payload['items']);
            if (!empty($payload['strict_input']) && $totalesOverride) {
                $payload['totales'] = $totalesOverride;
            } elseif ($totalesOverride) {
                $payload['totales'] = array_merge($totalesCalc, $totalesOverride);
            } else {
                $payload['totales'] = $totalesCalc;
            }
        } else {
            $emisor = $this->emisorModel()->get();
            if (!$emisor) {
                // EcfUsuarioException: mismo texto tecnico para integradores y
                // audit; la app muestra el segundo (ver la clase).
                throw new EcfUsuarioException(
                    'emisor_config no configurado. Insertar registro id=1 con datos fiscales.',
                    'Faltan los datos fiscales de tu empresa, así que todavía no puedes emitir comprobantes electrónicos. Avisa a soporte para que los configuren.'
                );
            }
            $ambienteEarly = $this->ncfModel()->resolveActiveAmbiente() ?? 'certecf';

            // Notas E33/E34: la referencia se valida ANTES de reservar el e-NCF.
            // Si fallaba recien en el builder, el numero ya estaba dispensado y
            // cada intento quemaba uno del rango. Solo en modo app: en
            // integracion el cliente trae su e_ncf (no se reserva nada) y el
            // builder sigue validando como siempre, con el mismo mensaje.
            $payload['informacion_referencia'] = InformacionReferencia::normalizar(
                $tipoEcf,
                $payload['informacion_referencia'] ?? null,
                !empty($payload['strict_input']),
                (string) ($payload['fecha_emision'] ?? date('d-m-Y'))
            );
        }

        $eNcfOverride = $payload['e_ncf'] ?? null;
        if ($integration && ($eNcfOverride === null || $eNcfOverride === '')) {
            throw new RuntimeException('Integracion: el e_ncf es requerido en el payload (no generamos secuencia).');
        }
        $rangoVencimiento = null; // vencimiento del rango autorizado que dispensa
        if ($eNcfOverride !== null && $eNcfOverride !== '') {
            if (!preg_match('/^E' . $tipoEcf . '\d{10}$/', (string) $eNcfOverride)) {
                throw new EcfUsuarioException(
                    'e_ncf override invalido: debe ser E' . $tipoEcf . ' + 10 digitos. Recibido: ' . $eNcfOverride,
                    'El número de comprobante (e-NCF) indicado no es válido. Revísalo.'
                );
            }
            $eNcf = (string) $eNcfOverride;
        } else {
            // El contador puede quedar DETRAS de lo realmente usado: la fase 2 de
            // certificacion emite con los e-NCF del set, que vienen dados y no
            // consumen secuencia. Si dispensamos un numero ya ocupado, lo mandamos
            // a DGII (duplicado) y recien falla al guardar; peor aun, al revertirse
            // la secuencia el siguiente documento repite el mismo numero y se cicla.
            // Por eso se salta hacia adelante ANTES de emitir.
            $intentos = 0;
            do {
                $disp = $this->ncfModel()->dispenseNextECF('E' . $tipoEcf, $ambienteEarly);
                if ($disp === null) {
                    throw new EcfUsuarioException(
                        'Sin rango e-NCF disponible para E' . $tipoEcf . ' en ambiente ' . $ambienteEarly
                        . ': el rango autorizado esta agotado o vencido. Solicite un nuevo rango a la DGII '
                        . 'y registrelo en la app (POST /api/ncf/rangos).',
                        'Se acabaron o vencieron los números que la DGII te autorizó para '
                        . (self::NOMBRES_TIPO[$tipoEcf] ?? ('e-CF ' . $tipoEcf))
                        . '. Solicita un rango nuevo a la DGII y regístralo en Configuración → Numeraciones e-CF → Gestionar rangos.'
                    );
                }
                $ocupado = $this->eNcfYaUsado((string) $disp['e_ncf'], (string) $ambienteEarly);
                if ($ocupado) {
                    error_log('[ECF] e-NCF ' . $disp['e_ncf'] . ' ya usado localmente; se salta al siguiente.');
                }
            } while ($ocupado && ++$intentos < 100);
            if ($ocupado) {
                // No es un fallo pasajero: reintentar no lo arregla, hay que
                // corregir la numeracion. Por eso lleva su propio texto.
                throw new EcfUsuarioException(
                    'No se encontro un e-NCF libre para E' . $tipoEcf . ' tras 100 intentos: la secuencia'
                    . ' esta muy por detras de las facturas ya emitidas. Sincroniza ncf_sequences.current_value.',
                    'No se pudo asignar un número de comprobante para '
                    . (self::NOMBRES_TIPO[$tipoEcf] ?? ('e-CF ' . $tipoEcf))
                    . '. Avisa a soporte para que revisen la numeración.'
                );
            }
            $eNcf = $disp['e_ncf'];
            $rangoVencimiento = $disp['fecha_vencimiento'] ?? null;
        }
        // Si nosotros dispensamos la secuencia (no es un e_ncf override), se puede
        // revertir cuando DGII rechace sin consumirla (secuenciaUtilizada=false).
        $dispensamosSecuencia = ($eNcfOverride === null || $eNcfOverride === '');
        $secuenciaType = 'E' . $tipoEcf;
        $secuenciaValor = $dispensamosSecuencia ? (int) substr($eNcf, 3) : 0;
        $secuenciaRangoId = $dispensamosSecuencia ? ($disp['rango_id'] ?? null) : null;

        $emisorBase = [
            'rnc' => $emisor['rnc'],
            'razon_social' => $emisor['razon_social'],
            'nombre_comercial' => $emisor['nombre_comercial'] ?? null,
            'sucursal' => $emisor['sucursal'] ?? null,
            'direccion' => $emisor['direccion'],
            'municipio' => $emisor['municipio'] ?? null,
            'provincia' => $emisor['provincia'] ?? null,
            'telefono' => $emisor['telefono'] ?? null,
            'correo' => $emisor['correo'] ?? null,
            'website' => $emisor['website'] ?? null,
            'actividad_economica' => $emisor['actividad_economica'] ?? null,
            // Opcionales del emisor que el builder si emite (CodigoVendedor,
            // NumeroFacturaInterna, ...). Sin ellos aqui nunca llegaban al XML:
            // el set de pruebas de DGII los compara uno a uno contra los valores
            // que entrego y rechaza el set entero si van vacios.
            'codigo_vendedor' => $emisor['codigo_vendedor'] ?? null,
            'numero_factura_interna' => $emisor['numero_factura_interna'] ?? null,
            'numero_pedido_interno' => $emisor['numero_pedido_interno'] ?? null,
            'zona_venta' => $emisor['zona_venta'] ?? null,
            'ruta_venta' => $emisor['ruta_venta'] ?? null,
            'informacion_adicional' => $emisor['informacion_adicional'] ?? null,
        ];
        $strictInput = !empty($payload['strict_input']);
        $emisorOverride = is_array($payload['emisor_override'] ?? null) ? $payload['emisor_override'] : [];
        $emisorMerged = $strictInput
            ? array_merge($emisorBase, $emisorOverride)
            : array_merge($emisorBase, array_filter($emisorOverride, fn($v) => $v !== null && $v !== ''));

        $xmlData = [
            'tipo_ecf' => $tipoEcf,
            'e_ncf' => $eNcf,
            'fecha_emision' => $payload['fecha_emision'] ?? date('d-m-Y'),
            // Prioridad: override del payload > vencimiento del RANGO autorizado
            // que dispenso este e-NCF > emisor_config (legacy) > default.
            'fecha_vencimiento_secuencia' => $payload['fecha_vencimiento_secuencia']
                ?? $rangoVencimiento
                ?? $emisor['fecha_vencimiento_secuencia']
                ?? '31-12-2030',
            'tipo_ingresos' => $payload['tipo_ingresos'] ?? '01',
            'tipo_pago' => array_key_exists('tipo_pago', $payload) ? $payload['tipo_pago'] : 1,
            'fecha_limite_pago' => $payload['fecha_limite_pago'] ?? null,
            'termino_pago' => $payload['termino_pago'] ?? null,
            'tipo_cuenta_pago' => $payload['tipo_cuenta_pago'] ?? null,
            'numero_cuenta_pago' => $payload['numero_cuenta_pago'] ?? null,
            'banco_pago' => $payload['banco_pago'] ?? null,
            'fecha_desde' => $payload['fecha_desde'] ?? null,
            'fecha_hasta' => $payload['fecha_hasta'] ?? null,
            'total_paginas' => $payload['total_paginas'] ?? null,
            'indicador_monto_gravado' => $payload['indicador_monto_gravado'] ?? null,
            'indicador_nota_credito' => $payload['indicador_nota_credito'] ?? null,
            // Modo set de pruebas DGII: el builder no debe rellenar campos que
            // el set entrega vacios (los compara uno a uno y rechaza el set entero).
            'strict_input' => $strictInput,
            'emisor' => $emisorMerged,
            'comprador' => $payload['comprador'] ?? [],
            'items' => $payload['items'] ?? [],
            // Descuentos/recargos globales del documento: solo en el set de
            // pruebas, cuyos totales ya los incluyen. La emision normal calcula
            // los totales de las lineas (EcfItemMapper::totales) y no los
            // restaria: la DGII rechazaria los montos gravados.
            'descuentos_o_recargos' => $strictInput && is_array($payload['descuentos_o_recargos'] ?? null)
                ? $payload['descuentos_o_recargos'] : [],
            'totales' => $payload['totales'] ?? [],
            'informacion_referencia' => $payload['informacion_referencia'] ?? null,
            'fecha_hora_firma' => $payload['fecha_hora_firma'] ?? date('d-m-Y H:i:s'),
        ];

        // <Municipio>/<Provincia> del XML deben ser el código DGII de 6 dígitos
        // (ProvinciaMunicipioType). Los datos guardados suelen ser nombres libres
        // ("Santiago"); se resuelven al código del catálogo. Fail-open: si no hay
        // match se deja el valor tal cual (lo valida el XSD de la DGII).
        // Hasta recibir() nada de este e-CF sale hacia la DGII (autenticar solo
        // firma la semilla). Si armar, firmar o autenticar falla, el e-NCF que
        // dispensamos no se uso: se devuelve para que el proximo intento lo
        // reutilice en vez de dejar un hueco en el rango.
        // Con $persistencia, la ULTIMA sentencia de este try es la reserva
        // ('reservar'): commitea la fila (XML firmado, RFCE, codigo de seguridad
        // e items) antes de cualquier envio. Desde ese commit la fila es duena
        // del numero y el catch ya no lo devuelve.
        $reserva = null;
        $signedRfce = null;
        $ctxReservar = null;
        $montoTotal = (float) ($payload['totales']['monto_total'] ?? 0);
        $usaRFCE = $tipoEcf === '32' && $montoTotal < self::RFCE_THRESHOLD;
        try {
            $this->resolveUbicaciones($xmlData);

            $fechaEmisionDgii = DateTime::createFromFormat('d-m-Y H:i:s', $xmlData['fecha_hora_firma'])->format('Y-m-d H:i:s');

            $unsignedXml = $this->builder->build($xmlData);

            // Cert del tenant resuelto (multi-tenant) o el global del .env (fallback).
            $cert = CertResolver::resolve();
            $certContent = $cert['content'];
            $certPassword = $cert['password'];
            if ($certPassword === '') {
                // Configuracion, no un fallo pasajero: el usuario debe avisar a
                // soporte en vez de reintentar.
                throw new EcfUsuarioException(
                    'Password del certificado no configurado (DGII_ECF_CERT_PASSWORD o cert del tenant).',
                    'El certificado digital de tu empresa no está configurado, así que no se puede firmar el comprobante. Avisa a soporte.'
                );
            }

            $signedXml = $this->signer->sign($certContent, $certPassword, $unsignedXml);
            $codigoSeguridad = $this->extractCodigoSeguridad($signedXml);

            // RFCE (E32 < 250k): se arma y firma aqui, antes de enviar nada. Fuera
            // del try un fallo dejaba el numero consumido sin documento, y la
            // reserva necesita este XML para guardarlo en la fila.
            if ($usaRFCE) {
                $rfceEmisorOverride = is_array($payload['rfce_emisor_override'] ?? null) ? $payload['rfce_emisor_override'] : [];
                $rfceCompradorOverride = is_array($payload['rfce_comprador_override'] ?? null) ? $payload['rfce_comprador_override'] : [];

                $rfceEmisor = array_merge(
                    [
                        'rnc' => $emisorMerged['rnc'],
                        'razon_social' => $emisorMerged['razon_social'],
                    ],
                    array_filter($rfceEmisorOverride, fn($v) => $v !== null && $v !== '')
                );
                $rfceComprador = array_merge(
                    [
                        'rnc' => $payload['comprador']['rnc'] ?? null,
                        'identificador_extranjero' => $payload['comprador']['identificador_extranjero'] ?? null,
                        'razon_social' => $payload['comprador']['razon_social'] ?? null,
                    ],
                    array_filter($rfceCompradorOverride, fn($v) => $v !== null && $v !== '')
                );

                $rfceXmlData = [
                    'tipo_ecf' => '32',
                    'e_ncf' => $eNcf,
                    'tipo_ingresos' => $xmlData['tipo_ingresos'],
                    'tipo_pago' => $xmlData['tipo_pago'],
                    'formas_pago' => $payload['formas_pago'] ?? [],
                    'emisor' => $rfceEmisor,
                    'fecha_emision' => $xmlData['fecha_emision'],
                    'comprador' => $rfceComprador,
                    'totales' => $payload['totales'] ?? [],
                    'codigo_seguridad_ecf' => $codigoSeguridad,
                ];

                $unsignedRfce = $this->rfceBuilder->build($rfceXmlData);
                $signedRfce = $this->signer->sign($certContent, $certPassword, $unsignedRfce);
            }

            // Pre-vuelo del guard de CR: recibir()/recibirResumen() lo repiten,
            // pero ahi ya habria fila reservada. Aqui el numero todavia vuelve a
            // la secuencia.
            DgiiReceptionService::assertSinCarriageReturn($signedXml);
            if ($signedRfce !== null) {
                DgiiReceptionService::assertSinCarriageReturn($signedRfce);
            }

            // El mismo cert firma la semilla de autenticacion DGII.
            $tokenInfo = $this->auth->autenticar([
                'environment' => $payload['ambiente'] ?? null,
                'certificate_content' => $certContent,
                'certificate_password' => $certPassword,
            ]);
            // Pre-vuelo del token. normalizeTokenResponse solo rechaza un token
            // empty(): uno de puros espacios pasaria y normalizeBearerToken lo
            // rechazaria DENTRO de recibir(), con la fila ya reservada (un envio
            // "incierto" que nunca salio). Mismo texto que normalizeBearerToken,
            // para que los integradores lean lo de siempre.
            if (trim((string) ($tokenInfo['token'] ?? '')) === '') {
                throw new RuntimeException('DGII bearer token is required.');
            }
            // Dentro del try: la reserva guarda $ambiente (el del token).
            $bearerToken = $tokenInfo['token'];
            $ambiente = $tokenInfo['ambiente'];

            if ($persistencia !== null) {
                $ctxReservar = [
                    'tipo_ecf' => $tipoEcf,
                    'e_ncf' => $eNcf,
                    'ambiente' => (string) $ambiente,
                    'ambiente_secuencia' => (string) $ambienteEarly,
                    'signed_xml' => $signedXml,
                    'codigo_seguridad' => $codigoSeguridad,
                    'fecha_emision_dgii' => $fechaEmisionDgii,
                    'flujo' => $usaRFCE ? 'RFCE' : 'ECF',
                    'rfce_xml' => $signedRfce,
                    'dispensado' => $dispensamosSecuencia,
                    'rango_id' => $secuenciaRangoId !== null ? (int) $secuenciaRangoId : null,
                    'secuencia_valor' => $dispensamosSecuencia ? $secuenciaValor : null,
                    'desde' => date('Y-m-d H:i:s'),
                ];
                $reserva = $persistencia('reservar', $ctxReservar);
                $idReserva = is_array($reserva) ? (int) ($reserva['factura_id'] ?? 0) : 0;
                $colgada = Database::getInstance()->getConnection()->inTransaction();
                if ($idReserva <= 0 || $colgada) {
                    // Ese retorno no dice quien es duena del numero: el catch cierra la
                    // transaccion que haya quedado abierta y lo decide la base.
                    $reserva = null;
                    throw new RuntimeException(
                        $idReserva <= 0 ? 'persistencia no devolvio factura_id' : 'persistencia dejo una transaccion abierta'
                    );
                }
                self::armarEnVuelo($persistencia, $reserva, $ctxReservar);
                error_log(sprintf('[ECF e-NCF=%s factura_id=%d] reservado, enviando', $eNcf, $idReserva));
            }
        } catch (Throwable $e) {
            // Un gancho que lanzo con su transaccion abierta: sin este rollBack el
            // UPDATE que devuelve el numero correria DENTRO de ella y se perderia
            // al terminar la peticion (numero quemado sin rastro).
            if ($persistencia !== null) {
                try {
                    $conexion = Database::getInstance()->getConnection();
                    if ($conexion->inTransaction()) {
                        $conexion->rollBack();
                    }
                } catch (Throwable $r) {
                    error_log('[ECF e-NCF=' . $eNcf . '] pre-envio: rollBack fallo: ' . $r->getMessage());
                }
            }
            if ($reserva === null && $ctxReservar !== null && $dispensamosSecuencia && !($e instanceof EcfNumeroOcupadoException)) {
                // Se llamo al gancho y el numero esta a punto de volver a la secuencia
                // (lanzo, devolvio basura o dejo una transaccion abierta): quien es
                // duena del numero lo decide la base, no el gancho. Una fila
                // commiteada del gancho (aunque lanzara despues de commitear) lo es.
                $reserva = $this->reservaEnLaBase($eNcf, $ctxReservar['ambiente'], $ctxReservar['desde']);
            }
            if ($reserva !== null) {
                // La fila ya esta commiteada y es duena del numero: no se devuelve.
                error_log(sprintf('[ECF e-NCF=%s factura_id=%d] fila reservada sin enviar', $eNcf, (int) ($reserva['factura_id'] ?? 0)));
                throw $e;
            }
            if ($e instanceof EcfNumeroOcupadoException) {
                // Otra fila ya tiene el numero: devolverlo lo repartiria otra vez.
                error_log(sprintf('[ECF] e-NCF %s ocupado al reservar: la secuencia queda avanzada', $eNcf));
                throw $e;
            }
            if ($dispensamosSecuencia) {
                $devuelto = $this->ncfModel()->rollbackECFSequence(
                    $secuenciaType, $secuenciaValor, $ambienteEarly, $secuenciaRangoId
                );
                error_log(sprintf(
                    '[ECF] fallo antes de enviar a DGII (%s): e-NCF %s %s',
                    get_class($e), $eNcf, $devuelto ? 'devuelto a la secuencia' : 'no se pudo devolver (rollback sin coincidencia)'
                ));
                if (!$devuelto) {
                    // Numero consumido sin fila que lo explique: queda en la bitacora.
                    AuditLogger::log([
                        'module' => 'ncf',
                        'action' => 'NCF_SEQUENCE_UPDATE',
                        'entity_type' => 'ncf_sequence',
                        'entity_id' => $eNcf,
                        'success' => false,
                        'error_message' => 'rollback sin coincidencia',
                        'new_values' => [
                            'e_ncf' => $eNcf,
                            'tipo_ecf' => $tipoEcf,
                            'rango_id' => $secuenciaRangoId,
                            'secuencia_valor' => $secuenciaValor,
                            'ambiente_secuencia' => $ambienteEarly,
                            'causa' => get_class($e),
                        ],
                        'description' => 'e-NCF consumido sin fila: no se pudo devolver a la secuencia.',
                    ]);
                }
            }
            throw $e;
        }

        // Envio. Desde aqui la DGII puede tener el e-CF: ningun fallo devuelve el
        // numero. Con fila reservada, un fallo del envio, del mapeo o de
        // 'confirmar' deja la fila en ENVIO_INCIERTO y sale como
        // EcfEnvioInciertoException (con factura_id, para llevar al usuario a ella).
        $ctxConfirmar = null;
        $ok = true;
        try {
            if ($usaRFCE) {
                $rfceReception = $this->reception->recibirResumen($signedRfce, $bearerToken, [
                    'environment' => $ambiente,
                ]);

                $rfceEstado = $this->mapEstado($rfceReception);
                $rfceTrackId = $this->extractTrackId($rfceReception);

                if ($ctxReservar !== null) {
                    $ctxConfirmar = $ctxReservar + [
                        'reserva' => $reserva,
                        'estado' => 'RFCE_' . $rfceEstado,
                        'track_id' => null,
                        'dgii_response' => null,
                        'dgii_status_code' => null,
                        'rfce_track_id' => $rfceTrackId,
                        'rfce_estado' => $rfceEstado,
                        'rfce_response' => $rfceReception['data'],
                        'rfce_status_code' => $rfceReception['status_code'],
                        'secuencia_utilizada' => self::parseSecuenciaUtilizada(
                            is_array($rfceReception['data'] ?? null) ? $rfceReception['data'] : []
                        ),
                    ];
                    // La fila queda final ANTES de tocar el contador (fila primero,
                    // contador despues).
                    $ok = (bool) $persistencia('confirmar', $ctxConfirmar);
                }
            } else {
                $reception = $this->reception->recibir($signedXml, $bearerToken, [
                    'environment' => $ambiente,
                ]);

                $estado = $this->mapEstado($reception);
                $trackId = $this->extractTrackId($reception);

                if ($ctxReservar !== null) {
                    $ctxConfirmar = $ctxReservar + [
                        'reserva' => $reserva,
                        'estado' => $estado,
                        'track_id' => $trackId,
                        'dgii_response' => $reception['data'],
                        'dgii_status_code' => $reception['status_code'],
                        'rfce_track_id' => null,
                        'rfce_estado' => null,
                        'rfce_response' => null,
                        'rfce_status_code' => null,
                        'secuencia_utilizada' => self::parseSecuenciaUtilizada(
                            is_array($reception['data'] ?? null) ? $reception['data'] : []
                        ),
                    ];
                    $ok = (bool) $persistencia('confirmar', $ctxConfirmar);
                }
            }
        } catch (Throwable $e) {
            if ($persistencia !== null && $reserva !== null) {
                $fase = $ctxConfirmar !== null ? 'confirmar' : 'envio';
                $parcial = $ctxConfirmar !== null ? self::proyectarResultado($ctxConfirmar) : null;
                $marcado = false;
                try {
                    $marcado = (bool) $persistencia('incierto', self::ctxSinXml($ctxReservar) + [
                        'reserva' => $reserva,
                        'fase_fallida' => $fase,
                        'clase' => get_class($e),
                        'error' => mb_substr($e->getMessage(), 0, 2000),
                        'resultado_parcial' => $parcial,
                    ]);
                } catch (Throwable $h) {
                    error_log('[ECF e-NCF=' . $eNcf . ' factura_id=' . $reserva['factura_id'] . '] marcar incierto fallo: ' . $h->getMessage());
                }
                // Sale del registro DESPUES de 'incierto', no antes: si un fatal o un
                // timeout mata el proceso dentro de esa llamada, el cierre reintenta
                // (el UPDATE solo toca PENDIENTE_ENVIO: si ya marco, no hace nada).
                self::desarmarEnVuelo((int) $reserva['factura_id']);
                // Si la DGII llego a contestar, estado y track_id quedan aqui aunque
                // la base este caida (soporte los lee en el log, 10.2).
                error_log(sprintf(
                    '[ECF e-NCF=%s factura_id=%d] ENVIO_INCIERTO fase=%s marcado=%s estado=%s track_id=%s %s: %s',
                    $eNcf, $reserva['factura_id'], $fase, $marcado ? 'si' : 'no',
                    $parcial['estado'] ?? '-', $parcial['track_id'] ?? '-', get_class($e), $e->getMessage()
                ));
                throw new EcfEnvioInciertoException($eNcf, (int) $reserva['factura_id'], (string) $ambiente, $fase, $parcial, $marcado, $e);
            }
            // Integracion y gastos (sin gancho): la excepcion original, como siempre.
            throw $e;
        }
        if ($reserva !== null) {
            // 'confirmar' ya dejo la fila final (o CONFIRM_RACE): nada que marcar.
            self::desarmarEnVuelo((int) $reserva['factura_id']);
        }

        if (!$ok) {
            // Otra escritura (soporte, otra peticion) ya movio la fila: su estado
            // manda y la secuencia no se toca desde aqui.
            error_log(sprintf(
                '[ECF e-NCF=%s factura_id=%d] CONFIRM_RACE: otra escritura movio la fila; no se toca la secuencia',
                $eNcf, $reserva['factura_id']
            ));
        }

        if ($usaRFCE) {
            if ($ok) {
                $this->reclamarSecuenciaSiNoUtilizada(
                    $dispensamosSecuencia, $secuenciaType, $secuenciaValor, $ambienteEarly,
                    is_array($rfceReception['data'] ?? null) ? $rfceReception['data'] : [],
                    $rfceEstado,
                    $secuenciaRangoId
                );
            }

            return [
                'e_ncf' => $eNcf,
                'tipo_ecf' => $tipoEcf,
                'signed_xml' => $signedXml,
                'codigo_seguridad' => $codigoSeguridad,
                'track_id' => null,
                'estado' => 'RFCE_' . $rfceEstado,
                'ambiente' => $ambiente,
                'fecha_emision_dgii' => $fechaEmisionDgii,
                'dgii_response' => null,
                'dgii_status_code' => null,
                'flujo' => 'RFCE',
                'monto_total' => $montoTotal,
                'rfce_xml' => $signedRfce,
                'rfce_track_id' => $rfceTrackId,
                'rfce_estado' => $rfceEstado,
                'rfce_response' => $rfceReception['data'],
                'rfce_status_code' => $rfceReception['status_code'],
                // En produccion basta el RFCE: el XML integro queda firmado en
                // facturas.xml_firmado. La "carga manual al portal" era un paso de
                // la certificacion, no de la operacion (confirmado 2026-10-08).
                'aviso' => 'E32 con monto < 250,000: se envio el RFCE (resumen) a la DGII. El XML integro queda guardado y firmado en la factura.',
                'factura_id' => $reserva['factura_id'] ?? null,
            ];
        }

        if ($ok) {
            $this->reclamarSecuenciaSiNoUtilizada(
                $dispensamosSecuencia, $secuenciaType, $secuenciaValor, $ambienteEarly,
                is_array($reception['data'] ?? null) ? $reception['data'] : [],
                $estado,
                $secuenciaRangoId
            );
        }

        return [
            'e_ncf' => $eNcf,
            'tipo_ecf' => $tipoEcf,
            'signed_xml' => $signedXml,
            'codigo_seguridad' => $codigoSeguridad,
            'track_id' => $trackId,
            'estado' => $estado,
            'ambiente' => $ambiente,
            'fecha_emision_dgii' => $fechaEmisionDgii,
            'dgii_response' => $reception['data'],
            'dgii_status_code' => $reception['status_code'],
            'flujo' => 'ECF',
            'factura_id' => $reserva['factura_id'] ?? null,
        ];
    }

    /**
     * Revierte el contador de secuencia cuando DGII NO consume el e-NCF, para
     * reutilizarlo en el proximo intento. Solo aplica a secuencias que
     * dispensamos nosotros; un e_ncf override no toca el contador.
     *
     * Regla (DGII): un e-CF RECHAZADO no consume la secuencia autorizada salvo
     * que DGII lo indique con secuenciaUtilizada=true (p.ej. e-NCF duplicado).
     * La bandera no siempre viene y su forma varia entre la recepcion integra y
     * RecepcionFC (RFCE E32 < 250k), por eso se decide por bandera + estado:
     *   - secuenciaUtilizada === false            -> revertir
     *   - secuenciaUtilizada ausente + RECHAZADO  -> revertir (no se consumio)
     *   - secuenciaUtilizada === true             -> NO revertir (DGII la consumio)
     */
    private function reclamarSecuenciaSiNoUtilizada(
        bool $dispensamos,
        string $type,
        int $valor,
        string $ambiente,
        array $receptionData,
        string $estado,
        ?int $rangoId = null
    ): void {
        if (!$dispensamos) {
            return;
        }
        $utilizada = self::parseSecuenciaUtilizada($receptionData);
        $esRechazo = in_array($estado, ['RECHAZADO', 'NO_ENCONTRADO'], true);
        $debeRevertir = ($utilizada === false) || ($utilizada === null && $esRechazo);

        $flagTxt = $utilizada === null ? 'ausente' : ($utilizada ? 'true' : 'false');
        $resultado = 'no_revierte';
        if ($debeRevertir) {
            $resultado = $this->ncfModel()->rollbackECFSequence($type, $valor, $ambiente, $rangoId)
                ? 'revertido'
                : 'rollback_sin_coincidencia';
        }
        // Siempre se registra: deja claro en error_log que decision se tomo y por
        // que (dispensamos/estado/bandera) ante cualquier duda sobre la secuencia.
        error_log(sprintf(
            '[ECF] reclamar secuencia: type=%s valor=%d ambiente=%s estado=%s dispensamos=si utilizada=%s rango_id=%s -> %s',
            $type, $valor, $ambiente, $estado, $flagTxt, $rangoId !== null ? (string) $rangoId : 'null', $resultado
        ));
    }

    /**
     * Bandera secuenciaUtilizada de una respuesta de recepcion: true/false, o
     * null si no vino o no se entiende. Una sola lectura para el ctx de
     * 'confirmar' y para reclamarSecuenciaSiNoUtilizada.
     */
    private static function parseSecuenciaUtilizada(array $data): ?bool
    {
        $utilizada = null;
        if (array_key_exists('secuenciaUtilizada', $data)) {
            $utilizada = filter_var(
                $data['secuenciaUtilizada'],
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            );
        }
        return $utilizada;
    }

    /**
     * Copia del ctx sin los XML firmados (MEDIUMTEXT). Es lo unico que llega a
     * respuesta_dgii (TEXT), a la bitacora y al registro de cierre.
     */
    public static function ctxSinXml(array $ctx): array
    {
        unset($ctx['signed_xml'], $ctx['rfce_xml']);
        return $ctx;
    }

    /**
     * Lo que se sabe de la respuesta de la DGII, sin XML y con tope: siempre las
     * 8 claves de CLAVES_PROYECCION (null si faltan). Un dgii_response de texto
     * (cuerpo crudo de un 2xx sin JSON) se corta a MAX_RESPUESTA caracteres para
     * que nunca desborde respuesta_dgii TEXT. mb_substr: mbstring ya es
     * obligatorio en la emision (ECFXmlBuilder lo usa en cada e-CF).
     */
    public static function proyectarResultado(array $ctx): array
    {
        $r = [];
        foreach (self::CLAVES_PROYECCION as $clave) {
            $r[$clave] = $ctx[$clave] ?? null;
        }
        if ($r['dgii_response'] !== null && !is_array($r['dgii_response'])) {
            $r['dgii_response'] = mb_substr((string) $r['dgii_response'], 0, self::MAX_RESPUESTA);
        }
        return $r;
    }

    /**
     * Anota una fila reservada como en vuelo y, una vez por proceso, registra la
     * funcion de cierre que la marca ENVIO_INCIERTO si el proceso muere antes de
     * 'confirmar'/'incierto' (spec 4.4 paso 9).
     *
     * Orden: index.php registra ErrorHandler (ErrorHandler.php:21-30) antes de
     * cualquier emision, y PHP llama las funciones de cierre en orden de
     * registro; esta corre DESPUES de ErrorHandler::onShutdown, que solo puede
     * registrar un error_id anonimo. Si a esa altura PDO ya no sirve, la fila
     * queda en PENDIENTE_ENVIO (resolucion manual, 10.2).
     */
    private static function armarEnVuelo(callable $persistencia, array $reserva, array $ctxReservar): void
    {
        self::$enVuelo[(int) $reserva['factura_id']] = [$persistencia, $reserva, self::ctxSinXml($ctxReservar)];
        if (self::$shutdownRegistrado) {
            return;
        }
        self::$shutdownRegistrado = true;
        register_shutdown_function(static function (): void {
            // Lo PRIMERO: tras un fatal por memoria PHP restaura el limite, pero el
            // heap sigue casi lleno si el proceso crecio de a poco, y el UPDATE de
            // 'incierto' (prepare/execute de PDO) volveria a agotarlo: un segundo
            // fatal dentro del cierre, que ningun catch atrapa, con la fila en
            // PENDIENTE_ENVIO. Con holgura el cierre cabe. @ por si el host
            // prohibe ini_set: sin permiso no se avisa, solo se intenta.
            $holgura = self::limiteConHolgura((string) ini_get('memory_limit'));
            if ($holgura !== null) {
                @ini_set('memory_limit', $holgura);
            }
            $err = error_get_last();
            foreach (self::$enVuelo as [$p, $r, $c]) {
                try {
                    $p('incierto', $c + [
                        'reserva' => $r,
                        'fase_fallida' => 'shutdown',
                        'clase' => 'shutdown',
                        'error' => (string) ($err['message'] ?? 'shutdown sin respuesta'),
                    ]);
                } catch (Throwable) {
                    // Cierre del proceso: no hay a quien avisar. La fila queda en
                    // PENDIENTE_ENVIO y se resuelve a mano (10.2).
                }
            }
            self::$enVuelo = [];
        });
    }

    /** La fila ya tiene destino ('confirmar' o 'incierto'): fuera del registro. */
    private static function desarmarEnVuelo(int $facturaId): void
    {
        unset(self::$enVuelo[$facturaId]);
    }

    /**
     * memory_limit para la funcion de cierre: el actual (con sufijo K/M/G) mas
     * 64M. null si no hay que tocarlo: sin tope (-1), 0 o un valor que no se
     * entiende.
     */
    private static function limiteConHolgura(string $limite): ?string
    {
        if (!preg_match('/^\s*(\d+)\s*([KMG]?)\s*$/i', $limite, $m)) {
            return null;
        }
        $bytes = (int) $m[1] * match (strtoupper($m[2])) {
            'G' => 1073741824,
            'M' => 1048576,
            'K' => 1024,
            default => 1,
        };
        return $bytes > 0 ? (string) ($bytes + 64 * 1048576) : null;
    }

    /**
     * $rncEmisor permite consultar sin `emisor_config`: los tenants tipo
     * integracion no tienen DB propia, asi que el RNC viene del tenant resuelto.
     * Sin ese parametro el comportamiento es el de siempre (lee emisor_config).
     */
    public function consultarEstado(string $trackId, string $eNcf, ?string $ambiente = null, ?string $rncEmisor = null): array
    {
        $rncEmisor = $rncEmisor !== null && $rncEmisor !== '' ? $rncEmisor : null;
        if ($rncEmisor === null) {
            $emisor = $this->emisorModel()->get();
            if (!$emisor) {
                throw new EcfUsuarioException(
                    'emisor_config no configurado.',
                    'Faltan los datos fiscales de tu empresa, así que no se puede consultar el estado en la DGII. Avisa a soporte.'
                );
            }
            $rncEmisor = $emisor['rnc'];
        }
        $cert = CertResolver::resolve();
        $tokenInfo = $this->auth->autenticar([
            'environment' => $ambiente,
            'certificate_content' => $cert['content'],
            'certificate_password' => $cert['password'],
        ]);
        return $this->reception->consultarEstado(
            $trackId,
            $rncEmisor,
            $eNcf,
            $tokenInfo['token'],
            ['environment' => $tokenInfo['ambiente']]
        );
    }

    /**
     * Consulta el estado fiscal de un RFCE (E32 < 250,000) por RNC Emisor +
     * e-NCF + codigo de seguridad. Usa el servicio RecepcionFC (fc.dgii.gov.do)
     * en lugar de ConsultaResultado, porque los RFCE no generan trackId.
     *
     * $rncEmisor: igual que en consultarEstado(), para tenants sin emisor_config.
     */
    public function consultarEstadoRFCE(string $eNcf, string $codigoSeguridad, ?string $ambiente = null, ?string $rncEmisor = null): array
    {
        $rncEmisor = $rncEmisor !== null && $rncEmisor !== '' ? $rncEmisor : null;
        if ($rncEmisor === null) {
            $emisor = $this->emisorModel()->get();
            if (!$emisor) {
                throw new EcfUsuarioException(
                    'emisor_config no configurado.',
                    'Faltan los datos fiscales de tu empresa, así que no se puede consultar el estado en la DGII. Avisa a soporte.'
                );
            }
            $rncEmisor = $emisor['rnc'];
        }
        $cert = CertResolver::resolve();
        $tokenInfo = $this->auth->autenticar([
            'environment' => $ambiente,
            'certificate_content' => $cert['content'],
            'certificate_password' => $cert['password'],
        ]);
        return $this->reception->consultarResumenRFCE(
            $rncEmisor,
            $eNcf,
            $codigoSeguridad,
            $tokenInfo['token'],
            ['environment' => $tokenInfo['ambiente']]
        );
    }

    private function resolveCertPath(): string
    {
        $configured = (string) (getenv('DGII_ECF_CERT_PATH') ?: '');
        if ($configured === '') {
            throw new RuntimeException('DGII_ECF_CERT_PATH no configurado.');
        }
        if (preg_match('/^[A-Za-z]:[\\\\\/]/', $configured) || str_starts_with($configured, '/')) {
            return $configured;
        }
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR
            . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $configured);
    }

    private function extractCodigoSeguridad(string $signedXml): string
    {
        return self::codigoSeguridadDeXml($signedXml) ?? substr(sha1($signedXml), 0, 6);
    }

    /**
     * CodigoSeguridad de un e-CF firmado: los 6 primeros caracteres de
     * SignatureValue, sin espacios (nunca quitar + / =). null si no esta firmado.
     * Publico para quien tenga solo el XML (GET /integracion/xml).
     */
    public static function codigoSeguridadDeXml(string $signedXml): ?string
    {
        if (preg_match('/<SignatureValue>([^<]+)<\/SignatureValue>/i', $signedXml, $m)) {
            return substr(preg_replace('/\s+/', '', $m[1]), 0, 6);
        }
        return null;
    }

    private function extractTrackId(array $reception): ?string
    {
        $data = $reception['data'];
        if (!is_array($data)) {
            return null;
        }
        foreach (['trackId', 'TrackId', 'trackid'] as $key) {
            if (isset($data[$key])) {
                return (string) $data[$key];
            }
        }
        return null;
    }

    private function mapEstado(array $reception): string
    {
        $code = $reception['status_code'];
        $data = is_array($reception['data']) ? $reception['data'] : [];

        // El veredicto se mapea por el CONTENIDO, no por el HTTP: DGII rechaza
        // algunos e-CF con 4xx + cuerpo estructurado ({"codigo":2,"estado":
        // "Rechazado",...}). Es un rechazo de negocio (debe persistirse), no un
        // fallo de transporte. Primero el texto `estado`, luego el `codigo`.
        $estadoTexto = is_string($data['estado'] ?? null) ? strtolower(trim($data['estado'])) : '';
        if ($estadoTexto !== '') {
            if (str_contains($estadoTexto, 'rechaz')) return 'RECHAZADO';
            if (str_contains($estadoTexto, 'condicion')) return 'ACEPTADO_CONDICIONAL';
            if (str_contains($estadoTexto, 'acept')) return 'ACEPTADO';
            if (str_contains($estadoTexto, 'proceso')) return 'EN_PROCESO';
            if (str_contains($estadoTexto, 'no encontrado')) return 'NO_ENCONTRADO';
        }

        $estadoCodigo = $data['codigo'] ?? null;
        if (is_numeric($estadoCodigo)) {
            // Codigos DGII: 0=No encontrado, 1=Aceptado, 2=Rechazado,
            // 3=En Proceso, 4=Aceptado Condicional.
            switch ((int) $estadoCodigo) {
                case 0: return 'NO_ENCONTRADO';
                case 1: return 'ACEPTADO';
                case 2: return 'RECHAZADO';
                case 3: return 'EN_PROCESO';
                case 4: return 'ACEPTADO_CONDICIONAL';
            }
        }

        // 2xx sin veredicto explicito: recepcion aceptada, se consulta despues.
        if ($code >= 200 && $code < 300) {
            return 'ENVIADO';
        }
        return 'ERROR';
    }
}
