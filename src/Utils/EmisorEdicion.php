<?php

/**
 * EmisorEdicion — reglas para que el administrador edite los datos de su empresa
 * (la fila unica de emisor_config) desde Configuracion > Datos de empresa.
 *
 * Que se puede cambiar y que no:
 *   - Editables: nombre comercial, sucursal, direccion, municipio, provincia,
 *     telefono, correo, sitio web y actividad economica.
 *   - Bloqueados: RNC y razon social. Son la identidad fiscal de la empresa (la DGII
 *     los valida contra su registro y contra el certificado), asi que solo soporte los
 *     cambia. Si el cuerpo los trae iguales a los guardados se ignoran (un cliente
 *     puede devolver lo que leyo con GET); si los trae distintos, se rechaza.
 *
 * Los limites son los del XSD de la DGII (samples/e-CF 31 v.1.0.xsd, nodo Emisor): lo
 * que se guarda aqui viaja dentro del XML firmado de CADA e-CF, y un valor que el XSD no
 * acepta hace que la DGII rechace todos los comprobantes de la empresa. Por eso el
 * servidor valida aunque la pantalla ya lo haya hecho.
 *
 * Clase pura: sin base de datos ni HTTP. El catalogo de ubicaciones entra como callable
 * (ver validar()), de modo que se prueba sin servidor (tools/test_emisor_edicion.php).
 */
final class EmisorEdicion
{
    /** Datos que el administrador puede cambiar, en el orden de la pantalla. */
    public const EDITABLES = [
        'nombre_comercial', 'sucursal', 'direccion', 'municipio', 'provincia',
        'telefono', 'correo', 'website', 'actividad_economica',
    ];

    /** Identidad fiscal: solo soporte la cambia. */
    public const BLOQUEADOS = ['rnc', 'razon_social'];

    /**
     * Largo maximo en caracteres. XSD: NombreComercial AlfNum150, Sucursal AlfNum20,
     * DireccionEmisor AlfNum100, CorreoEmisor 80, WebSite AlfNum50, ActividadEconomica
     * AlfNum100. Coincide con las columnas de emisor_config (db/tenant_schema.sql).
     */
    public const LARGO = [
        'nombre_comercial' => 150,
        'sucursal' => 20,
        'direccion' => 100,
        'correo' => 80,
        'website' => 50,
        'actividad_economica' => 100,
    ];

    /** CorreoValidationType del XSD, tal cual (mas estricto que FILTER_VALIDATE_EMAIL). */
    private const CORREO_XSD = '/^\w+([-+.]\w+)*@\w+([-.]\w+)*\.\w+([-.]\w+)*$/D';

    /** Como se nombra cada dato en los mensajes y en la bitacora. */
    private const ROTULO = [
        'nombre_comercial' => 'el nombre comercial',
        'sucursal' => 'la sucursal',
        'direccion' => 'la dirección',
        'municipio' => 'el municipio',
        'provincia' => 'la provincia',
        'telefono' => 'el teléfono',
        'correo' => 'el correo',
        'website' => 'el sitio web',
        'actividad_economica' => 'la actividad económica',
    ];

    public const MSG_BLOQUEADO = 'El RNC y la razón social no se pueden cambiar desde aquí. Si necesitas corregirlos, comunícate con soporte.';
    public const MSG_SIN_CAMBIOS = 'No hay cambios para guardar.';
    public const MSG_SIN_DIRECCION = 'Escribe la dirección de tu empresa.';
    public const MSG_TELEFONO = 'El teléfono debe tener 10 dígitos, por ejemplo 809-555-1234.';
    public const MSG_CORREO = 'El correo no es válido. Revisa que esté completo, por ejemplo nombre@empresa.com.';
    public const MSG_WEBSITE_ESPACIOS = 'El sitio web no puede tener espacios.';
    public const MSG_PROVINCIA = 'Elige la provincia de la lista.';
    public const MSG_MUNICIPIO = 'Elige el municipio de la lista.';
    public const MSG_MUNICIPIO_AJENO = 'El municipio no pertenece a la provincia elegida. Elígelo de nuevo.';
    public const MSG_VALOR = 'Este dato no tiene un formato válido.';
    public const MSG_SIN_COLUMNA = 'Tu empresa todavía no puede guardar este dato. Avisa a soporte.';

    /**
     * Revisa lo que mando el cliente contra la fila actual y devuelve solo lo que cambia.
     *
     * El cuerpo es parcial (como PUT /api/branding): solo se miran las claves presentes y
     * las demas se conservan. Un dato igual al guardado no es un cambio y no se valida de
     * nuevo (un valor heredado que no cumple las reglas de hoy no impide guardar el resto).
     *
     * @param array         $cuerpo    JSON del cliente, ya decodificado.
     * @param array         $actual    fila actual de emisor_config.
     * @param callable|null $ubicacion fn(string $codigo): ?array con 'tipo' (PROVINCIA, MUNICIPIO
     *                                 o DISTRITO) del catalogo DGII, o null si el codigo no existe.
     *                                 null en lugar del callable = catalogo no disponible: solo se
     *                                 exige el formato de 6 digitos (la validacion final es del XSD).
     * @return array{cambios: array<string, ?string>, errores: array<string, string>}
     *         cambios: columna => valor normalizado (null = dejar en blanco). errores: campo => mensaje.
     */
    public static function validar(array $cuerpo, array $actual, ?callable $ubicacion = null): array
    {
        $errores = [];
        $cambios = [];

        foreach (self::BLOQUEADOS as $campo) {
            if (array_key_exists($campo, $cuerpo) && !self::igualBloqueado($campo, $cuerpo[$campo], $actual[$campo] ?? null)) {
                $errores[$campo] = self::MSG_BLOQUEADO;
            }
        }

        foreach (self::EDITABLES as $campo) {
            if (!array_key_exists($campo, $cuerpo)) {
                continue;
            }
            // Un tenant con un esquema viejo puede no tener la columna: error claro, no un 500 de SQL.
            if (!array_key_exists($campo, $actual)) {
                $errores[$campo] = self::MSG_SIN_COLUMNA;
                continue;
            }
            $crudo = $cuerpo[$campo];
            if ($crudo !== null && (!is_scalar($crudo) || is_bool($crudo))) {
                $errores[$campo] = self::MSG_VALOR;
                continue;
            }
            $texto = self::compactar((string) ($crudo ?? ''));
            $guardado = self::compactar((string) ($actual[$campo] ?? ''));
            if ($texto === null || $guardado === null) {
                $errores[$campo] = self::MSG_VALOR;
                continue;
            }
            if ($texto === $guardado) {
                continue;
            }
            [$valor, $error] = self::normalizar($campo, $texto, $ubicacion);
            if ($error !== null) {
                $errores[$campo] = $error;
                continue;
            }
            // 8095551234 ya estaba guardado como 809-555-1234: no es un cambio.
            if (($valor ?? '') === $guardado) {
                continue;
            }
            $cambios[$campo] = $valor;
        }

        // El municipio tiene que ser de la provincia (PPMMDD: los 2 primeros digitos son la provincia).
        if (!isset($errores['municipio']) && !isset($errores['provincia'])
            && (array_key_exists('provincia', $cambios) || array_key_exists('municipio', $cambios))) {
            $provincia = array_key_exists('provincia', $cambios) ? $cambios['provincia'] : trim((string) ($actual['provincia'] ?? ''));
            $municipio = array_key_exists('municipio', $cambios) ? $cambios['municipio'] : trim((string) ($actual['municipio'] ?? ''));
            if (is_string($provincia) && is_string($municipio)
                && preg_match('/^\d{6}$/', $provincia) === 1 && preg_match('/^\d{6}$/', $municipio) === 1
                && substr($provincia, 0, 2) !== substr($municipio, 0, 2)) {
                $errores['municipio'] = self::MSG_MUNICIPIO_AJENO;
            }
        }

        return ['cambios' => $cambios, 'errores' => $errores];
    }

    /** "la dirección, el teléfono": para la bitacora, de las columnas que cambiaron. */
    public static function describir(array $columnas): string
    {
        $nombres = [];
        foreach ($columnas as $columna) {
            $nombres[] = self::ROTULO[$columna] ?? $columna;
        }
        return implode(', ', $nombres);
    }

    /** Una linea: recorta, junta espacios y saltos en un solo espacio. null si el texto no es UTF-8 valido. */
    private static function compactar(string $texto): ?string
    {
        $r = preg_replace('/\s+/u', ' ', $texto);
        return $r === null ? null : trim($r);
    }

    /** RNC y razon social del cuerpo contra los guardados: el RNC por sus digitos, la razon social sin mayusculas ni espacios de mas. */
    private static function igualBloqueado(string $campo, $enviado, $guardado): bool
    {
        if (!is_scalar($enviado) || is_bool($enviado)) {
            return false;
        }
        if ($campo === 'rnc') {
            return preg_replace('/\D/', '', (string) $enviado) === preg_replace('/\D/', '', (string) ($guardado ?? ''));
        }
        $a = self::compactar((string) $enviado);
        $b = self::compactar((string) ($guardado ?? ''));
        return $a !== null && $b !== null && mb_strtolower($a) === mb_strtolower($b);
    }

    /**
     * Normaliza y valida un dato editable ya compactado.
     * @return array{0: ?string, 1: ?string} [valor, error]; valor null = dejar en blanco.
     */
    private static function normalizar(string $campo, string $texto, ?callable $ubicacion): array
    {
        switch ($campo) {
            case 'direccion':
                return $texto === '' ? [null, self::MSG_SIN_DIRECCION] : self::conLargo($campo, $texto);

            case 'nombre_comercial':
            case 'sucursal':
            case 'actividad_economica':
                return $texto === '' ? [null, null] : self::conLargo($campo, $texto);

            case 'website':
                if ($texto === '') {
                    return [null, null];
                }
                if (str_contains($texto, ' ')) {
                    return [null, self::MSG_WEBSITE_ESPACIOS];
                }
                return self::conLargo($campo, $texto);

            case 'correo':
                if ($texto === '') {
                    return [null, null];
                }
                if (mb_strlen($texto) > self::LARGO['correo'] || preg_match(self::CORREO_XSD, $texto) !== 1) {
                    return [null, self::MSG_CORREO];
                }
                return [$texto, null];

            case 'telefono':
                if ($texto === '') {
                    return [null, null];
                }
                $digitos = preg_replace('/\D/', '', $texto);
                if (strlen($digitos) === 11 && $digitos[0] === '1') {
                    $digitos = substr($digitos, 1);   // +1 de Republica Dominicana
                }
                if (strlen($digitos) !== 10) {
                    return [null, self::MSG_TELEFONO];
                }
                return [substr($digitos, 0, 3) . '-' . substr($digitos, 3, 3) . '-' . substr($digitos, 6), null];

            case 'provincia':
            case 'municipio':
                if ($texto === '') {
                    return [null, null];
                }
                $mensaje = $campo === 'provincia' ? self::MSG_PROVINCIA : self::MSG_MUNICIPIO;
                if (preg_match('/^\d{6}$/', $texto) !== 1) {
                    return [null, $mensaje];
                }
                if ($ubicacion !== null) {
                    $fila = $ubicacion($texto);
                    $tipo = is_array($fila) ? strtoupper((string) ($fila['tipo'] ?? '')) : '';
                    $esDeSuTipo = $campo === 'provincia'
                        ? $tipo === 'PROVINCIA'
                        : in_array($tipo, ['MUNICIPIO', 'DISTRITO'], true);
                    if (!$esDeSuTipo) {
                        return [null, $mensaje];
                    }
                }
                return [$texto, null];
        }
        return [null, self::MSG_VALOR];
    }

    /** @return array{0: ?string, 1: ?string} */
    private static function conLargo(string $campo, string $texto): array
    {
        $max = self::LARGO[$campo];
        if (mb_strlen($texto) > $max) {
            return [null, ucfirst(self::ROTULO[$campo]) . " no puede pasar de {$max} caracteres."];
        }
        return [$texto, null];
    }
}
