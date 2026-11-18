<?php
/**
 * test_clientes_lista_precio.php — la lista de precio por defecto del cliente (clients.lista_precio, 039),
 * sin base de datos: que valores acepta clientModel::listaPrecioDe y que pedazo del UPDATE arma
 * clientModel::cambioDeLista (sin la clave no la nombra, null o '' la quitan, un valor que no vale se ignora).
 * Uso:  php tools/test_clientes_lista_precio.php
 */
require_once __DIR__ . '/write_ahead_test_lib.php';
require_once __DIR__ . '/../src/Models/clientModel.php';

wa_chk('null y vacio: vale y es sin lista', clientModel::listaPrecioDe(null) === [true, null] && clientModel::listaPrecioDe('') === [true, null]);
wa_chk('1 a 4, como entero o como texto', clientModel::listaPrecioDe(1) === [true, 1] && clientModel::listaPrecioDe(4) === [true, 4]
    && clientModel::listaPrecioDe('2') === [true, 2] && clientModel::listaPrecioDe(' 3 ') === [true, 3]);
wa_chk('0, 5, texto, decimal, booleano y arreglo no valen', array_reduce([0, 5, '0', '5', 'x', 2.5, 2.0, true, [], '2.5'],
    fn($ok, $v) => $ok && clientModel::listaPrecioDe($v)[0] === false, true));
// Casos de borde: solo null y '' quitan la lista; un texto de puros espacios, false y un objeto no valen.
wa_chk('solo espacios, false y un objeto no valen (solo null y "" quitan la lista)', array_reduce(['   ', "\t", false, new stdClass()],
    fn($ok, $v) => $ok && clientModel::listaPrecioDe($v)[0] === false, true));
wa_chk('lo que no vale nunca trae una lista: [false, null]', clientModel::listaPrecioDe(5) === [false, null]
    && clientModel::listaPrecioDe('x') === [false, null] && clientModel::listaPrecioDe(true) === [false, null]);

// cambioDeLista: el pedazo del UPDATE de updateClient, [fragmento SQL, parametros]. Sin la clave no la nombra; null y ''
// la quitan; un valor que no vale se ignora como en el alta (el controller lo rechaza antes, esta es la red de seguridad):
// nunca quita la lista guardada.
$quita = [', lista_precio = :lista_precio', [':lista_precio' => null]];
wa_chk('cambioDeLista: sin la clave no cambia nada, vengan o no otras', clientModel::cambioDeLista([]) === ['', []]
    && clientModel::cambioDeLista(['descuento' => 5, 'client_name' => 'Ana']) === ['', []]);
wa_chk('cambioDeLista: null y "" la quitan', clientModel::cambioDeLista(['lista_precio' => null]) === $quita
    && clientModel::cambioDeLista(['lista_precio' => '']) === $quita);
wa_chk('cambioDeLista: 3, "3" y " 4 " la cambian, con su entero', clientModel::cambioDeLista(['lista_precio' => 3]) === [', lista_precio = :lista_precio', [':lista_precio' => 3]]
    && clientModel::cambioDeLista(['lista_precio' => '3']) === [', lista_precio = :lista_precio', [':lista_precio' => 3]]
    && clientModel::cambioDeLista(['lista_precio' => ' 4 ']) === [', lista_precio = :lista_precio', [':lista_precio' => 4]]);
// Antes de cambioDeLista, updateClient le ponia NULL a lo que no valia y la quitaba.
$ignorados = array_map(fn($v) => clientModel::cambioDeLista(['lista_precio' => $v]), [0, 5, 'x', 2.5, true, false, []]);
wa_chk('cambioDeLista: 0, 5, "x", 2.5, true, false y [] se ignoran: nunca quitan la lista', $ignorados === array_fill(0, 7, ['', []]), $ignorados);
wa_fin();
