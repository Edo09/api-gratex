<?php

/**
 * Normaliza los items de un e-CF antes de construir el XML.
 *
 * Calcula lo que el XSD exige y el cliente no tiene por que mandar: MontoItem
 * (cantidad x precio), el ITBIS de la linea segun su indicador de facturacion, y
 * el default de UnidadMedida.
 *
 * Vivia dentro de facturaController::mapItemsForXml(), asi que solo lo aplicaba
 * la ruta de tenants app. La ruta de integracion mandaba los items crudos y el
 * XML salia con <MontoItem>0.00</MontoItem>, que DGII rechaza por el
 * MinInclusive de Decimal18D2ValidationTypeMayor. Ahora las dos rutas usan esto.
 *
 * `$strict` = modo set de pruebas DGII: no se rellena UnidadMedida cuando el set
 * la entrega vacia (XSD minOccurs=0; rellenarla hace que DGII rechace el set).
 */
class EcfItemMapper
{
    public static function map(array $items, bool $strict = false): array
    {
        $mapped = [];
        foreach ($items as $i => $raw) {
            $cantidad = (float) ($raw['cantidad'] ?? $raw['quantity'] ?? 1);
            $precio = (float) ($raw['precio_unitario'] ?? $raw['amount'] ?? 0);
            $monto = round($cantidad * $precio, 2);
            $indicador = (int) ($raw['indicador_facturacion'] ?? 1);
            $itbis = 0.0;
            if ($indicador === 1) {
                $itbis = round($monto * 0.18, 2);
            } elseif ($indicador === 2) {
                $itbis = round($monto * 0.16, 2);
            }

            $mapped[] = [
                'numero_linea' => (int) ($raw['numero_linea'] ?? ($i + 1)),
                'indicador_facturacion' => $indicador,
                'indicador_agente_retencion_percepcion' => $raw['indicador_agente_retencion_percepcion'] ?? null,
                'monto_itbis_retenido' => $raw['monto_itbis_retenido'] ?? null,
                'monto_isr_retenido' => $raw['monto_isr_retenido'] ?? null,
                'nombre_item' => (string) ($raw['nombre_item'] ?? $raw['description'] ?? 'Item'),
                'indicador_bien_servicio' => (int) ($raw['indicador_bien_servicio'] ?? 2),
                'descripcion' => (string) ($raw['descripcion'] ?? $raw['description'] ?? ''),
                'cantidad' => $cantidad,
                'cantidad_raw' => $raw['cantidad_raw'] ?? null,
                'unidad_medida' => isset($raw['unidad_medida']) && (string) $raw['unidad_medida'] !== ''
                    ? (string) $raw['unidad_medida'] : ($strict ? null : '43'),
                'cantidad_referencia' => $raw['cantidad_referencia'] ?? null,
                'unidad_referencia' => $raw['unidad_referencia'] ?? null,
                'subcantidades' => is_array($raw['subcantidades'] ?? null) ? $raw['subcantidades'] : [],
                'grados_alcohol' => $raw['grados_alcohol'] ?? null,
                'precio_unitario_referencia' => $raw['precio_unitario_referencia'] ?? null,
                'fecha_elaboracion' => $raw['fecha_elaboracion'] ?? null,
                'fecha_vencimiento_item' => $raw['fecha_vencimiento_item'] ?? null,
                'precio_unitario' => $precio,
                'precio_unitario_raw' => $raw['precio_unitario_raw'] ?? null,
                'descuento_monto' => $raw['descuento_monto'] ?? null,
                'subdescuentos' => is_array($raw['subdescuentos'] ?? null) ? $raw['subdescuentos'] : [],
                'recargo_monto' => $raw['recargo_monto'] ?? null,
                'subrecargos' => is_array($raw['subrecargos'] ?? null) ? $raw['subrecargos'] : [],
                'impuestos_adicionales' => is_array($raw['impuestos_adicionales'] ?? null) ? $raw['impuestos_adicionales'] : [],
                'monto_item' => isset($raw['monto_item']) && $raw['monto_item'] !== '' ? (float) $raw['monto_item'] : $monto,
                'monto_item_raw' => $raw['monto_item_raw'] ?? null,
                'itbis_amount' => $itbis,
            ];
        }
        return $mapped;
    }
}
