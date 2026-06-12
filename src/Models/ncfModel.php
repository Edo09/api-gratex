<?php
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../AmbienteResolver.php';

class ncfModel
{
    private $conexion;

    public function __construct()
    {
        $this->conexion = Database::getInstance()->getConnection();
    }

    /** Ambiente activo: per-tenant (tenants.ambiente) o global del .env. */
    public function resolveActiveAmbiente(): ?string
    {
        return AmbienteResolver::active();
    }

    public function getCurrentSequence($type = 'B01')
    {
        try {
            $sql = "SELECT * FROM ncf_sequences WHERE type = :type";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([':type' => $type]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return null;
        }
    }

    public function updateSequence($type, $addValue)
    {
        try {
            $current = $this->getCurrentSequence($type);
            if (!$current) {
                return false;
            }
            $newValue = $current['current_value'] + $addValue;
            $sql = "UPDATE ncf_sequences SET current_value = :val WHERE type = :type";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([':val' => $newValue, ':type' => $type]);
            return $this->getCurrentSequence($type);
        } catch (PDOException $e) {
            return false;
        }
    }

    public function setSequence($type, $value)
    {
        try {
            $sql = "UPDATE ncf_sequences SET current_value = :val WHERE type = :type";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([':val' => $value, ':type' => $type]);
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    public function getNextNCF($type = 'B01')
    {
        $seq = $this->getCurrentSequence($type);
        if (!$seq) {
            return null;
        }
        $nextVal = $seq['current_value'] + 1;
        return $type . str_pad($nextVal, 8, '0', STR_PAD_LEFT);
    }

    /**
     * Reserves the next e-NCF for an electronic type (E31..E47) from the ACTIVE
     * authorized range (DGII model: ranges with numero_desde/hasta + vencimiento).
     *
     * Active range = lowest numero_desde with remaining capacity
     * (numero_hasta NULL = unlimited/legacy) and not expired. The increment is
     * atomic AND bounded by numero_hasta, so concurrent calls never exceed the
     * authorization. If a range is exhausted between SELECT and UPDATE, retries
     * with the next range once.
     *
     * @return array|null ['e_ncf','valor','fecha_vencimiento','numero_hasta','restantes'] o null si
     *                    NO hay rango disponible (agotado/vencido: registrar uno nuevo).
     */
    public function dispenseNextECF(string $type, ?string $ambiente = null): ?array
    {
        if (!preg_match('/^E\d{2}$/', $type)) {
            return null;
        }
        $amb = $ambiente ?? $this->resolveActiveAmbiente() ?? 'certecf';
        try {
            for ($intento = 0; $intento < 3; $intento++) {
                $sel = $this->conexion->prepare(
                    'SELECT id, numero_hasta, fecha_vencimiento FROM ncf_sequences
                     WHERE type = :type AND ambiente = :ambiente
                       AND (numero_hasta IS NULL OR current_value < numero_hasta)
                       AND (fecha_vencimiento IS NULL OR fecha_vencimiento >= CURDATE())
                     ORDER BY numero_desde ASC LIMIT 1'
                );
                $sel->execute([':type' => $type, ':ambiente' => $amb]);
                $rango = $sel->fetch(PDO::FETCH_ASSOC);
                if (!$rango) {
                    return null; // sin rango con capacidad vigente
                }

                // Incremento atomico ACOTADO: si otra emision agoto el rango entre
                // el SELECT y este UPDATE, rowCount=0 y se intenta el siguiente.
                $upd = $this->conexion->prepare(
                    'UPDATE ncf_sequences SET current_value = current_value + 1
                     WHERE id = :id AND (numero_hasta IS NULL OR current_value < numero_hasta)'
                );
                $upd->execute([':id' => $rango['id']]);
                if ($upd->rowCount() === 0) {
                    continue;
                }

                $val = $this->conexion->prepare('SELECT current_value FROM ncf_sequences WHERE id = :id');
                $val->execute([':id' => $rango['id']]);
                $current = (int) $val->fetchColumn();

                return [
                    'e_ncf' => $type . str_pad((string) $current, 10, '0', STR_PAD_LEFT),
                    'valor' => $current,
                    'rango_id' => (int) $rango['id'],
                    'fecha_vencimiento' => $rango['fecha_vencimiento'] ?: null,
                    'numero_hasta' => $rango['numero_hasta'] !== null ? (int) $rango['numero_hasta'] : null,
                    'restantes' => $rango['numero_hasta'] !== null
                        ? max(0, (int) $rango['numero_hasta'] - $current)
                        : null,
                ];
            }
            return null;
        } catch (PDOException $e) {
            return null;
        }
    }

    /**
     * Revierte (-1) el contador de un e-CF cuando DGII rechaza SIN consumir la
     * secuencia (secuenciaUtilizada=false, p.ej. codigo 135 "No existen rangos de
     * secuencias disponibles"): asi el mismo e-NCF se reutiliza en el proximo
     * intento. Condicional: solo decrementa si el contador sigue en el valor que
     * se entrego, para no pisar el incremento de otra emision posterior. Devuelve
     * true si revirtio.
     */
    public function rollbackECFSequence(string $type, int $expectedValue, ?string $ambiente = null, ?int $rangoId = null): bool
    {
        if (!preg_match('/^E\d{2}$/', $type) || $expectedValue < 1) {
            return false;
        }
        $amb = $ambiente ?? $this->resolveActiveAmbiente() ?? 'certecf';
        try {
            // Cuando se conoce el rango_id (dispensado por dispenseNextECF), se
            // apunta directamente a esa fila. De lo contrario se usa el filtro
            // clasico type+ambiente (legacy / e_ncf override).
            if ($rangoId !== null) {
                $upd = $this->conexion->prepare(
                    'UPDATE ncf_sequences SET current_value = current_value - 1
                     WHERE id = :id AND current_value = :expected'
                );
                $upd->execute([':id' => $rangoId, ':expected' => $expectedValue]);
            } else {
                $upd = $this->conexion->prepare(
                    'UPDATE ncf_sequences SET current_value = current_value - 1
                     WHERE type = :type AND ambiente = :ambiente AND current_value = :expected'
                );
                $upd->execute([':type' => $type, ':ambiente' => $amb, ':expected' => $expectedValue]);
            }
            if ($upd->rowCount() > 0) {
                return true;
            }
            // No coincidio: deja rastro del estado real del contador para diagnostico
            // (p.ej. el contador avanzo por otra emision, o el ambiente difiere).
            if ($rangoId !== null) {
                $cur = $this->conexion->prepare(
                    'SELECT current_value FROM ncf_sequences WHERE id = :id'
                );
                $cur->execute([':id' => $rangoId]);
            } else {
                $cur = $this->conexion->prepare(
                    'SELECT current_value FROM ncf_sequences WHERE type = :type AND ambiente = :ambiente'
                );
                $cur->execute([':type' => $type, ':ambiente' => $amb]);
            }
            $valores = $cur->fetchAll(PDO::FETCH_COLUMN);
            error_log(sprintf(
                '[NCF] rollbackECFSequence sin coincidencia: type=%s ambiente=%s rango_id=%s esperado=%d current_value(es)=[%s]',
                $type, $amb, $rangoId !== null ? (string) $rangoId : 'null', $expectedValue, implode(',', array_map('intval', $valores))
            ));
            return false;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Lista los rangos e-NCF del ambiente con uso/restantes/estado calculados.
     * estado: activo (dispensando) | pendiente (rango futuro) | agotado | vencido | sin_limite.
     */
    public function listRanges(?string $ambiente = null, ?string $type = null): array
    {
        $amb = $ambiente ?? $this->resolveActiveAmbiente() ?? 'certecf';
        try {
            $sql = "SELECT id, type, prefix, description, ambiente, current_value,
                           numero_desde, numero_hasta, fecha_vencimiento, no_solicitud, no_autorizacion,
                           created_at, updated_at
                    FROM ncf_sequences
                    WHERE type LIKE 'E%' AND ambiente = :amb" . ($type !== null ? ' AND type = :type' : '') . '
                    ORDER BY type, numero_desde';
            $stmt = $this->conexion->prepare($sql);
            $params = [':amb' => $amb];
            if ($type !== null) {
                $params[':type'] = $type;
            }
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $hoy = date('Y-m-d');
            $activoPorTipo = [];
            foreach ($rows as &$r) {
                $cv = (int) $r['current_value'];
                $desde = (int) $r['numero_desde'];
                $hasta = $r['numero_hasta'] !== null ? (int) $r['numero_hasta'] : null;
                $vencido = $r['fecha_vencimiento'] !== null && $r['fecha_vencimiento'] < $hoy;
                $agotado = $hasta !== null && $cv >= $hasta;

                $r['usados'] = max(0, $cv - ($desde - 1));
                $r['restantes'] = $hasta !== null ? max(0, $hasta - $cv) : null;

                if ($vencido) {
                    $r['estado'] = 'vencido';
                } elseif ($agotado) {
                    $r['estado'] = 'agotado';
                } elseif (!isset($activoPorTipo[$r['type']])) {
                    // primer rango con capacidad vigente = el que dispensa ahora
                    $activoPorTipo[$r['type']] = true;
                    $r['estado'] = $hasta === null ? 'sin_limite' : 'activo';
                } else {
                    $r['estado'] = 'pendiente';
                }
            }
            unset($r);
            return $rows;
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Registra un rango autorizado por DGII para un tipo e-CF.
     * Reglas: numero_desde > todo numero ya usado/autorizado del tipo+ambiente
     * (los rangos DGII son consecutivos hacia adelante). Si existia una fila
     * "sin limite" (legacy, numero_hasta NULL) se CIERRA en su consumo actual
     * para que el nuevo rango pase a dispensar.
     *
     * @return array ['success', rango] | ['error', mensaje]
     */
    public function registerRange(
        string $type,
        int $numeroDesde,
        int $numeroHasta,
        string $fechaVencimiento,
        ?string $noSolicitud = null,
        ?string $noAutorizacion = null,
        ?string $ambiente = null
    ): array {
        if (!preg_match('/^E\d{2}$/', $type)) {
            return ['error', 'type invalido: use E31..E47'];
        }
        if ($numeroDesde < 1 || $numeroHasta < $numeroDesde) {
            return ['error', 'Rango invalido: numero_desde >= 1 y numero_hasta >= numero_desde'];
        }
        $venc = DateTime::createFromFormat('Y-m-d', $fechaVencimiento);
        if (!$venc || $venc->format('Y-m-d') !== $fechaVencimiento) {
            return ['error', 'fecha_vencimiento invalida: use formato YYYY-MM-DD'];
        }
        $amb = $ambiente ?? $this->resolveActiveAmbiente() ?? 'certecf';

        try {
            $this->conexion->beginTransaction();

            // Tope ya comprometido del tipo: numeros usados (current_value) y
            // autorizados (numero_hasta) no pueden solaparse con el rango nuevo.
            $stmt = $this->conexion->prepare(
                'SELECT id, current_value, numero_hasta FROM ncf_sequences
                 WHERE type = :type AND ambiente = :amb FOR UPDATE'
            );
            $stmt->execute([':type' => $type, ':amb' => $amb]);
            $existentes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $tope = 0;
            $sinLimite = null;
            foreach ($existentes as $e) {
                $tope = max($tope, (int) $e['current_value'], (int) ($e['numero_hasta'] ?? 0));
                if ($e['numero_hasta'] === null) {
                    $sinLimite = $e;
                }
            }
            if ($numeroDesde <= $tope) {
                $this->conexion->rollBack();
                return ['error', "numero_desde debe ser mayor que {$tope} (ultimo numero usado/autorizado de {$type} en {$amb})"];
            }

            // Cerrar la fila legacy sin limite en su consumo actual.
            if ($sinLimite !== null) {
                $cerrar = $this->conexion->prepare(
                    'UPDATE ncf_sequences SET numero_hasta = GREATEST(current_value, numero_desde - 1) WHERE id = :id'
                );
                $cerrar->execute([':id' => $sinLimite['id']]);
            }

            $ins = $this->conexion->prepare(
                'INSERT INTO ncf_sequences
                    (type, prefix, current_value, numero_desde, numero_hasta, fecha_vencimiento,
                     no_solicitud, no_autorizacion, description, ambiente)
                 VALUES
                    (:type, :type2, :cv, :desde, :hasta, :venc, :sol, :aut, :descr, :amb)'
            );
            $ins->execute([
                ':type' => $type,
                ':type2' => $type,
                ':cv' => $numeroDesde - 1, // ultimo dispensado = ninguno aun
                ':desde' => $numeroDesde,
                ':hasta' => $numeroHasta,
                ':venc' => $fechaVencimiento,
                ':sol' => $noSolicitud !== null && $noSolicitud !== '' ? $noSolicitud : null,
                ':aut' => $noAutorizacion !== null && $noAutorizacion !== '' ? $noAutorizacion : null,
                ':descr' => 'Rango autorizado DGII',
                ':amb' => $amb,
            ]);
            $id = (int) $this->conexion->lastInsertId();
            $this->conexion->commit();

            return ['success', [
                'id' => $id,
                'type' => $type,
                'ambiente' => $amb,
                'numero_desde' => $numeroDesde,
                'numero_hasta' => $numeroHasta,
                'fecha_vencimiento' => $fechaVencimiento,
                'no_solicitud' => $noSolicitud,
                'no_autorizacion' => $noAutorizacion,
                'restantes' => $numeroHasta - ($numeroDesde - 1),
            ]];
        } catch (PDOException $e) {
            if ($this->conexion->inTransaction()) {
                $this->conexion->rollBack();
            }
            if ($e->getCode() === '23000') {
                return ['error', 'Ya existe un rango de ' . $type . ' que inicia en ' . $numeroDesde . ' para ' . $amb];
            }
            return ['error', 'No se pudo registrar el rango'];
        }
    }
}
