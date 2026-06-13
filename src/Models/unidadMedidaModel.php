<?php
require_once __DIR__ . '/../MasterDatabase.php';

/**
 * Catálogo DGII de unidades de medida. Vive en el DB MASTER (compartido por
 * todos los tenants), tabla `unidades_medida (id, codigo, descripcion, activo)`.
 *
 * IMPORTANTE: el `id` ES el código numérico DGII (id 43 = Unidad, 21 = KG…) que
 * va en <UnidadMedida> del XML; `codigo` (UND/KG/CM) y `descripcion` son solo
 * para mostrar. Ver samples/e-CF 31 v.1.0.xsd (UnidadMedidaType = xs:integer).
 */
class unidadMedidaModel
{
    private $conexion;
    /** @var array<int,bool>|null Cache de códigos válidos por request. */
    private static ?array $validCache = null;

    public function __construct()
    {
        $this->conexion = MasterDatabase::getInstance()->getConnection();
    }

    /** Unidades activas: [{id, codigo, descripcion}] ordenadas por id (código DGII). */
    public function all(): array
    {
        try {
            $stmt = $this->conexion->query(
                'SELECT id, codigo, descripcion FROM unidades_medida WHERE activo = 1 ORDER BY id'
            );
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }

    /** Mapa [id => codigo] para etiquetar (ej. en la Representación Impresa). */
    public function codigoMap(): array
    {
        $map = [];
        foreach ($this->all() as $u) {
            $map[(int) $u['id']] = $u['codigo'];
        }
        return $map;
    }

    /**
     * ¿`$code` es un código DGII válido (id activo del catálogo)?
     * Fail-open: si el catálogo no se pudo leer, NO bloquea la emisión (la
     * validación final la hace el XSD de la DGII).
     */
    public function isValid($code): bool
    {
        $code = (int) $code;
        if ($code <= 0) {
            return false;
        }
        if (self::$validCache === null) {
            try {
                $ids = $this->conexion
                    ->query('SELECT id FROM unidades_medida WHERE activo = 1')
                    ->fetchAll(PDO::FETCH_COLUMN);
                self::$validCache = array_fill_keys(array_map('intval', $ids), true);
            } catch (PDOException $e) {
                self::$validCache = [];
            }
        }
        return self::$validCache === [] ? true : isset(self::$validCache[$code]);
    }
}
