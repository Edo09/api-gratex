<?php
require_once __DIR__ . '/PosError.php';
require_once __DIR__ . '/../Models/posModel.php';
require_once __DIR__ . '/../Models/clientModel.php';
require_once __DIR__ . '/../Utils/RncConsultaService.php';

/**
 * Cliente de credito fiscal desde el POS (docs/specs/pos.md F2, decision 19).
 *
 *  - El cajero escribe el RNC o la cedula. Si ya es cliente, se usa (con su
 *    descuento, V5).
 *  - Si no, se consulta el registro de contribuyentes (RncConsultaService) y se
 *    crea el cliente con la razon social que devuelve, sin correo ni telefono.
 *  - No inscrito -> no hay E31 (la venta puede salir como consumo). Servicio
 *    caido -> tampoco (default 9 / Q6: no se escribe la razon social a mano).
 */
final class PosCliente
{
    /** @return array{cliente: array, nuevo: bool, estado_dgii: ?string} */
    public static function porRnc(posModel $pos, $entrada): array
    {
        $rnc = (string) preg_replace('/\D/', '', (string) $entrada);
        if (strlen($rnc) !== 9 && strlen($rnc) !== 11) {
            throw new PosError('El RNC tiene 9 dígitos y la cédula 11.', 422, 'RNC_FORMATO');
        }
        $existente = $pos->clientePorRnc($rnc);
        if ($existente !== null) {
            return ['cliente' => self::forma($existente), 'nuevo' => false, 'estado_dgii' => null];
        }

        $r = (new RncConsultaService())->consultar($rnc);
        if ($r['status'] === 404) {
            throw new PosError('Ese RNC o cédula no está inscrito como contribuyente: no se puede hacer crédito fiscal. La venta puede salir como consumo.',
                404, 'RNC_NO_ENCONTRADO');
        }
        if ($r['status'] !== 200) {
            throw new PosError('La consulta de RNC no está disponible ahora, así que no se puede hacer crédito fiscal. La venta puede salir como consumo.',
                502, 'RNC_NO_DISPONIBLE');
        }
        $d = $r['data'];
        $razon = mb_substr($d['razon_social'], 0, 150);
        $guardado = (new clientModel())->saveClient([
            'email' => '',
            'phone_number' => '',
            // Contacto = nombre comercial si tiene; empresa y razon social = la del registro.
            'client_name' => mb_substr($d['nombre_comercial'] !== '' ? $d['nombre_comercial'] : $razon, 0, 100),
            'company_name' => mb_substr($razon, 0, 100),
            'razon_social' => $razon,
            'rnc' => $d['rnc'],
        ]);
        if ($guardado[0] !== 'success') {
            throw new PosError('No se pudo crear el cliente. Inténtalo de nuevo.', 500, 'CLIENTE_NO_CREADO');
        }
        $cliente = $pos->clientePorId((int) $guardado[2]);
        AuditLogger::log([
            'module' => 'pos', 'action' => 'POS_CLIENTE_CREADO', 'entity_type' => 'client', 'entity_id' => (int) $guardado[2],
            'new_values' => ['rnc' => $d['rnc'], 'razon_social' => $razon, 'estado' => $d['estado']],
            'description' => 'Cliente creado desde el POS con los datos del registro de contribuyentes.',
            'success' => true,
        ]);
        return ['cliente' => self::forma($cliente ?? []), 'nuevo' => true, 'estado_dgii' => $d['estado'] ?: null];
    }

    /** Lo que el POS necesita del cliente. */
    public static function forma(array $c): array
    {
        return [
            'id' => (int) ($c['id'] ?? 0),
            'nombre' => self::primero([$c['razon_social'] ?? null, $c['company_name'] ?? null, $c['client_name'] ?? null]) ?? '',
            'rnc' => (string) ($c['rnc'] ?? ''),
            'descuento' => round((float) ($c['descuento'] ?? 0), 2),
        ];
    }

    /** Comprador del e-CF, igual que facturaController (los vacios no cuentan). */
    public static function comprador(array $c): array
    {
        return [
            'rnc' => (string) ($c['rnc'] ?? ''),
            'razon_social' => self::primero([$c['razon_social'] ?? null, $c['company_name'] ?? null, $c['client_name'] ?? null]),
            'direccion' => $c['direccion'] ?? null,
            'municipio' => $c['municipio'] ?? null,
            'provincia' => $c['provincia'] ?? null,
            'correo' => ($c['email'] ?? '') !== '' ? $c['email'] : null,
            'contacto' => $c['client_name'] ?? null,
        ];
    }

    private static function primero(array $candidatos): ?string
    {
        foreach ($candidatos as $v) {
            if ($v !== null && trim((string) $v) !== '') {
                return trim((string) $v);
            }
        }
        return null;
    }
}
