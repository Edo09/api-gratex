<?php
require_once __DIR__ . '/../MasterDatabase.php';

/**
 * Catálogo DGII de unidades de medida. Vive en el DB MASTER (compartido por
 * todos los tenants), tabla `unidades_medida (id, codigo, descripcion,
 * permite_decimales, activo)`.
 *
 * `permite_decimales` (master_migrations/010) decide si una cantidad puede
 * llevar decimales en esa unidad: 1,5 metros sí, 1,5 "Unidad" no. Mientras la
 * migración no se haya corrido la columna no existe: todo se lee igual y la
 * regla no bloquea (fail-open, como isValid).
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
    /** @var array<int,bool>|null [id => admite decimales] por request; [] = no se sabe. */
    private static ?array $decimalesCache = null;

    public function __construct()
    {
        $this->conexion = MasterDatabase::getInstance()->getConnection();
    }

    /**
     * Unidades activas: [{id, codigo, descripcion, permite_decimales}] ordenadas
     * por id (código DGII). permite_decimales es bool, o null si la master aún no
     * tiene la columna (migración 010 sin correr): el front lo trata como "no se
     * sabe" y no bloquea.
     */
    public function all(): array
    {
        try {
            $stmt = $this->conexion->query(
                'SELECT id, codigo, descripcion, permite_decimales FROM unidades_medida WHERE activo = 1 ORDER BY id'
            );
            $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($filas as &$f) {
                $f['permite_decimales'] = (int) $f['permite_decimales'] === 1;
            }
            unset($f);
            return $filas;
        } catch (PDOException $e) {
            // Sin la columna (master sin migrar): el catálogo de siempre, sin la
            // marca. Si también falla esto, vacío: el selector queda sin opciones
            // en vez de romper la pantalla.
            try {
                $filas = $this->conexion
                    ->query('SELECT id, codigo, descripcion FROM unidades_medida WHERE activo = 1 ORDER BY id')
                    ->fetchAll(PDO::FETCH_ASSOC);
                foreach ($filas as &$f) {
                    $f['permite_decimales'] = null;
                }
                unset($f);
                return $filas;
            } catch (PDOException $e2) {
                error_log('[catalogos] unidades_medida: ' . $e2->getMessage());
                return [];
            }
        }
    }

    /**
     * ¿Una cantidad en esta unidad puede llevar decimales?
     *
     * Fail-open a propósito: si la master no tiene la columna, el catálogo no se
     * pudo leer o el código no está, responde true. Esta regla evita cantidades
     * absurdas ("1,5 unidades"); nunca debe impedir facturar por un fallo de
     * lectura. Código vacío o 0 = no hay unidad que juzgar: true.
     */
    public function permiteDecimales($code): bool
    {
        $code = (int) $code;
        if ($code <= 0) {
            return true;
        }
        if (self::$decimalesCache === null) {
            try {
                $filas = $this->conexion
                    ->query('SELECT id, permite_decimales FROM unidades_medida')
                    ->fetchAll(PDO::FETCH_KEY_PAIR);
                self::$decimalesCache = [];
                foreach ($filas as $id => $permite) {
                    self::$decimalesCache[(int) $id] = (int) $permite === 1;
                }
            } catch (PDOException $e) {
                error_log('[catalogos] unidades_medida.permite_decimales (fail-open): ' . $e->getMessage());
                self::$decimalesCache = [];
            }
        }
        return self::$decimalesCache[$code] ?? true;
    }

    /**
     * Qué está mal en una cantidad, en palabras del usuario, o null si está
     * bien. Mismos textos que el front (fiscalo src/components/unidadesMedida.ts
     * problemaCantidad). $maxDecimales: 2 en lo que va a la DGII (CantidadItem
     * admite 2), 3 en la factura simple y el inventario.
     */
    public function problemaCantidad(float $cantidad, $unidad, int $maxDecimales): ?string
    {
        // Se juzga lo que de verdad se guarda: 0.0001 con 2 decimales queda en 0,
        // y un 0 no puede llegar al comprobante (ni al XML, con el e-NCF ya dado).
        if (!($cantidad > 0) || !(round($cantidad, $maxDecimales) > 0)) {
            return 'La cantidad debe ser mayor que 0.';
        }
        $dec = self::decimalesDe($cantidad);
        if ($dec === 0) {
            return null;
        }
        if (!$this->permiteDecimales($unidad)) {
            $nombre = $this->descripcion($unidad);
            return 'La unidad «' . ($nombre !== '' ? $nombre : 'Unidad')
                . '» no admite fracciones: usa una cantidad entera o cambia la unidad.';
        }
        if ($dec > $maxDecimales) {
            return 'La cantidad admite hasta ' . $maxDecimales . ' decimales.';
        }
        return null;
    }

    /**
     * Cuántos decimales trae un número (0.125 -> 3). La tolerancia es RELATIVA
     * al valor: con una fija (1e-7), cantidades grandes correctas (10115841.63)
     * contaban con decimales de más. Mismo criterio que decimalesDe del front.
     */
    public static function decimalesDe(float $n): int
    {
        if ($n == 0.0) {
            return 0;
        }
        for ($d = 0; $d <= 6; $d++) {
            if (abs($n - round($n, $d)) <= abs($n) * 1e-12) {
                return $d;
            }
        }
        return 6;
    }

    /** Descripción de una unidad para los mensajes ("Unidad", "Caja"), o '' si no se sabe. */
    public function descripcion($code): string
    {
        foreach ($this->all() as $u) {
            if ((int) $u['id'] === (int) $code) {
                return (string) $u['descripcion'];
            }
        }
        return '';
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
                error_log('[catalogos] unidades_medida (validacion, fail-open): ' . $e->getMessage());
                self::$validCache = [];
            }
        }
        return self::$validCache === [] ? true : isset(self::$validCache[$code]);
    }
}
