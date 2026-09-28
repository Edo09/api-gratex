<?php

require_once __DIR__ . '/EcfUsuarioException.php';

/**
 * InformacionReferencia de las notas E33 (debito) y E34 (credito): la factura
 * que modifican. Reglas del XSD de la DGII (samples/e-CF 33|34 v.1.0.xsd):
 *
 *   NCFModificado       obligatorio, 11 a 19 caracteres (e-NCF de 13 o NCF).
 *   RNCOtroContribuyente opcional, 9 u 11 digitos. En E33/E34 va vacio: si se
 *                        manda el RNC del comprador DGII responde error 614.
 *   FechaNCFModificado  obligatoria, dd-mm-aaaa.
 *   CodigoModificacion  obligatorio, catalogo 1-5 (CODIGOS_MODIFICACION).
 *   RazonModificacion   opcional, 1 a 90 caracteres. Fuera del modo estricto el
 *                        builder pone un texto por defecto si viene vacia.
 *
 * Se valida ANTES de reservar el e-NCF: si esto fallaba recien en
 * ECFXmlBuilder, el numero ya estaba dispensado y cada intento quemaba uno del
 * rango autorizado. Los mensajes tecnicos de los campos obligatorios son los
 * mismos que daba el builder, para no cambiarle el texto a quien los lea.
 */
final class InformacionReferencia
{
    /** Tipos de e-CF que exigen InformacionReferencia. */
    public const TIPOS_NOTA = ['33', '34'];

    /** Catalogo DGII de CodigoModificacion (CodigoModificacionType). */
    public const CODIGOS_MODIFICACION = [
        '1' => 'Anula el NCF modificado',
        '2' => 'Corrige texto del comprobante fiscal modificado',
        '3' => 'Corrige montos del NCF modificado',
        '4' => 'Reemplazo NCF emitido en contingencia',
        '5' => 'Referencia factura consumo electronica',
    ];

    /** RazonModificacion es AlfNum90ValidationType. */
    public const RAZON_MAX = 90;

    /**
     * Normaliza y valida la referencia de una nota. Para cualquier otro tipo
     * devuelve $ref intacto.
     *
     * @param mixed       $ref          informacion_referencia tal como llego
     * @param bool        $strict       modo set de pruebas DGII: no exige razon
     * @param string|null $fechaEmision fecha de la nota; si viene, la factura
     *                                  modificada no puede ser posterior
     * @return mixed la referencia con ncf sin espacios y fecha en dd-mm-aaaa
     * @throws EcfUsuarioException
     */
    public static function normalizar(string $tipoEcf, $ref, bool $strict = false, ?string $fechaEmision = null)
    {
        if (!in_array($tipoEcf, self::TIPOS_NOTA, true)) {
            return $ref;
        }
        $nota = $tipoEcf === '34' ? 'nota de crédito' : 'nota de débito';

        $ref = is_array($ref) ? $ref : [];
        foreach ($ref as $k => $v) {
            if (is_string($v)) {
                $ref[$k] = trim($v);
            }
        }
        // DGII rechaza NCFModificado con espacios (p.ej. " E31..."), tambien
        // intermedios cuando el numero se copio de un PDF.
        if (isset($ref['ncf_modificado']) && is_string($ref['ncf_modificado'])) {
            $ref['ncf_modificado'] = preg_replace('/\s+/', '', $ref['ncf_modificado']);
        }

        $requeridos = [
            'ncf_modificado' => 'Indica la factura que modifica esta ' . $nota . '.',
            'fecha_ncf_modificado' => 'Indica la fecha de la factura que modifica esta ' . $nota . '.',
            'codigo_modificacion' => 'Elige qué corrige esta ' . $nota . ': si anula la factura, corrige un texto o corrige montos.',
        ];
        foreach ($requeridos as $campo => $mensajeUsuario) {
            if ((string) ($ref[$campo] ?? '') === '') {
                throw new EcfUsuarioException(
                    'InformacionReferencia.' . $campo . ' es requerido para e-CF tipo ' . $tipoEcf . '.',
                    $mensajeUsuario
                );
            }
        }

        $ncf = (string) $ref['ncf_modificado'];
        $largo = strlen($ncf);
        if ($largo < 11 || $largo > 19) {
            throw new EcfUsuarioException(
                'InformacionReferencia.ncf_modificado invalido (' . $ncf . '): debe tener entre 11 y 19 caracteres.',
                'El número de la factura que modifica esta ' . $nota . ' (' . $ncf . ') no es válido. '
                    . 'Un e-NCF tiene 13 caracteres, como E310000000001.'
            );
        }

        $fecha = self::fechaDgii((string) $ref['fecha_ncf_modificado']);
        if ($fecha === null) {
            throw new EcfUsuarioException(
                'InformacionReferencia.fecha_ncf_modificado invalida (' . $ref['fecha_ncf_modificado'] . '): use dd-mm-aaaa.',
                'La fecha de la factura que modifica esta ' . $nota . ' no es válida. Revísala.'
            );
        }
        $ref['fecha_ncf_modificado'] = $fecha;

        $fechaNota = $fechaEmision !== null ? self::fechaDgii($fechaEmision) : null;
        if ($fechaNota !== null && self::dia($fecha) > self::dia($fechaNota)) {
            throw new EcfUsuarioException(
                'InformacionReferencia.fecha_ncf_modificado (' . $fecha . ') es posterior a la fecha de emision de la nota (' . $fechaNota . ').',
                'La factura que modifica tiene fecha ' . $fecha . ', posterior a la de esta ' . $nota . ' (' . $fechaNota . '). '
                    . 'Una nota solo puede modificar una factura ya emitida.'
            );
        }

        $codigo = (string) $ref['codigo_modificacion'];
        if (!array_key_exists($codigo, self::CODIGOS_MODIFICACION)) {
            throw new EcfUsuarioException(
                'InformacionReferencia.codigo_modificacion invalido (' . $codigo . '): use 1, 2, 3, 4 o 5.',
                'Lo que corrige esta ' . $nota . ' no es válido. Elige una de las opciones de la lista.'
            );
        }
        $ref['codigo_modificacion'] = $codigo;

        $razon = (string) ($ref['razon_modificacion'] ?? '');
        $largoRazon = mb_strlen($razon);
        if ($largoRazon > self::RAZON_MAX) {
            throw new EcfUsuarioException(
                'InformacionReferencia.razon_modificacion supera ' . self::RAZON_MAX . ' caracteres (' . $largoRazon . ').',
                'La razón de la ' . $nota . ' es muy larga: la DGII acepta hasta ' . self::RAZON_MAX
                    . ' caracteres y tiene ' . $largoRazon . '. Acórtala.'
            );
        }
        $ref['razon_modificacion'] = $razon;

        $rncOtro = preg_replace('/\D/', '', (string) ($ref['rnc_otro_contribuyente'] ?? ''));
        if ($rncOtro !== '' && strlen($rncOtro) !== 9 && strlen($rncOtro) !== 11) {
            throw new EcfUsuarioException(
                'InformacionReferencia.rnc_otro_contribuyente invalido (' . $ref['rnc_otro_contribuyente'] . '): debe tener 9 u 11 digitos.',
                'El RNC del otro contribuyente debe tener 9 dígitos (empresa) u 11 (cédula).'
            );
        }
        $ref['rnc_otro_contribuyente'] = $rncOtro !== '' ? $rncOtro : null;

        return $ref;
    }

    /**
     * IndicadorNotaCredito de un E34 (obligatorio en su XSD): 0 si la nota se
     * emite dentro de los 30 dias calendario de la factura modificada, 1 si
     * despues. null si alguna fecha no se puede leer (el builder deja el '0').
     */
    public static function indicadorNotaCredito(string $fechaNota, string $fechaModificado): ?string
    {
        $nota = self::fechaDgii($fechaNota);
        $modificado = self::fechaDgii($fechaModificado);
        if ($nota === null || $modificado === null) {
            return null;
        }
        return (self::dia($nota) - self::dia($modificado)) > 30 ? '1' : '0';
    }

    /**
     * Fecha en el formato de la DGII (dd-mm-aaaa, con ceros), o null si no es
     * una fecha real. Acepta dd-mm-aaaa (lo que pide el XSD, con o sin ceros) y
     * aaaa-mm-dd (lo que guarda la base, con o sin hora).
     */
    public static function fechaDgii(string $valor): ?string
    {
        $valor = trim($valor);
        if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $valor, $m)) {
            [$d, $mes, $a] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T][\d:.]+)?$/', $valor, $m)) {
            [$a, $mes, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }
        // El XSD solo admite anos 19xx y 20xx.
        if ($a < 1900 || $a > 2099 || !checkdate($mes, $d, $a)) {
            return null;
        }
        return sprintf('%02d-%02d-%04d', $d, $mes, $a);
    }

    /** Dias desde 1970 de una fecha dd-mm-aaaa ya validada, para comparar. */
    private static function dia(string $fechaDgii): int
    {
        [$d, $m, $a] = array_map('intval', explode('-', $fechaDgii));
        return intdiv((int) gmmktime(0, 0, 0, $m, $d, $a), 86400);
    }
}
