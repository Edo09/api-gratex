<?php
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Utils/EmisorEdicion.php';

class EmisorConfigModel
{
    private $conexion;

    public function __construct()
    {
        $this->conexion = Database::getInstance()->getConnection();
    }

    public function get(): ?array
    {
        $stmt = $this->conexion->prepare('SELECT * FROM emisor_config WHERE id = 1');
        $stmt->execute();
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Guarda los datos de la empresa que el administrador cambio (UPDATE de la fila unica).
     *
     * Solo escribe las columnas de EmisorEdicion::EDITABLES: el RNC y la razon social no se
     * tocan aunque el arreglo los traiga. $campos ya viene validado y normalizado
     * (EmisorEdicion::validar); aqui no se vuelve a decidir nada.
     *
     * El UPDATE y la lectura de vuelta van en una transaccion: si la fila no quedo como se
     * pidio (p. ej. una columna mas corta de lo que dice el esquema, con MySQL sin modo
     * estricto, recortaria el texto en silencio) se deshace y se lanza la excepcion, en vez
     * de dar por guardado algo distinto.
     *
     * @param array<string, ?string> $campos columna => valor (null deja la columna en blanco).
     * @return array la fila ya guardada, leida de nuevo.
     * @throws RuntimeException si la fila no existe o no quedo como se pidio.
     */
    public function update(array $campos): array
    {
        $campos = array_intersect_key($campos, array_flip(EmisorEdicion::EDITABLES));
        if ($campos === []) {
            $fila = $this->get();
            if ($fila === null) {
                throw new RuntimeException('emisor_config no tiene la fila 1');
            }
            return $fila;
        }
        $sets = [];
        $params = [];
        foreach ($campos as $columna => $valor) {
            // $columna sale de la lista fija de arriba: nunca del cliente.
            $sets[] = "`{$columna}` = :{$columna}";
            $params[':' . $columna] = $valor;
        }
        $this->conexion->beginTransaction();
        try {
            $this->conexion->prepare('UPDATE emisor_config SET ' . implode(', ', $sets) . ' WHERE id = 1')->execute($params);
            $fila = $this->get();
            if ($fila === null) {
                throw new RuntimeException('emisor_config no tiene la fila 1');
            }
            foreach ($campos as $columna => $valor) {
                if (($fila[$columna] ?? null) !== $valor) {
                    throw new RuntimeException("emisor_config.{$columna} no quedo como se pidio");
                }
            }
            $this->conexion->commit();
            return $fila;
        } catch (Throwable $e) {
            if ($this->conexion->inTransaction()) {
                $this->conexion->rollBack();
            }
            throw $e;
        }
    }
}
