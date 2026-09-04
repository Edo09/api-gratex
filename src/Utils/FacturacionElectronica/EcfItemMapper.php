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
            $indicador = (int) ($raw['indicador_facturacion'] ?? 1);

            // DGII: MontoItem = Cantidad x PrecioUnitario - DescuentoMonto, y el
            // ITBIS se calcula sobre ese neto. Antes el descuento se pasaba al XML
            // pero no se restaba, asi que MontoItem y el ITBIS quedaban inflados.
            $descuento = self::montoDescuento($raw, round($cantidad * $precio, 2));
            $monto = round(round($cantidad * $precio, 2) - $descuento, 2);

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
                'descuento_monto' => $descuento > 0 ? $descuento : null,
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

    /**
     * Descuento en monto de una linea, acotado a [0, bruto]: un descuento mayor
     * que la linea daria un MontoItem negativo, que el XSD rechaza.
     */
    private static function montoDescuento(array $raw, float $bruto): float
    {
        $d = $raw['descuento_monto'] ?? null;
        if ($d === null || $d === '' || !is_numeric($d)) {
            return 0.0;
        }
        return max(0.0, min($bruto, round((float) $d, 2)));
    }

    /**
     * Aplica un descuento porcentual a las lineas que NO traen uno propio.
     *
     * Lo usa la emision para bajar el `descuento` del cliente a cada linea: el
     * porcentaje es del cliente, pero DGII solo entiende montos por item. Una
     * linea que ya trae `descuento_monto` se respeta tal cual — el usuario lo
     * puso a mano y manda sobre el default del cliente.
     *
     * @param float $porcentaje 0-100. Fuera de rango o 0 devuelve los items intactos.
     */
    public static function aplicarDescuentoPorcentaje(array $items, float $porcentaje): array
    {
        if ($porcentaje <= 0 || $porcentaje > 100) {
            return $items;
        }
        foreach ($items as &$item) {
            $yaTiene = isset($item['descuento_monto']) && $item['descuento_monto'] !== ''
                && is_numeric($item['descuento_monto']) && (float) $item['descuento_monto'] > 0;
            if ($yaTiene) {
                continue;
            }
            $cantidad = (float) ($item['cantidad'] ?? $item['quantity'] ?? 1);
            $precio = (float) ($item['precio_unitario'] ?? $item['amount'] ?? 0);
            $bruto = round($cantidad * $precio, 2);
            if ($bruto <= 0) {
                continue;
            }
            $item['descuento_monto'] = round($bruto * $porcentaje / 100, 2);
            // MontoItem se recalcula neto en map(): un valor viejo aqui lo pisaria.
            unset($item['monto_item']);
        }
        unset($item);
        return $items;
    }

    /**
     * Totales del Encabezado derivados de los items: montos gravados por tasa,
     * exento, ITBIS por tasa y monto total.
     *
     * Mismo caso que map(): vivia en facturaController::computeTotales(), asi que
     * la ruta de integracion mandaba solo lo que trajera el payload. Con las tasas
     * pero sin los montos, DGII rechaza con "El campo MontoGravadoI1 / MontoExento
     * del area Totales de la seccion Encabezado no es valido".
     */
    public static function totales(array $items): array
    {
        $i1 = 0.0;       // gravado al 18%
        $i2 = 0.0;       // gravado al 16%
        $i3 = 0.0;       // gravado al 0%
        $exento = 0.0;   // exento (indicador 4)
        $itbis1 = 0.0;
        $itbis2 = 0.0;
        $itbis3 = 0.0;
        $montoTotal = 0.0;

        foreach ($items as $item) {
            $cantidad = (float) ($item['cantidad'] ?? $item['quantity'] ?? 1);
            $precio = (float) ($item['precio_unitario'] ?? $item['amount'] ?? 0);
            // Neto de descuento, igual que MontoItem: los montos gravados del
            // Encabezado tienen que cuadrar con la suma de las lineas.
            $bruto = round($cantidad * $precio, 2);
            $base = round($bruto - self::montoDescuento($item, $bruto), 2);
            $indicador = (int) ($item['indicador_facturacion'] ?? 1);

            $itbis = 0.0;
            if ($indicador === 1) {
                $itbis = round($base * 0.18, 2);
                $i1 += $base;
                $itbis1 += $itbis;
            } elseif ($indicador === 2) {
                $itbis = round($base * 0.16, 2);
                $i2 += $base;
                $itbis2 += $itbis;
            } elseif ($indicador === 3) {
                $i3 += $base;
            } elseif ($indicador === 4 || $indicador === 0) {
                $exento += $base;
            } else {
                $i1 += $base;
                $itbis1 += round($base * 0.18, 2);
            }

            $montoTotal += $base + $itbis;
        }

        return [
            'monto_gravado_total' => round($i1 + $i2 + $i3, 2),
            'monto_gravado_i1' => round($i1, 2),
            'monto_gravado_i2' => round($i2, 2),
            'monto_gravado_i3' => round($i3, 2),
            'monto_exento' => round($exento, 2),
            'itbis1' => 18,
            'itbis2' => 16,
            'itbis3' => 0,
            'total_itbis' => round($itbis1 + $itbis2 + $itbis3, 2),
            'total_itbis1' => round($itbis1, 2),
            'total_itbis2' => round($itbis2, 2),
            'total_itbis3' => round($itbis3, 2),
            'monto_total' => round($montoTotal, 2),
        ];
    }
}
