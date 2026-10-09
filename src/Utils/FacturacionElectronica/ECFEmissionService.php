<?php

require_once __DIR__ . '/DgiiAuthService.php';
require_once __DIR__ . '/DgiiXmlSigner.php';
require_once __DIR__ . '/DgiiReceptionService.php';
require_once __DIR__ . '/ECFXmlBuilder.php';
require_once __DIR__ . '/RFCEXmlBuilder.php';
require_once __DIR__ . '/EcfItemMapper.php';
require_once __DIR__ . '/EcfUsuarioException.php';
require_once __DIR__ . '/InformacionReferencia.php';
require_once __DIR__ . '/../../CertResolver.php';
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

    public function __construct()
    {
        $this->auth = new DgiiAuthService();
        $this->signer = new DgiiXmlSigner();
        $this->reception = new DgiiReceptionService($this->auth);
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
     * Resuelve municipio/provincia de emisor y comprador al código DGII de 6
     * dígitos usando el catálogo. Fail-open: valores sin match quedan intactos.
     */
    /**
     * true si el e-NCF ya lo ocupa, en ESTE ambiente, una factura que NO se
     * puede re-emitir. Certificacion y produccion numeran aparte (migracion
     * 027): contar los de prueba hacia saltar el rango que la DGII autoriza en
     * produccion. Una fila sin ambiente (anterior a la columna) cuenta como
     * ocupada: no se sabe de cual era.
     * Mismos estados reintentables que facturaModel::saveFacturaConECF, para que
     * los dos chequeos no puedan discrepar. Fail-open: ante un error de consulta
     * devuelve false y deja que el chequeo del modelo siga siendo la red.
     */
    private function eNcfYaUsado(string $eNcf, string $ambiente): bool
    {
        try {
            $stmt = Database::getInstance()->getConnection()->prepare(
                'SELECT estado_dgii FROM facturas
                 WHERE e_ncf = :e AND (ambiente_dgii = :amb OR ambiente_dgii IS NULL)'
            );
            $stmt->execute([':e' => $eNcf, ':amb' => $ambiente]);
            $reintentables = ['RECHAZADO', 'RFCE_RECHAZADO', 'NO_ENCONTRADO'];
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $estado) {
                if (!in_array((string) $estado, $reintentables, true)) {
                    return true;
                }
            }
            return false;
        } catch (Throwable $e) {
            error_log('[ECF] eNcfYaUsado fallo: ' . $e->getMessage());
            return false;
        }
    }

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
     * @return array {
     *   e_ncf, tipo_ecf, signed_xml, codigo_seguridad,
     *   track_id, estado, ambiente, fecha_emision_dgii, dgii_response
     * }
     */
    public function emitir(array $payload): array
    {
        $tipoEcf = (string) ($payload['tipo_ecf'] ?? '');
        if (!preg_match('/^(31|32|33|34|41|43|44|45|46|47)$/', $tipoEcf)) {
            throw new RuntimeException('tipo_ecf invalido: ' . $tipoEcf);
        }

        // Modo integracion: sin DB propia. El emisor viene en el payload y el
        // cliente envia el e_ncf (no dispensamos secuencia ni leemos emisor_config).
        $integration = !empty($payload['integration']);
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
            // TablaFormasPago: la misma que lleva el RFCE, para que el e-CF
            // firmado y su resumen digan lo mismo (el POS la manda; app.* aun
            // no). En el set de pruebas (strict) se queda como estaba: sin ella.
            'formas_pago' => !$strictInput && is_array($payload['formas_pago'] ?? null)
                ? $payload['formas_pago'] : [],
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

            // El mismo cert firma la semilla de autenticacion DGII.
            $tokenInfo = $this->auth->autenticar([
                'environment' => $payload['ambiente'] ?? null,
                'certificate_content' => $certContent,
                'certificate_password' => $certPassword,
            ]);
        } catch (Throwable $e) {
            if ($dispensamosSecuencia) {
                $devuelto = $this->ncfModel()->rollbackECFSequence(
                    $secuenciaType, $secuenciaValor, $ambienteEarly, $secuenciaRangoId
                );
                error_log(sprintf(
                    '[ECF] fallo antes de enviar a DGII (%s): e-NCF %s %s',
                    get_class($e), $eNcf, $devuelto ? 'devuelto a la secuencia' : 'no se pudo devolver (rollback sin coincidencia)'
                ));
            }
            throw $e;
        }
        $bearerToken = $tokenInfo['token'];
        $ambiente = $tokenInfo['ambiente'];

        // Opciones del POS (docs/specs/pos.md F6). Sin ellas, todo como siempre.
        //  - dgii_timeout: segundos de espera al ENVIAR (el resto del sistema usa
        //    DGII_ECF_TIMEOUT, 30 s). El token ya se pidio con el timeout normal.
        //  - tolerar_fallo_envio: si el envio del RFCE no llega a tener respuesta
        //    (timeout o caida), el resultado sale como RFCE_PENDIENTE con los XML
        //    firmados, en vez de una excepcion. El e-NCF NO se devuelve: no se
        //    sabe si la DGII lo recibio, y la venta se queda con el. Se reenvia
        //    despues consultando primero (reenviarRFCE / consultarEstadoRFCE).
        $opcionesEnvio = ['environment' => $ambiente];
        if ((int) ($payload['dgii_timeout'] ?? 0) > 0) {
            $opcionesEnvio['timeout'] = (int) $payload['dgii_timeout'];
        }
        $tolerarFalloEnvio = !empty($payload['tolerar_fallo_envio']);

        $montoTotal = (float) ($payload['totales']['monto_total'] ?? 0);
        $usaRFCE = $tipoEcf === '32' && $montoTotal < self::RFCE_THRESHOLD;

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

            try {
                $rfceReception = $this->reception->recibirResumen($signedRfce, $bearerToken, $opcionesEnvio);
            } catch (Throwable $e) {
                if (!$tolerarFalloEnvio) {
                    throw $e;
                }
                error_log(sprintf('[ECF] RFCE %s sin respuesta de la DGII (%s): queda pendiente de envio. %s',
                    $eNcf, get_class($e), $e->getMessage()));
                return [
                    'e_ncf' => $eNcf,
                    'tipo_ecf' => $tipoEcf,
                    'signed_xml' => $signedXml,
                    'codigo_seguridad' => $codigoSeguridad,
                    'track_id' => null,
                    'estado' => 'RFCE_PENDIENTE',
                    'ambiente' => $ambiente,
                    'fecha_emision_dgii' => $fechaEmisionDgii,
                    'dgii_response' => null,
                    'dgii_status_code' => null,
                    'flujo' => 'RFCE',
                    'monto_total' => $montoTotal,
                    'rfce_xml' => $signedRfce,
                    'rfce_track_id' => null,
                    'rfce_estado' => 'PENDIENTE',
                    'rfce_response' => null,
                    'rfce_status_code' => null,
                    'envio_pendiente' => true,
                    'error_envio' => get_class($e) . ': ' . $e->getMessage(),
                ];
            }

            $rfceEstado = $this->mapEstado($rfceReception);
            $rfceTrackId = $this->extractTrackId($rfceReception);

            $this->reclamarSecuenciaSiNoUtilizada(
                $dispensamosSecuencia, $secuenciaType, $secuenciaValor, $ambienteEarly,
                is_array($rfceReception['data'] ?? null) ? $rfceReception['data'] : [],
                $rfceEstado,
                $secuenciaRangoId
            );

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
            ];
        }

        try {
            $reception = $this->reception->recibir($signedXml, $bearerToken, $opcionesEnvio);
        } catch (Throwable $e) {
            if (!$tolerarFalloEnvio) {
                throw $e;
            }
            // e-CF completo sin respuesta: igual que el RFCE, queda firmado y
            // pendiente. Sin trackId: al reenviar se pregunta primero a
            // ConsultaTrackIds si la DGII lo recibio (consultarTrackIds).
            error_log(sprintf('[ECF] e-CF %s sin respuesta de la DGII (%s): queda pendiente de envio. %s',
                $eNcf, get_class($e), $e->getMessage()));
            return [
                'e_ncf' => $eNcf,
                'tipo_ecf' => $tipoEcf,
                'signed_xml' => $signedXml,
                'codigo_seguridad' => $codigoSeguridad,
                'track_id' => null,
                'estado' => 'ENVIO_PENDIENTE',
                'ambiente' => $ambiente,
                'fecha_emision_dgii' => $fechaEmisionDgii,
                'dgii_response' => null,
                'dgii_status_code' => null,
                'flujo' => 'ECF',
                'envio_pendiente' => true,
                'error_envio' => get_class($e) . ': ' . $e->getMessage(),
            ];
        }

        $estado = $this->mapEstado($reception);
        $trackId = $this->extractTrackId($reception);

        $this->reclamarSecuenciaSiNoUtilizada(
            $dispensamosSecuencia, $secuenciaType, $secuenciaValor, $ambienteEarly,
            is_array($reception['data'] ?? null) ? $reception['data'] : [],
            $estado,
            $secuenciaRangoId
        );

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
        $utilizada = null;
        if (array_key_exists('secuenciaUtilizada', $receptionData)) {
            $utilizada = filter_var(
                $receptionData['secuenciaUtilizada'],
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            );
        }
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
     * Reenvia a la DGII un RFCE ya firmado que quedo pendiente (POS, F7). El que
     * llama tiene que haber consultado antes (consultarEstadoRFCE) y no haberlo
     * encontrado: si la DGII ya lo tenia, reenviarlo lo duplicaria.
     *
     * @return array{estado: string, response: mixed, status_code: int|null}
     *   estado: RFCE_ACEPTADO, RFCE_RECHAZADO, RFCE_EN_PROCESO... (mapEstado con prefijo)
     * Lanza excepcion si tampoco esta vez hay respuesta (sigue pendiente).
     */
    public function reenviarRFCE(string $rfceXml, ?string $ambiente = null, ?int $timeout = null): array
    {
        $cert = CertResolver::resolve();
        $tokenInfo = $this->auth->autenticar([
            'environment' => $ambiente,
            'certificate_content' => $cert['content'],
            'certificate_password' => $cert['password'],
        ]);
        $opciones = ['environment' => $tokenInfo['ambiente']];
        if ($timeout !== null && $timeout > 0) {
            $opciones['timeout'] = $timeout;
        }
        $recepcion = $this->reception->recibirResumen($rfceXml, $tokenInfo['token'], $opciones);
        return [
            'estado' => 'RFCE_' . $this->mapEstado($recepcion),
            'response' => $recepcion['data'],
            'status_code' => $recepcion['status_code'],
        ];
    }

    /**
     * trackIds que la DGII tiene para un e-NCF (ConsultaTrackIds, POS F7): para
     * saber si un e-CF completo que quedo sin respuesta llego o no. Existe en
     * testecf y ecf (no en certecf).
     *
     * @return array<int,array{trackId:string,estado:?string}>|null
     *   lista (vacia = la DGII no lo tiene), o null si la respuesta no permite
     *   saberlo (no reenviar a ciegas).
     */
    public function consultarTrackIds(string $eNcf, ?string $ambiente = null): ?array
    {
        $emisor = $this->emisorModel()->get();
        if (!$emisor) {
            throw new EcfUsuarioException('emisor_config no configurado.', 'Faltan los datos fiscales de tu empresa. Avisa a soporte.');
        }
        $cert = CertResolver::resolve();
        $tokenInfo = $this->auth->autenticar([
            'environment' => $ambiente,
            'certificate_content' => $cert['content'],
            'certificate_password' => $cert['password'],
        ]);
        $ruta = 'consultatrackids/api/TrackIds/Consulta?' . http_build_query(['RncEmisor' => $emisor['rnc'], 'Encf' => $eNcf]);
        // tolerate: el "no existe" es un 404 con cuerpo ProblemDetails; un 404
        // sin JSON (caida) sigue siendo excepcion.
        $r = $this->auth->consultarEndpointAutenticado('GET', $ruta, $tokenInfo['token'], null,
            ['environment' => $tokenInfo['ambiente'], 'tolerate_http_errors' => true]);
        $data = $r['data'];
        if ($r['status_code'] === 404) {
            return [];
        }
        if (!is_array($data)) {
            return null;
        }
        $lista = array_is_list($data) ? $data : [$data];
        $out = [];
        foreach ($lista as $item) {
            if (is_array($item) && trim((string) ($item['trackId'] ?? '')) !== '') {
                $out[] = ['trackId' => trim((string) $item['trackId']), 'estado' => isset($item['estado']) ? (string) $item['estado'] : null];
            }
        }
        if ($out === [] && $r['status_code'] >= 300) {
            return null;
        }
        return $out;
    }

    /**
     * Reenvia un e-CF completo ya firmado que quedo sin respuesta (POS, F7). El
     * que llama tiene que haber confirmado con consultarTrackIds que la DGII no
     * lo tiene. Lanza excepcion si tampoco esta vez hay respuesta.
     *
     * @return array{estado: string, track_id: ?string, response: mixed}
     */
    public function reenviarECF(string $signedXml, ?string $ambiente = null, ?int $timeout = null): array
    {
        $cert = CertResolver::resolve();
        $tokenInfo = $this->auth->autenticar([
            'environment' => $ambiente,
            'certificate_content' => $cert['content'],
            'certificate_password' => $cert['password'],
        ]);
        $opciones = ['environment' => $tokenInfo['ambiente']];
        if ($timeout !== null && $timeout > 0) {
            $opciones['timeout'] = $timeout;
        }
        $recepcion = $this->reception->recibir($signedXml, $tokenInfo['token'], $opciones);
        return [
            'estado' => $this->mapEstado($recepcion),
            'track_id' => $this->extractTrackId($recepcion),
            'response' => $recepcion['data'],
        ];
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
