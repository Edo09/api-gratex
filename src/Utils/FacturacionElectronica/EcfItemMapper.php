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
    /** Decimales de CantidadItem en el XSD (Decimal18D1or2). */
    public const DECIMALES_CANTIDAD = 2;

    /** Decimales de PrecioUnitarioItem en el XSD (Decimal20D1or4). */
    public const DECIMALES_PRECIO = 4;

    /**
     * Tipos que se pueden emitir con precios que ya traen ITBIS
     * (IndicadorMontoGravado = 1). El XSD lo admite tambien en E41 y E45, pero
     * el POS solo vende con E31/E32 y devuelve con E34: se abre a lo que se usa
     * y se verifica con la DGII, no a todo lo que el XSD permite.
     */
    public const TIPOS_PRECIOS_CON_ITBIS = ['31', '32', '33', '34'];

    /**
     * Deja cantidad y precio de cada linea con los decimales que admite el XML:
     * cantidad a 2 y precio a 4, con el round() del servidor (el front imita el
     * de PHP 8.3 en montosLinea.ts).
     *
     * Se aplica UNA vez y antes de todo lo demas (descuento del cliente,
     * totales(), map() y lo que se guarda en factura_items): MontoItem =
     * round(cantidad x precio, 2) - descuento tiene que salir de los MISMOS
     * valores que imprime el XML. Antes los totales usaban el valor crudo y
     * ECFXmlBuilder::qty() recortaba a 2 decimales solo al escribir: 1.125 x
     * 84.7458 firmaba CantidadItem 1.13 con MontoItem 95.34 (1.13 x 84.7458 =
     * 95.76), y un precio de 5 decimales salia con 2.
     *
     * La cantidad se valida ANTES (unidadMedidaModel::problemaCantidad con 2
     * decimales): aqui solo se limpia el ruido binario, nunca se convierte en
     * silencio 1.125 en 1.13. No toca cantidad_raw / precio_unitario_raw: el
     * set de certificacion DGII (strict_input) va tal cual y quien llama no
     * normaliza en ese modo. monto_item del body, si viene, se respeta como
     * siempre (map()).
     */
    public static function normalizarCantidadPrecio(array $items): array
    {
        foreach ($items as &$item) {
            $item = (array) $item;
            // Misma lectura que map(): sin cantidad = 1, sin precio = 0.
            $item['cantidad'] = round((float) ($item['cantidad'] ?? $item['quantity'] ?? 1), self::DECIMALES_CANTIDAD);
            $item['precio_unitario'] = round((float) ($item['precio_unitario'] ?? $item['amount'] ?? 0), self::DECIMALES_PRECIO);
        }
        unset($item);
        return $items;
    }

    /**
     * @param bool $preciosConItbis IndicadorMontoGravado = 1: el precio de cada
     *   linea ya trae el ITBIS (ver desglosarIncluido). MontoItem se calcula
     *   igual, pero el ITBIS de la linea sale de adentro del monto en vez de
     *   sumarse encima, y `monto_neto` (lo que se guarda como subtotal) queda
     *   sin ITBIS para que reportes y 607 sigan leyendo una base imponible.
     */
    public static function map(array $items, bool $strict = false, bool $preciosConItbis = false): array
    {
        $mapped = [];
        $montosIncluidos = [];
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
            if ($preciosConItbis) {
                // Se reparte despues del bucle: el ITBIS de cada linea depende de
                // las demas lineas de su tasa (ver desglosarIncluido).
                $montosIncluidos[$i] = ['indicador' => $indicador, 'monto' => $monto];
            } elseif ($indicador === 1) {
                $itbis = round($monto * 0.18, 2);
            } elseif ($indicador === 2) {
                $itbis = round($monto * 0.16, 2);
            }

            $mapped[$i] = [
                // No va al XML: viaja para que la factura sepa que producto del
                // catalogo es cada linea y pueda descontar inventario al guardar.
                'product_id' => !empty($raw['product_id']) ? (int) $raw['product_id'] : null,
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
                // Texto original: el set de pruebas DGII lo firma tal cual
                // (ECFXmlBuilder::decimal); la emision normal usa el float.
                'descuento_monto_raw' => $raw['descuento_monto_raw'] ?? $raw['descuento_monto'] ?? null,
                'subdescuentos' => is_array($raw['subdescuentos'] ?? null) ? $raw['subdescuentos'] : [],
                'recargo_monto' => $raw['recargo_monto'] ?? null,
                'subrecargos' => is_array($raw['subrecargos'] ?? null) ? $raw['subrecargos'] : [],
                'impuestos_adicionales' => is_array($raw['impuestos_adicionales'] ?? null) ? $raw['impuestos_adicionales'] : [],
                'monto_item' => isset($raw['monto_item']) && $raw['monto_item'] !== '' ? (float) $raw['monto_item'] : $monto,
                'monto_item_raw' => $raw['monto_item_raw'] ?? null,
                'itbis_amount' => $itbis,
                // Base sin ITBIS de la linea: es lo que se guarda como
                // factura_items.subtotal. Con precios sin ITBIS es el MontoItem.
                'monto_neto' => $monto,
            ];
        }

        if ($preciosConItbis) {
            $desglose = self::desglosarIncluido($montosIncluidos);
            foreach ($desglose['itbis'] as $i => $itbis) {
                $mapped[$i]['itbis_amount'] = $itbis;
                $mapped[$i]['monto_neto'] = round($mapped[$i]['monto_item'] - $itbis, 2);
            }
        }
        return array_values($mapped);
    }

    /** Tasa de ITBIS en % segun indicador_facturacion: 1 = 18, 2 = 16, el resto 0. */
    private static function tasaItbis(int $indicador): int
    {
        return $indicador === 1 ? 18 : ($indicador === 2 ? 16 : 0);
    }

    /**
     * Desglose de montos que YA traen el ITBIS (IndicadorMontoGravado = 1), como
     * lo calcula la DGII: por TASA, no por linea.
     *
     *   MontoGravadoIx = round(suma de MontoItem de la tasa / (1 + tasa), 2)
     *   TotalITBISx    = suma de MontoItem de la tasa - MontoGravadoIx
     *
     * Verificado con el set de pruebas DGII del tenant 130968837 (2026-09-02):
     * E450000000003 tiene 10 lineas al 18% que suman 477,750.00 y la DGII espera
     * MontoGravadoI1 404,872.88 / TotalITBIS1 72,877.12; dividiendo linea por
     * linea daria 404,872.90. Con el ITBIS como diferencia, MontoTotal es
     * exactamente la suma de los MontoItem: 7 x RD$25 da 175.00, y no 174.99 o
     * 175.01 como sumando el 18% encima de un precio neto.
     *
     * El ITBIS de cada linea (factura_items.itbis_amount, la RI) se reparte por
     * mayor residuo para que las lineas de una tasa sumen exactamente su
     * TotalITBISx: cada base queda a menos de un centavo de MontoItem / (1 + tasa).
     * Todo se calcula en centavos enteros: el redondeo no depende de floats.
     *
     * @param array<int,array{indicador:int,monto:float}> $lineas
     * @return array{itbis: array<int,float>, tasas: array<int,array{bruto:float,base:float,itbis:float}>}
     *   `tasas` va por tasa en % (18, 16, 0) e incluye solo las gravadas.
     */
    private static function desglosarIncluido(array $lineas): array
    {
        $itbisLinea = [];
        $porTasa = [];
        foreach ($lineas as $i => $l) {
            $itbisLinea[$i] = 0.0;
            $tasa = self::tasaItbis((int) $l['indicador']);
            // Indicador fuera de 1..4 se trata como 18%, igual que totales().
            if (!in_array((int) $l['indicador'], [1, 2, 3, 4, 0], true)) {
                $tasa = 18;
            }
            if ($tasa === 0) {
                continue;
            }
            $porTasa[$tasa][$i] = (int) round((float) $l['monto'] * 100);
        }

        $tasas = [];
        foreach ($porTasa as $tasa => $centavos) {
            $divisor = 100 + $tasa;
            $bruto = array_sum($centavos);
            // round(bruto / (1 + tasa)) en enteros: la mitad hacia arriba. No hay
            // empates reales: bruto x 100 / 118 (o / 116) nunca termina en .5.
            $base = intdiv(2 * $bruto * 100 + $divisor, 2 * $divisor);

            // Base de cada linea: el piso de su parte, y los centavos que faltan
            // para llegar a la base de la tasa van a las de mayor residuo.
            $pisos = [];
            $residuos = [];
            foreach ($centavos as $i => $c) {
                $pisos[$i] = intdiv($c * 100, $divisor);
                $residuos[$i] = ($c * 100) % $divisor;
            }
            $faltan = $base - array_sum($pisos);
            // Mayor residuo primero; a igual residuo, la linea anterior.
            uksort($residuos, static function ($a, $b) use ($residuos) {
                return [$residuos[$b], $a] <=> [$residuos[$a], $b];
            });
            foreach (array_keys($residuos) as $i) {
                if ($faltan <= 0) {
                    break;
                }
                $pisos[$i]++;
                $faltan--;
            }
            foreach ($centavos as $i => $c) {
                $itbisLinea[$i] = ($c - $pisos[$i]) / 100;
            }
            $tasas[$tasa] = [
                'bruto' => $bruto / 100,
                'base' => $base / 100,
                'itbis' => ($bruto - $base) / 100,
            ];
        }

        return ['itbis' => $itbisLinea, 'tasas' => $tasas];
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
     *
     * Con $preciosConItbis (IndicadorMontoGravado = 1) los montos gravados son
     * la parte sin ITBIS de cada tasa y MontoTotal la suma exacta de los
     * MontoItem (ver desglosarIncluido).
     */
    public static function totales(array $items, bool $preciosConItbis = false): array
    {
        if ($preciosConItbis) {
            return self::totalesIncluido($items);
        }

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

    /** totales() con IndicadorMontoGravado = 1: mismas claves, montos de desglosarIncluido. */
    private static function totalesIncluido(array $items): array
    {
        $lineas = [];
        $i3 = 0;        // gravado al 0%, en centavos: no lleva ITBIS que desglosar
        $exento = 0;
        $total = 0;
        foreach (array_values($items) as $i => $item) {
            $cantidad = (float) ($item['cantidad'] ?? $item['quantity'] ?? 1);
            $precio = (float) ($item['precio_unitario'] ?? $item['amount'] ?? 0);
            $bruto = round($cantidad * $precio, 2);
            $monto = round($bruto - self::montoDescuento($item, $bruto), 2);
            $indicador = (int) ($item['indicador_facturacion'] ?? 1);
            $centavos = (int) round($monto * 100);
            $total += $centavos;
            if ($indicador === 3) {
                $i3 += $centavos;
            } elseif ($indicador === 4 || $indicador === 0) {
                $exento += $centavos;
            } else {
                $lineas[$i] = ['indicador' => $indicador, 'monto' => $monto];
            }
        }

        $tasas = self::desglosarIncluido($lineas)['tasas'];
        $base18 = $tasas[18]['base'] ?? 0.0;
        $base16 = $tasas[16]['base'] ?? 0.0;
        $itbis18 = $tasas[18]['itbis'] ?? 0.0;
        $itbis16 = $tasas[16]['itbis'] ?? 0.0;

        return [
            'monto_gravado_total' => round($base18 + $base16 + $i3 / 100, 2),
            'monto_gravado_i1' => round($base18, 2),
            'monto_gravado_i2' => round($base16, 2),
            'monto_gravado_i3' => round($i3 / 100, 2),
            'monto_exento' => round($exento / 100, 2),
            'itbis1' => 18,
            'itbis2' => 16,
            'itbis3' => 0,
            'total_itbis' => round($itbis18 + $itbis16, 2),
            'total_itbis1' => round($itbis18, 2),
            'total_itbis2' => round($itbis16, 2),
            'total_itbis3' => 0.0,
            'monto_total' => round($total / 100, 2),
        ];
    }
}
