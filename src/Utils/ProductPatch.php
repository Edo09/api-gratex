<?php

/**
 * PATCH /api/products: cambia SOLO el nombre y el precio (lista 1) de un producto.
 *
 * Motivo (2026-11-03): PUT /api/products reemplaza TODAS las columnas (costo, sku, descripcion, categoria,
 * listas de precio 2 a 4, unidad, indicadores de ITBIS, existencia minima, activo) con lo que venga en el
 * cuerpo, y lo que falte vuelve a su valor por defecto. Quien no tiene el producto completo no puede usarlo
 * sin borrarlos. El panel web (roberto-music-admin) no guarda el costo ni el SKU a proposito, asi que edita
 * por aqui: recibe solo lo que cambia y no toca nada mas.
 *
 * Clase pura (sin base de datos ni sesion): la usa productController y la prueba
 * tools/test_producto_patch.php. productModel::actualizarNombrePrecio escribe lo que ella valida.
 */
final class ProductPatch
{
    /** El nombre se mide en bytes (strlen), como en validateProduct: una letra con tilde o una ñ cuenta 2. */
    public const NOMBRE_MAX_BYTES = 150;

    /** Un precio mayor que esto es un error de dedo (la columna aguanta mucho mas). */
    public const PRECIO_MAX = '100000000000';

    private const MENSAJE_NOMBRE = 'El nombre del producto es obligatorio y puede tener hasta 150 caracteres.';
    private const MENSAJE_PRECIO = 'El precio tiene que ser un número de 0 o más, con hasta 4 decimales.';

    /**
     * Valida el cuerpo del PATCH.
     *
     *   {"id": 12, "nombre": "...", "precio": "10593.2203",
     *    "esperado": {"nombre": "...", "precio": "9800.0000"}}
     *
     *   - id: entero mayor que 0 (obligatorio).
     *   - nombre y precio: opcionales, pero al menos uno. El precio es el de la lista 1, SIN ITBIS, con hasta 4
     *     decimales (como lo guarda api-gratex).
     *   - esperado: lo que quien edita vio antes del cambio (nombre y/o precio). Si el producto ya no lo tiene,
     *     alguien lo cambio en medio: se responde 409 y no se escribe nada. Se compara solo lo que se cambia.
     *
     * @param mixed $datos stdClass (json_decode) o arreglo
     * @return array{ok:true, id:int, cambios:array<string,string>, esperado:array<string,string>}|array{ok:false, error:string}
     */
    public static function validar($datos): array
    {
        if (is_object($datos)) {
            $datos = get_object_vars($datos);
        }
        if (!is_array($datos)) {
            return self::falla('No se pudo leer lo que se quiere cambiar.');
        }

        $id = $datos['id'] ?? null;
        $idValido = (is_int($id) && $id > 0) || (is_string($id) && preg_match('/^[1-9][0-9]{0,14}$/', $id) === 1);
        if (!$idValido) {
            return self::falla('No se pudo identificar el producto. Cierra la ventana y ábrelo de nuevo.');
        }

        $cambios = [];
        if (array_key_exists('nombre', $datos)) {
            $nombre = $datos['nombre'];
            if (!is_string($nombre) || trim($nombre) === '' || strlen(trim($nombre)) > self::NOMBRE_MAX_BYTES) {
                return self::falla(self::MENSAJE_NOMBRE);
            }
            $cambios['nombre'] = trim($nombre);
        }
        if (array_key_exists('precio', $datos)) {
            $precio = self::textoPrecio($datos['precio'], true);
            if ($precio === null) {
                return self::falla(self::MENSAJE_PRECIO);
            }
            $cambios['precio'] = $precio;
        }
        if ($cambios === []) {
            return self::falla('No hay nada que cambiar: manda el nombre, el precio o los dos.');
        }

        $esperado = [];
        $lo = $datos['esperado'] ?? null;
        if (is_object($lo)) {
            $lo = get_object_vars($lo);
        }
        if ($lo !== null) {
            if (!is_array($lo)) {
                return self::falla('No se pudo leer lo que se esperaba del producto.');
            }
            if (array_key_exists('nombre', $lo)) {
                if (!is_string($lo['nombre'])) {
                    return self::falla('No se pudo leer el nombre que se esperaba del producto.');
                }
                $esperado['nombre'] = $lo['nombre'];
            }
            if (array_key_exists('precio', $lo)) {
                $precio = self::textoPrecio($lo['precio'], false);
                if ($precio === null) {
                    return self::falla('No se pudo leer el precio que se esperaba del producto.');
                }
                $esperado['precio'] = $precio;
            }
        }

        return ['ok' => true, 'id' => (int) $id, 'cambios' => $cambios, 'esperado' => $esperado];
    }

    /**
     * Un precio como lo guarda la columna: texto con la parte entera sin ceros de mas y exactamente 4
     * decimales ('9800.0000'). null si no es un numero de 0 o mas con hasta 4 decimales (sin signo, sin
     * exponente, sin comas ni espacios). Con $limitar, tambien si pasa de PRECIO_MAX.
     *
     * @param mixed $valor texto, entero o decimal
     */
    public static function textoPrecio($valor, bool $limitar): ?string
    {
        if (is_int($valor)) {
            $valor = (string) $valor;
        } elseif (is_float($valor)) {
            if (!is_finite($valor)) {
                return null;
            }
            $valor = sprintf('%.4F', $valor);
        }
        if (!is_string($valor) || preg_match('/^([0-9]{1,14})(?:\.([0-9]{1,4}))?$/', $valor, $m) !== 1) {
            return null;
        }
        $entera = ltrim($m[1], '0');
        $entera = $entera === '' ? '0' : $entera;
        $decimales = str_pad($m[2] ?? '', 4, '0');
        if ($limitar && (strlen($entera) > strlen(self::PRECIO_MAX) || ($entera === self::PRECIO_MAX ? $decimales !== '0000' : strlen($entera) === strlen(self::PRECIO_MAX) && strcmp($entera, self::PRECIO_MAX) > 0))) {
            return null;
        }
        return $entera . '.' . $decimales;
    }

    /** @return array{ok:false, error:string} */
    private static function falla(string $error): array
    {
        return ['ok' => false, 'error' => $error];
    }
}
