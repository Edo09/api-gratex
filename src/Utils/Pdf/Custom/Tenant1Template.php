<?php
require_once __DIR__ . '/../ClasicoTemplate.php';

/**
 * Plantilla de GRATEX (tenant 1, 'custom:tenant1').
 *
 * Es la clasica tal cual (la disposicion del modelo de RI de la DGII) con los
 * ajustes de Gratex (ver ajustes()) mas el aviso de la fecha limite de pago a la
 * derecha de las firmas. Los ajustes y el aviso son decisiones de Gratex, no de
 * la norma DGII: por eso viven aqui y no en ClasicoTemplate, que es la de todos
 * los tenants.
 *
 * El diseno que Gratex tenia antes de ese modelo (cliente a la derecha, banda de
 * tabla negra, totales grises) vuelve sobreescribiendo usaDisposicionNorma() a
 * false: ClasicoTemplate dibuja entonces su encabezado, tabla y totales de antes.
 *
 * Activar:  UPDATE tenants SET pdf_template = 'custom:tenant1' WHERE id = 1;
 *           (o PUT /api/branding {"template":"custom:tenant1"}).
 */
class Tenant1Template extends ClasicoTemplate
{
    /**
     * Comprobantes donde el aviso no aplica: la nota de credito no se cobra, y
     * en E41/E43/E47 Gratex es quien paga (el "cliente" es su proveedor).
     */
    private const SIN_AVISO_DE_PAGO = ['34', '41', '43', '47'];

    /**
     * Sobre el modelo de la DGII: logo y sello mas grandes (el logo ya trae el
     * nombre de Gratex, asi que no se repite en texto), direccion, telefono y
     * correo sin etiqueta, y el cuadro de totales mas grande y en negrita.
     *
     * Sello: arranca en y=246 (bajo el codigo de seguridad del QR) y las lineas
     * de firma bajan a y=261.5 para darle sitio; con 52 mm de ancho el sello de
     * Gratex (proporcion 0.263) mide 13.7 mm de alto y termina en y=259.7.
     * Totales: con 38 + 32 mm el cuadro empieza en x=137.9, donde la columna
     * Precio de la tabla.
     */
    protected function ajustes(): array
    {
        return array_merge(parent::ajustes(), [
            'logo_caja'             => [70, 20],
            'nombre_con_logo'       => false,
            'etiquetas_contacto'    => false,
            'sello_caja'            => [52, 14.5],
            'firma_y'               => 261.5,
            'totales_fuente'        => 11,
            'totales_negrita_valor' => true,
            'totales_ancho'         => [38, 32],
        ]);
    }

    /**
     * Pie del clasico + el aviso de pago, que empieza en y=254.3 (x>=131). Con
     * sello manda el sello (y=246); sin sello, el aviso.
     */
    public function layout(): array
    {
        return array_merge(parent::layout(), [
            'pie_y' => BrandingResolver::selloPath() !== null ? 245 : 254,
        ]);
    }

    /** El vencimiento ya lo dice el aviso del pie: sin la linea del encabezado. */
    public function mostrarVencimientoPago(): bool
    {
        return false;
    }

    public function drawFooter($pdf): void
    {
        parent::drawFooter($pdf);

        $doc = $this->documento();
        if ($doc === null || in_array($doc->tipoEcf(), self::SIN_AVISO_DE_PAGO, true)) {
            return;
        }

        // Entre la firma del cliente (termina en x=125) y el cuadro de totales
        // (borde derecho x=205.9): a 8 pt la linea en mayusculas mide 73.6 mm y
        // es la que manda el ancho. Las seis lineas van de y=254.3 a 273.5 (el
        // final no cambia: abajo queda el margen de impresion), a la derecha de
        // "Pagina X de Y" (centrada, no se cruzan).
        $lineas = [
            'Para los clientes con crédito',
            'Apreciado cliente: La fecha límite del pago de',
            'la presente factura es para el ' . $doc->fechaLimitePago() . '.',
            'Facturas en atraso de 40 días, generarán un cargo',
            'de 3% de interés mensual.',
            'POR FAVOR EVITE DEMORAS EN EL PAGO. GRACIAS.',
        ];
        $pdf->SetFont('Arial', '', 8);
        $y = 254.3;
        foreach ($lineas as $linea) {
            $pdf->SetXY(131, $y);
            $pdf->Cell(76, 3.2, $this->enc($linea), 0, 0, 'L');
            $y += 3.2;
        }
    }
}
