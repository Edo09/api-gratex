<?php
/**
 * test_write_ahead_puro.php — Piezas del write-ahead del e-CF que se prueban
 * sin base de datos ni red (spec del write-ahead, 12.3.1).
 *
 * Donde hace falta una conexion se usa una falsa que anota el SQL y los
 * parametros: nunca toca MySQL. Cada tarea del plan agrega su seccion justo
 * encima del bloque de cierre (wa_fin), en orden.
 *
 * Uso:
 *   php tools/test_write_ahead_puro.php      (sale con 1 si algo falla)
 */

require_once __DIR__ . '/write_ahead_test_lib.php';
require_once __DIR__ . '/../src/Utils/FacturacionElectronica/EstadosEcf.php';

// ---------------------------------------------------------------------------
// 1) EstadosEcf: las listas del spec 4.6
// ---------------------------------------------------------------------------
echo "\n== 1) EstadosEcf ==\n";
wa_chk('REINTENTABLES: las tres de hoy mas RFCE_NO_ENCONTRADO',
    EstadosEcf::REINTENTABLES === ['RECHAZADO', 'RFCE_RECHAZADO', 'NO_ENCONTRADO', 'RFCE_NO_ENCONTRADO'], EstadosEcf::REINTENTABLES);
wa_chk('PENDIENTE_ENVIO y ENVIO_INCIERTO',
    EstadosEcf::PENDIENTE_ENVIO === 'PENDIENTE_ENVIO' && EstadosEcf::ENVIO_INCIERTO === 'ENVIO_INCIERTO');
wa_chk('EN_VUELO: PENDIENTE_ENVIO y ENVIO_INCIERTO',
    EstadosEcf::EN_VUELO === ['PENDIENTE_ENVIO', 'ENVIO_INCIERTO'], EstadosEcf::EN_VUELO);
wa_chk('SIN_VEREDICTO: en vuelo + ENVIADO, EN_PROCESO y sus RFCE_',
    EstadosEcf::SIN_VEREDICTO === ['PENDIENTE_ENVIO', 'ENVIO_INCIERTO', 'ENVIADO', 'EN_PROCESO', 'RFCE_ENVIADO', 'RFCE_EN_PROCESO'],
    EstadosEcf::SIN_VEREDICTO);
wa_chk('SIN_STOCK: reintentables + ERROR y RFCE_ERROR',
    EstadosEcf::SIN_STOCK === ['RECHAZADO', 'RFCE_RECHAZADO', 'NO_ENCONTRADO', 'RFCE_NO_ENCONTRADO', 'ERROR', 'RFCE_ERROR'],
    EstadosEcf::SIN_STOCK);
foreach (['REINTENTABLES', 'EN_VUELO', 'SIN_VEREDICTO', 'SIN_STOCK'] as $lista) {
    $valores = constant('EstadosEcf::' . $lista);
    wa_chk("{$lista}: sin repetidos", count(array_unique($valores)) === count($valores), $valores);
}
wa_chk('EN_VUELO esta dentro de SIN_VEREDICTO', array_diff(EstadosEcf::EN_VUELO, EstadosEcf::SIN_VEREDICTO) === []);
wa_chk('REINTENTABLES esta dentro de SIN_STOCK', array_diff(EstadosEcf::REINTENTABLES, EstadosEcf::SIN_STOCK) === []);
wa_chk('una fila en vuelo nunca es reintentable', array_intersect(EstadosEcf::EN_VUELO, EstadosEcf::REINTENTABLES) === []);
wa_chk('una fila en vuelo no esta en SIN_STOCK (no es veredicto)', array_intersect(EstadosEcf::EN_VUELO, EstadosEcf::SIN_STOCK) === []);
wa_chk("sql(['A','B']) === \"'A', 'B'\"", EstadosEcf::sql(['A', 'B']) === "'A', 'B'", EstadosEcf::sql(['A', 'B']));
wa_chk('sql(EN_VUELO) para un IN (...)',
    EstadosEcf::sql(EstadosEcf::EN_VUELO) === "'PENDIENTE_ENVIO', 'ENVIO_INCIERTO'", EstadosEcf::sql(EstadosEcf::EN_VUELO));
$rechaza = static function (array $estados): bool {
    try {
        EstadosEcf::sql($estados);
        return false;
    } catch (InvalidArgumentException $e) {
        return true;
    }
};
wa_chk('sql() rechaza comilla y salto de linea final', $rechaza(["ACEPTADO' OR '1'='1"]) && $rechaza(["ABC\n"]));
wa_chk('sql() rechaza la lista vacia (IN () no es SQL)', $rechaza([]));
$textoClase = (string) file_get_contents(__DIR__ . '/../src/Utils/FacturacionElectronica/EstadosEcf.php');
wa_chk('EstadosEcf no carga otros archivos (sin require ni include)',
    !preg_match('/\b(require|include)(_once)?\b/', $textoClase));
wa_chk('EstadosEcf es final', (new ReflectionClass(EstadosEcf::class))->isFinal());

// ---------------------------------------------------------------------------
// Cierre. Cada tarea agrega su seccion justo encima de este bloque.
// ---------------------------------------------------------------------------
wa_fin();
