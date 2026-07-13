<?php

/**
 * Sanitiza texto entrante de clientes antes de que llegue a DB o a XML e-CF.
 *
 * Motivo (2026-07-13, E310000000014): un retorno de carro (\r) en la descripcion
 * de un item llego hasta el XML firmado como &#13;. El validador de DGII pierde
 * los CR al re-serializar y rechaza con "La firma del XML no es valida" aunque
 * la firma sea correcta. Los XML builders ya normalizan, pero limpiar en la
 * entrada evita que el dato sucio se guarde en primer lugar.
 *
 * Reglas:
 *   - \r\n y \r  -> \n (se conservan los saltos de linea como LF)
 *   - Se eliminan los demas caracteres de control (invalidos en XML 1.0),
 *     excepto \n y \t. Incluye DEL (\x7F).
 *
 * NO usar con XML firmado por terceros (recepcion DGII): alterar un solo byte
 * rompe su firma. Ver IncomingXmlExtractor, que lee el body crudo a proposito.
 */
class InputSanitizer
{
    public static function cleanString(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        // Bytes de control nunca forman parte de secuencias UTF-8 multibyte,
        // asi que el reemplazo bytewise es seguro sin el modificador /u.
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
    }

    /**
     * Limpia recursivamente strings dentro de arrays y objetos (stdClass).
     * Otros tipos (int, float, bool, null) pasan intactos.
     */
    public static function clean($value)
    {
        if (is_string($value)) {
            return self::cleanString($value);
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = self::clean($v);
            }
            return $value;
        }
        if ($value instanceof stdClass) {
            foreach (get_object_vars($value) as $k => $v) {
                $value->{$k} = self::clean($v);
            }
            return $value;
        }
        return $value;
    }

    /**
     * Reemplazo directo de json_decode(file_get_contents('php://input'), $assoc)
     * con sanitizacion incluida. Devuelve null si el body no es JSON valido,
     * igual que json_decode.
     */
    public static function jsonInput(bool $assoc = true)
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return null;
        }
        return self::clean(json_decode($raw, $assoc));
    }
}
