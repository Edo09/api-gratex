<?php
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../AmbienteResolver.php';
require_once __DIR__ . '/../Utils/FacturacionElectronica/EstadosEcf.php';

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

    /**
     * Nombre del ambiente para los mensajes al usuario. testecf/certecf/ecf son
     * claves internas; los nombres son los mismos que muestra Configuracion en
     * el front (AMBIENTES de SettingsView.tsx).
     */
    private static function ambienteLabel(string $amb): string
    {
        $labels = ['ecf' => 'Producción', 'certecf' => 'Certificación', 'testecf' => 'Pruebas'];
        return $labels[$amb] ?? $amb;
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
     * Expresion SQL del ultimo numero dispensado del rango ACTIVO de un tipo,
     * para los resumenes de secuencias (proximo e-NCF = esto + 1). Elige el
     * rango igual que dispenseNextECF; sin rango vigente cae a
     * MAX(ns.current_value). Va dentro de un SELECT ... FROM ncf_sequences ns
     * GROUP BY ns.type.
     *
     * Con MAX(current_value) a secas, un rango registrado por adelantado ("En
     * cola", arranca en numero_desde - 1) hacia mostrar un proximo e-NCF que
     * no es el que sale.
     */
    public static function sqlSecuenciaActual(PDO $conexion, string $ambiente): string
    {
        $amb = $conexion->quote($ambiente);
        return "COALESCE((SELECT a.current_value FROM ncf_sequences a
                           WHERE a.type = ns.type AND a.ambiente = {$amb}
                             AND (a.numero_hasta IS NULL OR a.current_value < a.numero_hasta)
                             AND (a.fecha_vencimiento IS NULL OR a.fecha_vencimiento >= CURDATE())
                           ORDER BY a.numero_desde ASC LIMIT 1),
                         MAX(ns.current_value))";
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
     * @throws RuntimeException si ncf_sequences no se puede leer o actualizar
     *                    (base caida, tabla ausente). Antes devolvia null y el
     *                    usuario leia "Se acabaron los numeros..." con la base caida.
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
            // null significa "no hay rango"; una base que no responde es otra cosa
            // y sale como error generico (spec 4.8).
            throw new RuntimeException('ncf_sequences inaccesible: ' . $e->getMessage(), 0, $e);
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
            // Sigue devolviendo false (el llamador lo trata como "no se pudo
            // devolver"), pero con rastro: sin esto un fallo de la base se leia
            // igual que "rollback sin coincidencia".
            error_log('[ECF] rollbackECFSequence fallo: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Vuelve a tomar del contador un e-NCF que ya se habia dispensado y se devolvio. El reenvio de
     * un rechazado (POST /api/facturas/{id}/reenviar) sale con el MISMO numero y no pasa por
     * dispenseNextECF: el rechazo sin consumir lo habia devuelto (rollbackECFSequence, valor ->
     * valor - 1) y nada lo volvia a subir. Un reenvio aceptado dejaba el contador un numero atras:
     * el formulario ofrecia otra vez el numero ya usado y "Quedan N" contaba uno de mas (empresa
     * 133454394, 2026-11-20: E310000000001 aceptado con el contador en 0).
     *
     * Es el inverso de rollbackECFSequence: sube el contador del rango que cubre el numero de
     * valor - 1 a valor, y solo si sigue en valor - 1, que es donde lo dejo la devolucion
     * (compare-and-set: no pisa lo que otra emision haya movido).
     *
     * Con el contador en otro valor no se toca nada: mas abajo hay numeros devueltos que
     * dispenseNextECF debe entregar primero; igual o mas arriba, el numero ya esta contado (una
     * venta del POS, que nunca devuelve el suyo, o un hueco que no se pudo devolver).
     *
     * @return array|null ['rango_id' => int, 'avanzo' => bool]; null si no es un e-NCF de la
     *                    secuencia, si ningun rango cubre el numero o si la base fallo (el reenvio
     *                    sale como siempre y queda una linea en el error_log)
     */
    public function retomarECF(string $type, int $valor, ?string $ambiente = null): ?array
    {
        if (!preg_match('/^E\d{2}$/', $type) || $valor < 1) {
            return null;
        }
        $amb = $ambiente ?? $this->resolveActiveAmbiente() ?? 'certecf';
        try {
            // El mismo orden que dispenseNextECF: el rango de menor numero_desde que cubre el numero.
            $sel = $this->conexion->prepare(
                'SELECT id FROM ncf_sequences
                 WHERE type = :type AND ambiente = :ambiente
                   AND numero_desde <= :desde AND (numero_hasta IS NULL OR numero_hasta >= :hasta)
                 ORDER BY numero_desde ASC LIMIT 1'
            );
            $sel->execute([':type' => $type, ':ambiente' => $amb, ':desde' => $valor, ':hasta' => $valor]);
            $rangoId = $sel->fetchColumn();
            if ($rangoId === false) {
                return null;
            }
            $upd = $this->conexion->prepare(
                'UPDATE ncf_sequences SET current_value = :valor
                 WHERE id = :id AND current_value = :anterior'
            );
            $upd->execute([':valor' => $valor, ':id' => (int) $rangoId, ':anterior' => $valor - 1]);
            return ['rango_id' => (int) $rangoId, 'avanzo' => $upd->rowCount() > 0];
        } catch (PDOException $e) {
            // El reenvio no se detiene por esto: sale como antes de retomarECF, con el contador sin subir.
            error_log('[ECF] retomarECF fallo: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Numero SIGUIENTE al ultimo usado de un tipo y ambiente cuando ningun rango
     * vigente tiene numeros libres (dispenseNextECF devolvio null): el POS no se
     * detiene por la secuencia (spec 4.2.6). Incrementa de forma atomica el
     * current_value del rango de mayor numero_desde SIN el tope numero_hasta, asi
     * que el contador pasa del final autorizado. La DGII rechaza los numeros
     * fuera de un rango autorizado: quien lo use guarda la venta con la meta
     * sin_rango y NO la envia hasta que rangoCubre() sea true.
     *
     * Mismas claves que dispenseNextECF mas 'sin_rango' => true. rango_id es null
     * (ningun rango autorizado es dueno del numero), numero_hasta y
     * fecha_vencimiento son los del ultimo rango y restantes es 0. El valor se
     * lee con LAST_INSERT_ID(expr) en la misma sentencia y conexion: dos ventas a
     * la vez nunca reciben el mismo numero.
     *
     * @return array|null null si el tipo no es E\d\d o el tipo no tiene ninguna
     *                    fila de rango en ese ambiente (no hay de donde seguir)
     * @throws RuntimeException si ncf_sequences no se puede leer o actualizar
     */
    public function dispenseSinRango(string $type, string $ambiente): ?array
    {
        if (!preg_match('/^E\d{2}$/', $type)) {
            return null;
        }
        try {
            $sel = $this->conexion->prepare(
                'SELECT id, numero_hasta, fecha_vencimiento FROM ncf_sequences
                 WHERE type = :type AND ambiente = :ambiente
                 ORDER BY numero_desde DESC LIMIT 1'
            );
            $sel->execute([':type' => $type, ':ambiente' => $ambiente]);
            $rango = $sel->fetch(PDO::FETCH_ASSOC);
            if (!$rango) {
                return null;
            }
            $upd = $this->conexion->prepare(
                'UPDATE ncf_sequences SET current_value = LAST_INSERT_ID(current_value + 1) WHERE id = :id'
            );
            $upd->execute([':id' => $rango['id']]);
            $current = (int) $this->conexion->lastInsertId();
            if ($current < 1) {
                throw new RuntimeException('el contador no devolvio el numero nuevo');
            }
            return [
                'e_ncf' => $type . str_pad((string) $current, 10, '0', STR_PAD_LEFT),
                'valor' => $current,
                'rango_id' => null,
                'fecha_vencimiento' => $rango['fecha_vencimiento'] ?: null,
                'numero_hasta' => $rango['numero_hasta'] !== null ? (int) $rango['numero_hasta'] : null,
                'restantes' => 0,
                'sin_rango' => true,
            ];
        } catch (PDOException $e) {
            throw new RuntimeException('ncf_sequences inaccesible: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * true si el numero $valor de un tipo y ambiente cae dentro de un rango
     * autorizado registrado y vigente: numero_desde <= valor <= numero_hasta
     * (numero_hasta NULL = sin limite) y sin vencer. Decide si una venta guardada
     * fuera de rango (meta sin_rango) ya se puede enviar a la DGII (spec 4.3.5).
     * Ante un fallo de la base devuelve false: con la duda no se envia.
     */
    public function rangoCubre(string $type, string $ambiente, int $valor): bool
    {
        if (!preg_match('/^E\d{2}$/', $type) || $valor < 1) {
            return false;
        }
        try {
            $st = $this->conexion->prepare(
                'SELECT 1 FROM ncf_sequences
                 WHERE type = :type AND ambiente = :ambiente
                   AND numero_desde <= :desde AND (numero_hasta IS NULL OR numero_hasta >= :hasta)
                   AND (fecha_vencimiento IS NULL OR fecha_vencimiento >= CURDATE())
                 LIMIT 1'
            );
            $st->execute([':type' => $type, ':ambiente' => $ambiente, ':desde' => $valor, ':hasta' => $valor]);
            return $st->fetchColumn() !== false;
        } catch (PDOException $e) {
            error_log('[NCF] rangoCubre fallo: ' . $e->getMessage());
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
     * Numeros usados fuera de rango (dispenseSinRango, venta del POS con el rango
     * agotado): el contador del ultimo rango paso de su numero_hasta. Eso no
     * cuenta como tope: el rango nuevo puede cubrirlos, arranca DESPUES del
     * ultimo que cae dentro de el (para que dispenseNextECF no reparta un numero
     * ya usado) y el rango viejo vuelve a su numero_hasta.
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
            return ['error', 'El tipo de comprobante no es válido. Elige uno de la lista.'];
        }
        if ($numeroDesde < 1 || $numeroHasta < $numeroDesde) {
            return ['error', 'El rango no es válido: el número inicial debe ser 1 o mayor, y el número final no puede ser menor que el inicial.'];
        }
        $venc = DateTime::createFromFormat('Y-m-d', $fechaVencimiento);
        if (!$venc || $venc->format('Y-m-d') !== $fechaVencimiento) {
            return ['error', 'La fecha de vencimiento no es válida. Elígela en el calendario.'];
        }
        $amb = $ambiente ?? $this->resolveActiveAmbiente() ?? 'certecf';
        $ambLabel = self::ambienteLabel($amb);

        try {
            $this->conexion->beginTransaction();

            // Tope ya comprometido del tipo: numeros usados (current_value) y
            // autorizados (numero_hasta) no pueden solaparse con el rango nuevo.
            $stmt = $this->conexion->prepare(
                'SELECT id, current_value, numero_desde, numero_hasta FROM ncf_sequences
                 WHERE type = :type AND ambiente = :amb FOR UPDATE'
            );
            $stmt->execute([':type' => $type, ':amb' => $amb]);
            $existentes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $sinLimite = null;
            foreach ($existentes as $e) {
                if ($e['numero_hasta'] === null) {
                    $sinLimite = $e;
                }
            }

            // La fila "sin limite" sembrada por db/tenant_schema.sql toma el default
            // numero_desde = 1, asi que YA OCUPA el inicio del primer rango real que
            // la DGII autoriza (que casi siempre empieza en 1). Insertar otra fila
            // con ese mismo numero_desde choca con uq_type_amb_desde (error 23000:
            // "Ya existe un rango que inicia en 1") y deja imposible registrar el
            // primer rango de un tenant nuevo. En ese caso no hay rango duplicado
            // que rechazar: hay que CONVERTIR la fila existente en el rango
            // autorizado, que es lo mismo que hizo a mano la migracion 014 para
            // Gratex (por eso alli nunca se noto).
            $convertir = $sinLimite !== null && (int) $sinLimite['numero_desde'] === $numeroDesde;

            // El consumo de la fila que se va a convertir NO cuenta como tope: ese
            // consumo pertenece justamente al rango que se esta formalizando. Sin
            // esta exclusion, un tenant que ya emitio en produccion antes de
            // registrar su rango quedaba bloqueado por su propio contador.
            $tope = 0;
            foreach ($existentes as $e) {
                if ($convertir && (int) $e['id'] === (int) $sinLimite['id']) {
                    continue;
                }
                // Con numero_hasta manda el final autorizado: un current_value MAYOR son
                // numeros usados fuera de rango, que el rango nuevo puede cubrir. Sin
                // numero_hasta (legacy) manda el consumo.
                $tope = max($tope, $e['numero_hasta'] !== null ? (int) $e['numero_hasta'] : (int) $e['current_value']);
            }
            if ($numeroDesde <= $tope) {
                $this->conexion->rollBack();
                return ['error', "El número inicial debe ser mayor que {$tope}, que es el último número de {$type} ya usado o autorizado en {$ambLabel}."
                    . ' Revisa el rango que te autorizó la DGII.'];
            }

            if ($convertir) {
                // Se conserva lo ya dispensado; si la fila no habia dispensado nada
                // (current_value 0 en un tenant nuevo) arranca en numero_desde - 1.
                $cv = max((int) $sinLimite['current_value'], $numeroDesde - 1);
                if ($cv > $numeroHasta) {
                    $this->conexion->rollBack();
                    return ['error', "Ya se emitieron comprobantes {$type} hasta el número {$cv} en {$ambLabel}, y el rango"
                        . " {$numeroDesde}–{$numeroHasta} que intentas registrar termina antes."
                        . ' Revisa los números del rango que te autorizó la DGII.'];
                }
                $upd = $this->conexion->prepare(
                    'UPDATE ncf_sequences
                        SET numero_hasta = :hasta, fecha_vencimiento = :venc,
                            no_solicitud = :sol, no_autorizacion = :aut,
                            current_value = :cv, description = :descr
                      WHERE id = :id'
                );
                $upd->execute([
                    ':hasta' => $numeroHasta,
                    ':venc' => $fechaVencimiento,
                    ':sol' => $noSolicitud !== null && $noSolicitud !== '' ? $noSolicitud : null,
                    ':aut' => $noAutorizacion !== null && $noAutorizacion !== '' ? $noAutorizacion : null,
                    ':cv' => $cv,
                    ':descr' => 'Rango autorizado DGII',
                    ':id' => $sinLimite['id'],
                ]);
                $this->conexion->commit();

                return ['success', [
                    'id' => (int) $sinLimite['id'],
                    'type' => $type,
                    'ambiente' => $amb,
                    'numero_desde' => $numeroDesde,
                    'numero_hasta' => $numeroHasta,
                    'fecha_vencimiento' => $fechaVencimiento,
                    'no_solicitud' => $noSolicitud,
                    'no_autorizacion' => $noAutorizacion,
                    'restantes' => $numeroHasta - $cv,
                ]];
            }

            // Numeros usados fuera de rango: el rango nuevo arranca despues del ultimo que
            // cae dentro de el y los rangos viejos vuelven a su numero_hasta.
            $sobrepaso = 0;
            foreach ($existentes as $e) {
                if ($e['numero_hasta'] !== null && (int) $e['current_value'] > (int) $e['numero_hasta']) {
                    $sobrepaso = max($sobrepaso, (int) $e['current_value']);
                }
            }
            $cvNuevo = max($numeroDesde - 1, min($sobrepaso, $numeroHasta));
            if ($sobrepaso > 0) {
                $this->conexion->prepare(
                    'UPDATE ncf_sequences SET current_value = numero_hasta
                      WHERE type = :type AND ambiente = :amb AND numero_hasta IS NOT NULL AND current_value > numero_hasta'
                )->execute([':type' => $type, ':amb' => $amb]);
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
                ':cv' => $cvNuevo, // ultimo dispensado: ninguno aun, o el ultimo numero fuera de rango que cae aqui
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
                'restantes' => $numeroHasta - $cvNuevo,
            ]];
        } catch (PDOException $e) {
            if ($this->conexion->inTransaction()) {
                $this->conexion->rollBack();
            }
            if ($e->getCode() === '23000') {
                return ['error', 'Ya hay un rango de ' . $type . ' registrado en ' . $ambLabel . ' que empieza en '
                    . $numeroDesde . '. Revisa la lista de rangos y registra el nuevo a partir de donde termina el último.'];
            }
            error_log('[ncfModel] registerRange ' . $type . ' ' . $numeroDesde . '-' . $numeroHasta . ': ' . $e->getMessage());
            return ['error', 'No se pudo registrar el rango. Inténtalo de nuevo.'];
        }
    }

    /**
     * Corrige un rango autorizado ya registrado (docs/specs/ncf-rangos-edicion.md). Cambios parciales:
     * fecha_vencimiento (Y-m-d), no_solicitud y no_autorizacion (hasta 20 caracteres; vacio = sin dato),
     * numero_hasta y numero_desde. Nunca el tipo, el ambiente ni el contador: un cambio de "Desde"
     * (solo sin numeros usados) deja el contador en Desde - 1.
     *
     * Todo ocurre en una transaccion con los rangos del tipo bloqueados (FOR UPDATE): el dispensador
     * no mueve el contador mientras se revisa. "Ultimo usado" = el mayor entre el contador y el mayor
     * e-NCF emitido dentro del rango, en facturas y, para los tipos de gastos (E41/E43/E47), en gastos,
     * sin los estados reintentables (el criterio de ECFEmissionService::eNcfYaUsado): el contador manda
     * y lo emitido cubre un contador atrasado, como lo dejaba el reenvio de un rechazado hasta 2026-11-20.
     *
     * @return array ['success', ['rango' => fila de listRanges, 'cambios' => [columna => ['antes', 'despues']]]]
     *               | ['notfound', mensaje] | ['error', mensaje]
     */
    public function updateRange(int $id, array $cambios, ?string $ambiente = null): array
    {
        foreach (['type', 'ambiente', 'current_value'] as $prohibida) {
            if (array_key_exists($prohibida, $cambios)) {
                return ['error', 'El tipo, el ambiente y el contador de un rango no se pueden cambiar.'];
            }
        }
        // Lo que vino en el cuerpo, ya normalizado: solo columnas que se pueden cambiar. Nada toca la base todavia.
        $nuevos = [];
        if (array_key_exists('fecha_vencimiento', $cambios)) {
            $fecha = is_string($cambios['fecha_vencimiento']) ? trim($cambios['fecha_vencimiento']) : '';
            $venc = DateTime::createFromFormat('Y-m-d', $fecha);
            if (!$venc || $venc->format('Y-m-d') !== $fecha) {
                return ['error', 'La fecha de vencimiento no es válida. Elígela en el calendario.'];
            }
            $nuevos['fecha_vencimiento'] = $fecha;
        }
        foreach (['no_solicitud' => 'solicitud', 'no_autorizacion' => 'autorización'] as $col => $nombre) {
            if (!array_key_exists($col, $cambios)) {
                continue;
            }
            $valor = $cambios[$col];
            if ($valor !== null && !is_string($valor) && !is_int($valor)) {
                return ['error', "El número de {$nombre} no es válido."];
            }
            $valor = trim((string) $valor);
            if (mb_strlen($valor) > 20) {
                return ['error', "El número de {$nombre} no puede tener más de 20 caracteres."];
            }
            $nuevos[$col] = $valor === '' ? null : $valor;
        }
        foreach (['numero_desde' => 'inicial', 'numero_hasta' => 'final'] as $col => $nombre) {
            if (!array_key_exists($col, $cambios)) {
                continue;
            }
            $n = self::enteroPositivo($cambios[$col]);
            if ($n === null) {
                return ['error', "El número {$nombre} tiene que ser un número entero de 1 o más."];
            }
            $nuevos[$col] = $n;
        }

        $amb = $ambiente ?? $this->resolveActiveAmbiente() ?? 'certecf';
        $ambLabel = self::ambienteLabel($amb);
        $type = '';
        try {
            $st = $this->conexion->prepare(
                "SELECT type FROM ncf_sequences WHERE id = :id AND ambiente = :ambiente AND type LIKE 'E%'"
            );
            $st->execute([':id' => $id, ':ambiente' => $amb]);
            $type = $st->fetchColumn();
            if ($type === false) {
                return ['notfound', 'No encontramos ese rango en el ambiente activo.'];
            }
            $type = (string) $type;

            // Desde aqui, todo error de regla cierra la transaccion antes de responder.
            $abortar = function (string $mensaje): array {
                if ($this->conexion->inTransaction()) {
                    $this->conexion->rollBack();
                }
                return ['error', $mensaje];
            };
            $this->conexion->beginTransaction();
            $sel = $this->conexion->prepare(
                'SELECT id, type, current_value, numero_desde, numero_hasta, fecha_vencimiento, no_solicitud, no_autorizacion
                   FROM ncf_sequences
                  WHERE type = :type AND ambiente = :ambiente
                  ORDER BY numero_desde FOR UPDATE'
            );
            $sel->execute([':type' => $type, ':ambiente' => $amb]);
            $filas = $sel->fetchAll(PDO::FETCH_ASSOC);

            $r = null;
            foreach ($filas as $f) {
                if ((int) $f['id'] === $id) {
                    $r = $f;
                }
            }
            if ($r === null) {
                $this->conexion->rollBack();
                return ['notfound', 'No encontramos ese rango en el ambiente activo.'];
            }
            if ($r['numero_hasta'] === null) {
                return $abortar('Este tipo no tiene un rango registrado: usa «Registrar rango aprobado» para formalizarlo.');
            }
            $desdeActual = (int) $r['numero_desde'];
            $hastaActual = (int) $r['numero_hasta'];
            // Vecinos por numero_desde: el rango anterior y el siguiente del mismo tipo y ambiente.
            $previo = null;
            $siguiente = null;
            foreach ($filas as $f) {
                if ((int) $f['id'] === $id) {
                    continue;
                }
                $d = (int) $f['numero_desde'];
                if ($d < $desdeActual) {
                    if ($previo === null || $d > (int) $previo['numero_desde']) {
                        $previo = $f;
                    }
                } elseif ($siguiente === null || $d < (int) $siguiente['numero_desde']) {
                    $siguiente = $f;
                }
            }

            // Solo cuenta como cambio lo que difiere de lo guardado (valores normalizados).
            $actual = [
                'fecha_vencimiento' => $r['fecha_vencimiento'] !== null ? (string) $r['fecha_vencimiento'] : null,
                'no_solicitud' => $r['no_solicitud'] !== null && $r['no_solicitud'] !== '' ? (string) $r['no_solicitud'] : null,
                'no_autorizacion' => $r['no_autorizacion'] !== null && $r['no_autorizacion'] !== '' ? (string) $r['no_autorizacion'] : null,
                'numero_desde' => $desdeActual,
                'numero_hasta' => $hastaActual,
            ];
            $reales = [];
            foreach ($nuevos as $col => $v) {
                if ($v !== $actual[$col]) {
                    $reales[$col] = $v;
                }
            }
            if ($reales === []) {
                $this->conexion->rollBack();
                return ['success', ['rango' => $this->rangoListado($amb, $type, $id) ?? $r, 'cambios' => []]];
            }

            if (isset($reales['numero_desde']) || isset($reales['numero_hasta'])) {
                $desde = $reales['numero_desde'] ?? $desdeActual;
                $hasta = $reales['numero_hasta'] ?? $hastaActual;
                $usado = max(
                    (int) $r['current_value'],
                    $this->ultimoNumeroEmitido($type, $amb, $desdeActual, $siguiente !== null ? (int) $siguiente['numero_desde'] : null)
                );
                if (isset($reales['numero_desde'])) {
                    if ($usado >= $desdeActual) {
                        return $abortar("Este rango ya tiene números usados (hasta el {$usado}): el número inicial no se puede cambiar.");
                    }
                    if ($previo !== null) {
                        $tope = $previo['numero_hasta'] !== null ? (int) $previo['numero_hasta'] : (int) $previo['current_value'];
                        if ($desde <= $tope) {
                            return $abortar("El número inicial debe ser mayor que {$tope}, que es el último número de {$type} ya usado o autorizado en el rango anterior.");
                        }
                    }
                    if ($desde > $hasta) {
                        return $abortar("El número inicial ({$desde}) no puede ser mayor que el número final ({$hasta}).");
                    }
                }
                if (isset($reales['numero_hasta'])) {
                    if ($hasta < $desde) {
                        return $abortar("El número final ({$hasta}) no puede ser menor que el número inicial ({$desde}).");
                    }
                    if ($hasta < $usado) {
                        return $abortar("El número final no puede ser menor que {$usado}, el último número de {$type} ya usado.");
                    }
                    if ($siguiente !== null && $hasta >= (int) $siguiente['numero_desde']) {
                        $sigDesde = (int) $siguiente['numero_desde'];
                        $sigHasta = $siguiente['numero_hasta'] !== null ? (int) $siguiente['numero_hasta'] : '∞';
                        return $abortar("El número final tiene que ser menor que {$sigDesde}, donde empieza el siguiente rango ({$type} {$sigDesde}–{$sigHasta}).");
                    }
                }
            }

            // Las columnas del SET salen de $reales, que ya solo trae las del permiso de arriba.
            $set = [];
            $params = [':id' => $id];
            $antesDespues = [];
            foreach ($reales as $col => $v) {
                $set[] = "{$col} = :{$col}";
                $params[':' . $col] = $v;
                $antesDespues[$col] = ['antes' => $actual[$col], 'despues' => $v];
            }
            $contador = null;
            if (isset($reales['numero_desde'])) {
                // Sin usados (comprobado arriba): el contador arranca en el nuevo Desde - 1.
                $contador = $reales['numero_desde'] - 1;
                $set[] = 'current_value = :current_value';
                $params[':current_value'] = $contador;
                $antesDespues['current_value'] = ['antes' => (int) $r['current_value'], 'despues' => $contador];
            }
            $upd = $this->conexion->prepare('UPDATE ncf_sequences SET ' . implode(', ', $set) . ' WHERE id = :id');
            $upd->execute($params);
            $this->conexion->commit();

            $rango = $this->rangoListado($amb, $type, $id)
                ?? ($reales + ($contador !== null ? ['current_value' => $contador] : []) + $r);
            return ['success', ['rango' => $rango, 'cambios' => $antesDespues]];
        } catch (PDOException $e) {
            if ($this->conexion->inTransaction()) {
                $this->conexion->rollBack();
            }
            if (($e->errorInfo[0] ?? (string) $e->getCode()) === '23000' && isset($nuevos['numero_desde'])) {
                return ['error', 'Ya hay un rango de ' . $type . ' registrado en ' . $ambLabel . ' que empieza en '
                    . $nuevos['numero_desde'] . '. Revisa la lista de rangos.'];
            }
            error_log('[ncfModel] updateRange ' . $id . ': ' . $e->getMessage());
            return ['error', 'No se pudo guardar el cambio del rango. Inténtalo de nuevo.'];
        }
    }

    /** La fila de listRanges de un rango ya guardado, o null si no esta (listRanges devuelve [] si la base falla). */
    private function rangoListado(string $amb, string $type, int $id): ?array
    {
        foreach ($this->listRanges($amb, $type) as $fila) {
            if ((int) $fila['id'] === $id) {
                return $fila;
            }
        }
        return null;
    }

    /**
     * Mayor numero de e-NCF de $type emitido en $amb desde $desde (y antes de $limite, el Desde del rango
     * siguiente, si lo hay) que no este en un estado reintentable. 0 si no hay ninguno. Se lee en facturas
     * y en gastos: E41/E43/E47 los emite el modulo de gastos y su e-NCF vive en gastos.ncf, no en facturas.
     * De gastos solo cuentan los que la empresa emite (es_auto_emision = 1): los recibidos traen el NCF del
     * proveedor, que no sale de nuestra secuencia. Mismo criterio que ECFEmissionService::eNcfYaUsado: una
     * fila sin ambiente cuenta; un gasto rechazado que se reusa queda archivado con ncf NULL y no cuenta.
     */
    private function ultimoNumeroEmitido(string $type, string $amb, int $desde, ?int $limite): int
    {
        $fuentes = [
            ['tabla' => 'facturas', 'ncf' => 'e_ncf', 'ambiente' => 'ambiente_dgii', 'filtro' => ''],
            ['tabla' => 'gastos', 'ncf' => 'ncf', 'ambiente' => 'ambiente', 'filtro' => ' AND es_auto_emision = 1'],
        ];
        $mayor = 0;
        foreach ($fuentes as $f) {
            // Tabla y columnas salen de la lista fija de arriba; los valores van como parametros.
            $numero = "CAST(SUBSTRING({$f['ncf']}, 4) AS UNSIGNED)";
            $sql = "SELECT MAX({$numero}) FROM {$f['tabla']}
                     WHERE {$f['ncf']} LIKE :patron{$f['filtro']} AND ({$f['ambiente']} = :ambiente OR {$f['ambiente']} IS NULL)
                       AND estado_dgii NOT IN (" . EstadosEcf::sql(EstadosEcf::REINTENTABLES) . ")
                       AND {$numero} >= :desde" . ($limite !== null ? " AND {$numero} < :limite" : '');
            $params = [':patron' => $type . '%', ':ambiente' => $amb, ':desde' => $desde];
            if ($limite !== null) {
                $params[':limite'] = $limite;
            }
            $st = $this->conexion->prepare($sql);
            $st->execute($params);
            $mayor = max($mayor, (int) $st->fetchColumn());
        }
        return $mayor;
    }

    /** Entero de 1 a 2147483647 (INT con signo de MySQL) desde un int, un texto de digitos o un float entero; null si no lo es. */
    private static function enteroPositivo(mixed $valor): ?int
    {
        if (is_string($valor) && preg_match('/^\s*\d{1,10}\s*$/', $valor)) {
            $valor = (int) trim($valor);
        } elseif (is_float($valor) && is_finite($valor) && floor($valor) == $valor && abs($valor) < 1e10) {
            $valor = (int) $valor;
        }
        return is_int($valor) && $valor >= 1 && $valor <= 2147483647 ? $valor : null;
    }
}
