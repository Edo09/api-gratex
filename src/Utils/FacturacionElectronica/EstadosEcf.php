<?php

/**
 * Listas de estados del e-CF (columna facturas.estado_dgii) que comparten la
 * emision, los modelos y los reportes. Una sola copia de cada lista: antes la
 * de reintentables estaba repetida en ECFEmissionService y en facturaModel.
 *
 * Solo constantes y una funcion pura, sin dependencias: ECFEmissionService la
 * carga tambien en tenants de integracion, que no tienen DB de tenant, y
 * tools/check_estado_dgii_ancho.php la lee para revisar el ancho de la columna.
 *
 * Los estados en vuelo (PENDIENTE_ENVIO, ENVIO_INCIERTO) son del write-ahead:
 * la fila se guarda antes de enviar el e-CF a la DGII y se actualiza con la
 * respuesta (spec del write-ahead del e-CF, secciones 4.3 y 4.6).
 */
final class EstadosEcf
{
    /**
     * Veredictos que dejan reintentar el MISMO numero: la DGII no lo uso. La
     * reserva de un intento nuevo archiva la fila vieja (<estado>_ARCHIVADO,
     * e_ncf NULL) y eNcfYaUsado no salta el numero. RFCE_NO_ENCONTRADO entra
     * para que el flujo RFCE (E32 < 250k) se porte igual que el e-CF. ERROR y
     * RFCE_ERROR quedan fuera: ese numero se quema a proposito.
     */
    public const REINTENTABLES = ['RECHAZADO', 'RFCE_RECHAZADO', 'NO_ENCONTRADO', 'RFCE_NO_ENCONTRADO'];

    /** Fila, XML firmado e items guardados; el envio no empezo o no termino. */
    public const PENDIENTE_ENVIO = 'PENDIENTE_ENVIO';

    /** Se envio (o se intento) y no se sabe si la DGII lo recibio. */
    public const ENVIO_INCIERTO = 'ENVIO_INCIERTO';

    /**
     * En vuelo: la fila ocupa su numero y la app no conoce un veredicto de la
     * DGII. Nunca se archiva sola, no es dinero (stats y reporte de ventas la
     * excluyen) y no mueve inventario.
     */
    public const EN_VUELO = [self::PENDIENTE_ENVIO, self::ENVIO_INCIERTO];

    /**
     * Filtro 'pendiente' del listado: todo estado sin veredicto de la DGII. Una
     * fila que termino en ENVIADO o EN_PROCESO tras perder la respuesta HTTP
     * aparece bajo el mismo filtro al que la manda el mensaje de timeout.
     */
    public const SIN_VEREDICTO = ['PENDIENTE_ENVIO', 'ENVIO_INCIERTO', 'ENVIADO', 'EN_PROCESO', 'RFCE_ENVIADO', 'RFCE_EN_PROCESO'];

    /** Veredictos que nunca mueven inventario: el comprobante no vale. */
    public const SIN_STOCK = ['RECHAZADO', 'RFCE_RECHAZADO', 'NO_ENCONTRADO', 'RFCE_NO_ENCONTRADO', 'ERROR', 'RFCE_ERROR'];

    /**
     * Una lista de esta clase como lista SQL para IN (...): sql(['A', 'B'])
     * devuelve 'A', 'B'. El texto va pegado al SQL sin parametros, asi que solo
     * acepta codigos en mayusculas y guion bajo, como los de arriba.
     *
     * @throws InvalidArgumentException con la lista vacia o un valor que no es un codigo de estado
     */
    public static function sql(array $estados): string
    {
        if ($estados === []) {
            throw new InvalidArgumentException('EstadosEcf::sql: lista vacia');
        }
        foreach ($estados as $estado) {
            if (!is_string($estado) || !preg_match('/^[A-Z][A-Z_]*$/', $estado)) {
                throw new InvalidArgumentException('EstadosEcf::sql: estado no valido');
            }
        }
        return "'" . implode("', '", $estados) . "'";
    }
}
