<?php
require_once(__DIR__ . '/../Database.php');
// FerreteriaConduce.php tambien incluye este archivo: require_once corta el
// ciclo, y ninguno de los dos usa al otro al cargarse, solo en sus metodos.
require_once(__DIR__ . '/../Utils/Cotizacion/FerreteriaConduce.php');

/**
 * Conduces de mercancía de Ferretería: las tablas conduces, conduce_items y
 * conduce_secuencia de la migración 029 (spec 2026-10-05-conduces-design, 3 y 4).
 *
 * Nada se borra de la base de datos. Eliminar pone conduces.activo = 0, y
 * editar pone activo = 0 a las líneas de antes e inserta las nuevas. Como las
 * filas se quedan, un número de conduce, una vez dado, no se vuelve a usar.
 *
 * Las lecturas no atrapan los errores de la DB, a propósito: un fallo no puede
 * leerse como "no hay conduces" ni como "este conduce ya no existe" (un PUT
 * respondería 404 por un conduce que sí está). conduceController los atrapa y
 * responde el error genérico. Las escrituras sí: devuelven
 * ['error', mensaje, http], con el detalle técnico en el error_log.
 */
class conduceModel
{
    public const MSG_GUARDAR = 'No se pudo guardar el conduce. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.';
    public const MSG_ACTUALIZAR = 'No se pudieron guardar los cambios del conduce. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.';
    public const MSG_ELIMINAR = 'No se pudo eliminar el conduce. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.';

    /**
     * Columnas de la fila (spec 4.1). conduces.client_name sale como
     * client_name_guardado: client_name, company_name y rnc son los del cliente
     * de hoy (LEFT JOIN, NULL si lo borraron), y el guardado queda para ese caso.
     */
    private const COLUMNAS = 'c.id, c.numero, c.code, c.date, c.cotizacion_id, q.code AS cotizacion_code,
                c.client_id, cl.client_name, cl.company_name, cl.rnc, c.client_name AS client_name_guardado,
                c.user_id, c.activo, c.created_at, c.updated_at';

    private const DESDE = 'FROM conduces c
           LEFT JOIN cotizaciones q ON q.id = c.cotizacion_id
           LEFT JOIN clients cl ON cl.id = c.client_id';

    /** Búsqueda del listado: número, nombre guardado, nombre y empresa del cliente, y RNC. */
    private const BUSQUEDA = '(c.code LIKE :query OR c.client_name LIKE :query OR cl.client_name LIKE :query
                OR cl.company_name LIKE :query OR cl.rnc LIKE :query)';

    private $conexion;

    public function __construct()
    {
        $this->conexion = Database::getInstance()->getConnection();
    }

    /**
     * Una página de conduces activos, los más recientes primero, cada uno con
     * sus líneas activas en 'items'. c.id DESC desempata los de la misma fecha:
     * sin él, el orden entre ellos lo elegía MySQL y podía cambiar de una página
     * a otra (como en cotizaciones).
     */
    public function listar(int $offset, int $limit, ?string $query): array
    {
        $buscar = self::textoBuscado($query);
        $stmt = $this->conexion->prepare(
            'SELECT ' . self::COLUMNAS . ' ' . self::DESDE
            . ' WHERE c.activo = 1' . ($buscar !== null ? ' AND ' . self::BUSQUEDA : '')
            . ' ORDER BY c.date DESC, c.id DESC LIMIT :limit OFFSET :offset'
        );
        if ($buscar !== null) {
            $stmt->bindValue(':query', '%' . $buscar . '%', PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $this->conLineas($stmt->fetchAll());
    }

    /** Cuántos conduces activos hay, con la misma búsqueda que listar. */
    public function contar(?string $query): int
    {
        $buscar = self::textoBuscado($query);
        $stmt = $this->conexion->prepare(
            'SELECT COUNT(*) AS total FROM conduces c LEFT JOIN clients cl ON cl.id = c.client_id'
            . ' WHERE c.activo = 1' . ($buscar !== null ? ' AND ' . self::BUSQUEDA : '')
        );
        $stmt->execute($buscar !== null ? [':query' => '%' . $buscar . '%'] : []);
        return (int) $stmt->fetchColumn();
    }

    /** El conduce $id con sus líneas activas, o null si no existe o está eliminado (activo = 0). */
    public function obtener(int $id): ?array
    {
        $stmt = $this->conexion->prepare(
            'SELECT ' . self::COLUMNAS . ' ' . self::DESDE . ' WHERE c.id = :id AND c.activo = 1'
        );
        $stmt->execute([':id' => $id]);
        $fila = $stmt->fetch();
        if (!$fila) {
            return null;
        }
        return $this->conLineas([$fila])[0];
    }

    /**
     * La cotización de la que sale un conduce nuevo: su código para el PDF y su
     * formato, que tiene que ser 'ferreteria' (lo decide FerreteriaConduce).
     *
     * @return array{id:int, code:string, formato:?string}|null null si no existe
     */
    public function cotizacionDeOrigen(int $cotizacionId): ?array
    {
        $stmt = $this->conexion->prepare('SELECT id, code, formato FROM cotizaciones WHERE id = :id');
        $stmt->execute([':id' => $cotizacionId]);
        $fila = $stmt->fetch();
        if (!$fila) {
            return null;
        }
        return [
            'id' => (int) $fila['id'],
            'code' => (string) $fila['code'],
            'formato' => $fila['formato'] ?? null,
        ];
    }

    /**
     * Crea un conduce: número, cabecera y líneas en una sola transacción (spec 4.2).
     *
     * Si aun así el número choca con uk_conduces_numero (la red de seguridad),
     * se deshace y se repite UNA vez en una transacción nueva, que vuelve a leer
     * la secuencia y el MAX. Cualquier otro error no se reintenta.
     *
     * @param array $cot 'cot' de FerreteriaConduce::validarForma, ya con el catálogo
     *                   aplicado. date null = ahora.
     * @return array ['success', ['id'=>int,'code'=>string,'numero'=>int]] | ['error', string, int $http]
     */
    public function crear(array $cot, ?int $userId, string $clientName): array
    {
        $fecha = $cot['date'] ?? FerreteriaFormato::ahoraRd();
        $intento = 0;
        while (true) {
            $intento++;
            $numero = null;
            try {
                $this->sembrarSecuencia();
                $this->conexion->beginTransaction();
                $numero = $this->reservarNumero();
                $code = FerreteriaConduce::codigo($numero);
                $stmt = $this->conexion->prepare(
                    'INSERT INTO conduces (numero, code, date, cotizacion_id, client_id, client_name, user_id)
                     VALUES (:numero, :code, :date, :cotizacion_id, :client_id, :client_name, :user_id)'
                );
                $stmt->execute([
                    ':numero' => $numero,
                    ':code' => $code,
                    ':date' => $fecha,
                    ':cotizacion_id' => (int) $cot['cotizacion_id'],
                    ':client_id' => (int) $cot['client_id'],
                    ':client_name' => $clientName,
                    ':user_id' => $userId,
                ]);
                $id = (int) $this->conexion->lastInsertId();
                $this->insertarLineas($id, $cot['items']);
                $this->conexion->commit();
                return ['success', ['id' => $id, 'code' => $code, 'numero' => $numero]];
            } catch (PDOException $e) {
                // inTransaction: el fallo puede venir de la semilla, antes del
                // beginTransaction, y un rollBack sin transacción lanza otra excepción.
                if ($this->conexion->inTransaction()) {
                    $this->conexion->rollBack();
                }
                if ($intento === 1 && self::esNumeroRepetido($e)) {
                    error_log('[conduces] el numero ' . $numero . ' ya estaba tomado (uk_conduces_numero): se reintenta una vez');
                    continue;
                }
                error_log('[conduces] no se pudo guardar el conduce: ' . $e->getMessage());
                return self::errorAlGuardar($e, self::MSG_GUARDAR);
            }
        }
    }

    /**
     * Reescribe un conduce: la cabecera, y sus líneas activas pasan a activo = 0
     * antes de insertar las nuevas (no se borra nada). numero, code y
     * cotizacion_id no cambian nunca; $cot['date'] null conserva la fecha y la
     * hora guardadas (COALESCE, como las cotizaciones).
     *
     * @return array mismo formato que crear(); ['error', MSG_NO_EXISTE, 404] si no existe o está eliminado
     */
    public function actualizar(int $id, array $cot, ?int $userId, string $clientName): array
    {
        try {
            $this->conexion->beginTransaction();
            // FOR UPDATE: si otra persona lo elimina entre la carga del
            // controller y este guardado, se sabe aquí y no se escriben líneas
            // en un conduce eliminado. De paso trae numero y code para la respuesta.
            $stmt = $this->conexion->prepare('SELECT code, numero FROM conduces WHERE id = :id AND activo = 1 FOR UPDATE');
            $stmt->execute([':id' => $id]);
            $actual = $stmt->fetch();
            if (!$actual) {
                $this->conexion->rollBack();
                return ['error', FerreteriaConduce::MSG_NO_EXISTE, 404];
            }
            $this->conexion->prepare(
                'UPDATE conduces
                    SET client_id = :client_id, client_name = :client_name, date = COALESCE(:date, date),
                        user_id = :user_id, updated_at = :updated_at
                  WHERE id = :id'
            )->execute([
                ':id' => $id,
                ':client_id' => (int) $cot['client_id'],
                ':client_name' => $clientName,
                ':date' => $cot['date'],
                ':user_id' => $userId,
                ':updated_at' => FerreteriaFormato::ahoraRd(),
            ]);
            // Las líneas de antes se quedan, inactivas: así se puede ver qué
            // decía el conduce antes de cada edición.
            $this->conexion->prepare('UPDATE conduce_items SET activo = 0 WHERE conduce_id = :id AND activo = 1')
                ->execute([':id' => $id]);
            $this->insertarLineas($id, $cot['items']);
            $this->conexion->commit();
            return ['success', ['id' => $id, 'code' => (string) $actual['code'], 'numero' => (int) $actual['numero']]];
        } catch (PDOException $e) {
            if ($this->conexion->inTransaction()) {
                $this->conexion->rollBack();
            }
            error_log('[conduces] no se pudo actualizar el conduce ' . $id . ': ' . $e->getMessage());
            return self::errorAlGuardar($e, self::MSG_ACTUALIZAR);
        }
    }

    /**
     * Eliminar: activo = 0 y nada más. Las líneas se quedan como están, unidas
     * al conduce inactivo. 0 filas afectadas = no existe o ya estaba eliminado.
     *
     * @return array ['success', 'Conduce eliminado'] | ['error', string, int $http]
     */
    public function desactivar(int $id): array
    {
        try {
            $stmt = $this->conexion->prepare('UPDATE conduces SET activo = 0, updated_at = :updated_at WHERE id = :id AND activo = 1');
            $stmt->execute([':id' => $id, ':updated_at' => FerreteriaFormato::ahoraRd()]);
            if ($stmt->rowCount() === 0) {
                return ['error', FerreteriaConduce::MSG_NO_EXISTE, 404];
            }
            return ['success', 'Conduce eliminado'];
        } catch (PDOException $e) {
            error_log('[conduces] no se pudo eliminar el conduce ' . $id . ': ' . $e->getMessage());
            return ['error', self::MSG_ELIMINAR, 500];
        }
    }

    /**
     * La fila de conduce_secuencia, por si la semilla de la 029 no se corrió.
     * IGNORE no toca una fila que ya está.
     *
     * Va fuera de la transacción a propósito: con la fila ya presente, InnoDB
     * le pone a ese INSERT un candado compartido que, dentro de la transacción,
     * duraría hasta el commit. Dos guardados a la vez tendrían cada uno el suyo
     * y los dos esperarían al otro en el FOR UPDATE de reservarNumero (deadlock
     * 1213). Suelto, el candado se suelta al terminar la sentencia.
     */
    private function sembrarSecuencia(): void
    {
        $this->conexion->prepare('INSERT IGNORE INTO conduce_secuencia (id, ultimo) VALUES (1, 0)')->execute();
    }

    /**
     * El número del conduce nuevo, dentro de la transacción de crear (spec 4.2).
     *
     * El FOR UPDATE sobre la única fila de conduce_secuencia hace esperar a otro
     * guardado hasta el commit (o el rollback) de este: los números salen de uno
     * en uno sin GET_LOCK. MAX(numero) cuenta TODAS las filas, activas o no, y
     * cubre una secuencia atrasada (la fila se volvió a sembrar en 0, o alguien
     * insertó un conduce a mano): con los dos, un número dado no se repite.
     */
    private function reservarNumero(): int
    {
        $stmt = $this->conexion->prepare('SELECT ultimo FROM conduce_secuencia WHERE id = 1 FOR UPDATE');
        $stmt->execute();
        $ultimo = (int) $stmt->fetchColumn();
        $stmt = $this->conexion->prepare('SELECT COALESCE(MAX(numero), 0) FROM conduces');
        $stmt->execute();
        $numero = max($ultimo, (int) $stmt->fetchColumn()) + 1;
        $this->conexion->prepare('UPDATE conduce_secuencia SET ultimo = :numero WHERE id = 1')->execute([':numero' => $numero]);
        return $numero;
    }

    /**
     * Las líneas de un conduce, dentro de la transacción de quien llama. amount
     * y los indicadores son internos (para facturar): el conduce no los
     * imprime. El round() de cantidad y precio solo limpia el ruido binario:
     * validarForma ya rechazó lo que tuviera más decimales, así que no cambia
     * ningún valor (en 8.3 ni en 8.5).
     */
    private function insertarLineas(int $conduceId, array $items): void
    {
        $stmt = $this->conexion->prepare(
            'INSERT INTO conduce_items
                (conduce_id, product_id, description, quantity, unidad_medida, amount,
                 indicador_facturacion, indicador_bien_servicio)
             VALUES
                (:conduce_id, :product_id, :description, :quantity, :unidad_medida, :amount,
                 :indicador_facturacion, :indicador_bien_servicio)'
        );
        foreach (array_values($items) as $item) {
            $stmt->execute([
                ':conduce_id' => $conduceId,
                ':product_id' => !empty($item['product_id']) ? (int) $item['product_id'] : null,
                ':description' => (string) $item['description'],
                ':quantity' => round((float) $item['quantity'], 2),
                ':unidad_medida' => (string) $item['unidad_medida'],
                ':amount' => round((float) $item['amount'], 4),
                ':indicador_facturacion' => (int) $item['indicador_facturacion'],
                ':indicador_bien_servicio' => (int) $item['indicador_bien_servicio'],
            ]);
        }
    }

    /**
     * Cada fila con sus líneas activas en 'items', en el orden en que se
     * escribieron (el de la pantalla y el PDF). Una sola consulta para toda la
     * página, no una por conduce.
     */
    private function conLineas(array $filas): array
    {
        if (!$filas) {
            return [];
        }
        $ids = array_map(static fn(array $f): int => (int) $f['id'], $filas);
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->conexion->prepare(
            "SELECT id, conduce_id, product_id, description, quantity, unidad_medida, amount,
                    indicador_facturacion, indicador_bien_servicio, activo
               FROM conduce_items
              WHERE conduce_id IN ({$marcas}) AND activo = 1
              ORDER BY id ASC"
        );
        $stmt->execute($ids);
        $porConduce = [];
        foreach ($stmt->fetchAll() as $linea) {
            $porConduce[(int) $linea['conduce_id']][] = $linea;
        }
        foreach ($filas as &$fila) {
            $fila['items'] = $porConduce[(int) $fila['id']] ?? [];
        }
        unset($fila);
        return $filas;
    }

    /** El texto de la búsqueda sin espacios en los extremos; null si no hay nada que buscar. */
    private static function textoBuscado(?string $query): ?string
    {
        $q = trim((string) $query);
        return $q === '' ? null : $q;
    }

    /** ¿El error es el número repetido (uk_conduces_numero)? Es el único que se reintenta. */
    private static function esNumeroRepetido(PDOException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === 1062
            && stripos((string) ($e->errorInfo[2] ?? $e->getMessage()), 'uk_conduces_numero') !== false;
    }

    /**
     * Mensaje para el usuario de un fallo al guardar. El detalle técnico ya fue
     * al log; aquí solo se distingue lo que el usuario puede arreglar.
     */
    private static function errorAlGuardar(PDOException $e, string $generico): array
    {
        $codigo = (int) ($e->errorInfo[1] ?? 0);
        $detalle = (string) ($e->errorInfo[2] ?? $e->getMessage());
        if ($codigo === 1452 && stripos($detalle, 'conduce_items_product_fk') !== false) {
            // Borraron el producto del catálogo entre la revisión
            // (getProductosInfo) y el INSERT.
            return ['error', FerreteriaConduce::MSG_PRODUCTO_FK, 422];
        }
        if ($codigo === 1452 && stripos($detalle, 'conduces_cotizacion_fk') !== false) {
            // Borraron la cotización de origen entre la revisión
            // (cotizacionDeOrigen) y el INSERT.
            return ['error', FerreteriaConduce::MSG_COTIZACION_FK, 422];
        }
        if (self::esNumeroRepetido($e)) {
            // Segundo choque seguido con el número: dos guardados a la vez.
            return ['error', FerreteriaConduce::MSG_CHOQUE, 500];
        }
        return ['error', $generico, 500];
    }
}
