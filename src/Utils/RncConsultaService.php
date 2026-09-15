<?php
/**
 * Consulta de RNC / cedula en un servicio externo, para autocompletar el alta de
 * clientes y proveedores (GET /api/rnc/consulta).
 *
 * Hoy el proveedor es rnc.megaplus.com.do: un servicio de TERCEROS, sin SLA. Por
 * eso todo pasa por aqui: cambiar de proveedor (o pasar al listado de
 * contribuyentes de la DGII en una tabla local) es tocar este archivo, no el
 * front. Y por eso nada depende de que responda: el front cae a llenado manual.
 *
 * Contrato probado el 2026-09-15:
 *   200 -> {"error":false, "cedula_rnc":"131-25643-2", "nombre_razon_social":"...",
 *           "nombre_comercial":"...", "estado":"ACTIVO", "facturador_electronico":"SI", ...}
 *   404 -> {"error":true, "mensaje":"el rnc/cedula consultado no se encuentra inscrito como contribuyente."}
 * OJO: solo acepta DIGITOS. "131-25643-2" responde 404 aunque el RNC exista, asi
 * que se limpia antes de consultar.
 */
class RncConsultaService
{
    private const URL = 'https://rnc.megaplus.com.do/api/consulta';

    // Cortos a proposito: la consulta es para autocompletar un formulario. Si
    // tarda mas, al usuario le sale mas rapido escribir los datos a mano.
    private const TIMEOUT_SECONDS = 5;
    private const CONNECT_TIMEOUT_SECONDS = 3;

    private const NO_DISPONIBLE = 'La consulta de RNC no está disponible ahora. Completa los datos manualmente.';

    /**
     * @return array{status:int, data:?array, error:?string}
     *   200 encontrado · 404 no inscrito · 422 formato invalido · 502 servicio no disponible
     */
    public function consultar(string $entrada): array
    {
        $rnc = (string) preg_replace('/\D/', '', $entrada);
        if (strlen($rnc) !== 9 && strlen($rnc) !== 11) {
            return self::fallo(422, 'El RNC debe tener 9 dígitos y la cédula 11.');
        }
        if (!function_exists('curl_init')) {
            error_log('[rnc] curl no disponible');
            return self::fallo(502, self::NO_DISPONIBLE);
        }

        $ch = curl_init(self::URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['rnc' => $rnc]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        if (PHP_VERSION_ID < 80500) {
            curl_close($ch);
        }

        if ($raw === false) {
            error_log('[rnc] consulta fallida: ' . $curlError);
            return self::fallo(502, self::NO_DISPONIBLE);
        }
        $body = json_decode((string) $raw, true);
        if (!is_array($body)) {
            // Una pagina HTML (incluido un 404 del servidor) es el servicio roto,
            // no un "no inscrito": se trata como no disponible.
            error_log('[rnc] respuesta no JSON (HTTP ' . $code . ')');
            return self::fallo(502, self::NO_DISPONIBLE);
        }
        if ($code === 404) {
            return self::fallo(404, 'Este RNC o cédula no está inscrito como contribuyente en la DGII.');
        }
        if ($code !== 200 || !empty($body['error']) || self::texto($body['nombre_razon_social'] ?? '') === '') {
            error_log('[rnc] respuesta inesperada (HTTP ' . $code . '): ' . substr((string) $raw, 0, 300));
            return self::fallo(502, self::NO_DISPONIBLE);
        }

        $rncDevuelto = (string) preg_replace('/\D/', '', (string) ($body['cedula_rnc'] ?? ''));
        if ($rncDevuelto === '') {
            $rncDevuelto = $rnc;
        }

        return [
            'status' => 200,
            'error' => null,
            'data' => [
                'rnc' => $rncDevuelto,
                'tipo' => strlen($rncDevuelto) === 11 ? 'CEDULA' : 'RNC',
                'razon_social' => self::texto($body['nombre_razon_social'] ?? ''),
                'nombre_comercial' => self::texto($body['nombre_comercial'] ?? ''),
                'estado' => strtoupper(self::texto($body['estado'] ?? '')),
                'facturador_electronico' => strtoupper(self::texto($body['facturador_electronico'] ?? '')) === 'SI',
                'actividad_economica' => self::texto($body['actividad_economica'] ?? ''),
                'regimen_pagos' => self::texto($body['regimen_de_pagos'] ?? ''),
            ],
        ];
    }

    private static function fallo(int $status, string $error): array
    {
        return ['status' => $status, 'data' => null, 'error' => $error];
    }

    /** Texto limpio: sin espacios dobles ni en los extremos. */
    private static function texto($valor): string
    {
        return trim((string) preg_replace('/\s+/', ' ', (string) $valor));
    }
}
