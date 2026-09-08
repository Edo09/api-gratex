<?php
require_once __DIR__ . '/../MasterDatabase.php';

/**
 * Catálogo DGII "Tipo de Bienes y Servicios Comprados" — el campo 3 del
 * Formato 606. Vive en el DB MASTER (compartido por todos los tenants), tabla
 * `dgii_tipo_bienes_servicios (codigo, descripcion, activo)`.
 *
 * El `codigo` de 2 dígitos ('01'..'11') ES lo que se estampa en el TXT del 606,
 * así que se guarda y se transporta como CADENA: '01' no es 1. Un cast a int en
 * el camino convierte '01' en '1' y la DGII rechaza el archivo.
 *
 * Seed: tools/migration_tipo_bienes_servicios.sql
 */
class tipoBienesServiciosModel
{
    /** Código por defecto del histórico anterior a este campo (ver Reporte606Model). */
    const CODIGO_DEFAULT = '09';

    private $conexion;
    /** @var array<string,bool>|null Cache de códigos válidos por request. */
    private static ?array $validCache = null;

    public function __construct()
    {
        $this->conexion = MasterDatabase::getInstance()->getConnection();
    }

    /** Tipos activos: [{codigo, descripcion}] ordenados por código. */
    public function all(): array
    {
        try {
            $stmt = $this->conexion->query(
                'SELECT codigo, descripcion FROM dgii_tipo_bienes_servicios
                 WHERE activo = 1 ORDER BY codigo'
            );
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Normaliza a la forma que exige la DGII: 2 dígitos con cero a la izquierda.
     * Acepta '1', 1 o '01' y devuelve '01'. Cadena vacía/null -> null, que el
     * llamador trata como "no elegido".
     */
    public static function normalizar($valor): ?string
    {
        if ($valor === null) {
            return null;
        }
        $limpio = preg_replace('/\D/', '', (string) $valor);
        if ($limpio === '' || (int) $limpio <= 0) {
            return null;
        }
        return str_pad($limpio, 2, '0', STR_PAD_LEFT);
    }

    /**
     * ¿`$codigo` es un código válido del catálogo?
     *
     * Fail-open a propósito, igual que unidadMedidaModel::isValid: si el
     * catálogo no se pudo leer (master caído, migración sin correr) NO se
     * bloquea el registro del gasto. Prefiero un 606 con un código a revisar
     * que impedirle a alguien anotar una compra por un problema nuestro.
     */
    public function isValid($codigo): bool
    {
        $codigo = self::normalizar($codigo);
        if ($codigo === null) {
            return false;
        }
        if (self::$validCache === null) {
            try {
                $codigos = $this->conexion
                    ->query('SELECT codigo FROM dgii_tipo_bienes_servicios WHERE activo = 1')
                    ->fetchAll(PDO::FETCH_COLUMN);
                self::$validCache = array_fill_keys($codigos, true);
            } catch (PDOException $e) {
                self::$validCache = [];
            }
        }
        return self::$validCache === [] ? true : isset(self::$validCache[$codigo]);
    }
}
