<?php
require_once __DIR__ . '/../MasterDatabase.php';

/**
 * Catálogo DGII de provincias / municipios / distritos (ProvinciaMunicipioType).
 * Vive en el DB MASTER (compartido por todos los tenants), tabla
 * `dgii_provincia_municipio (codigo CHAR(6), provincia_codigo CHAR(2),
 *  descripcion, tipo ENUM('PROVINCIA','MUNICIPIO','DISTRITO'))`.
 *
 * El `codigo` de 6 dígitos (PPMMDD) ES lo que va en <Municipio>/<Provincia> del
 * XML (y sus variantes *Comprador). Ver samples/e-CF 31 v.1.0.xsd.
 * Seed: tools/migration_provincia_municipio.sql (582 filas).
 */
class provinciaMunicipioModel
{
    private $conexion;
    /** @var array<string,bool>|null Cache de códigos válidos por request. */
    private static ?array $validCache = null;
    /** @var array<string,array<string,string>>|null [tipo => [nombreNorm => codigo]]. */
    private static ?array $nameCache = null;

    public function __construct()
    {
        $this->conexion = MasterDatabase::getInstance()->getConnection();
    }

    /**
     * Catálogo (para selectores). Filtros opcionales:
     *   'tipo'      => PROVINCIA|MUNICIPIO|DISTRITO
     *   'provincia' => código de 2 dígitos (agrupa por provincia)
     * @return array<int,array{codigo:string,provincia_codigo:string,descripcion:string,tipo:string}>
     */
    public function all(array $filters = []): array
    {
        $sql = 'SELECT codigo, provincia_codigo, descripcion, tipo FROM dgii_provincia_municipio';
        $where = [];
        $params = [];
        if (!empty($filters['tipo'])) {
            $where[] = 'tipo = ?';
            $params[] = strtoupper((string) $filters['tipo']);
        }
        if (!empty($filters['provincia'])) {
            $where[] = 'provincia_codigo = ?';
            $params[] = str_pad((string) $filters['provincia'], 2, '0', STR_PAD_LEFT);
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY codigo';
        try {
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * ¿`$code` es un código válido del catálogo? Fail-open: si el catálogo no se
     * pudo leer NO bloquea (la validación final la hace el XSD de la DGII).
     */
    public function isValid($code): bool
    {
        $code = trim((string) $code);
        if ($code === '') {
            return false;
        }
        if (self::$validCache === null) {
            try {
                $codes = $this->conexion
                    ->query('SELECT codigo FROM dgii_provincia_municipio')
                    ->fetchAll(PDO::FETCH_COLUMN);
                self::$validCache = array_fill_keys($codes, true);
            } catch (PDOException $e) {
                self::$validCache = [];
            }
        }
        return self::$validCache === [] ? true : isset(self::$validCache[$code]);
    }

    /**
     * Resuelve un valor (código YA válido o NOMBRE libre) al código DGII de 6
     * dígitos. Si ya es código válido lo devuelve tal cual; si es un nombre que
     * matchea el catálogo devuelve su código; si no hay match devuelve el valor
     * ORIGINAL sin modificar (fail-open — no rompe la emisión; lo valida el XSD).
     *
     * @param string $preferTipo PROVINCIA|MUNICIPIO — desempata nombres que
     *   existen en varios niveles (ej. "SANTIAGO" provincia vs municipio).
     */
    public function resolve($value, string $preferTipo = ''): string
    {
        $value = trim((string) $value);
        if ($value === '' || $this->isValid($value)) {
            return $value;
        }
        $key = $this->normalize($value);
        if ($key === '') {
            return $value;
        }
        $map = $this->nameMap();
        $preferTipo = strtoupper($preferTipo);
        if ($preferTipo !== '' && isset($map[$preferTipo][$key])) {
            return $map[$preferTipo][$key];
        }
        foreach (['MUNICIPIO', 'PROVINCIA', 'DISTRITO'] as $t) {
            if (isset($map[$t][$key])) {
                return $map[$t][$key];
            }
        }
        return $value;
    }

    /** Mapa [tipo => [nombreNormalizado => codigo]] para resolver nombres. */
    private function nameMap(): array
    {
        if (self::$nameCache !== null) {
            return self::$nameCache;
        }
        $out = ['PROVINCIA' => [], 'MUNICIPIO' => [], 'DISTRITO' => []];
        foreach ($this->all() as $row) {
            $t = $row['tipo'];
            $key = $this->normalize($row['descripcion']);
            if ($key !== '' && !isset($out[$t][$key])) {
                $out[$t][$key] = $row['codigo'];
            }
        }
        self::$nameCache = $out;
        return $out;
    }

    /**
     * Normaliza un nombre para comparar: sin acentos, MAYÚSCULAS, sin los
     * prefijos "PROVINCIA "/"MUNICIPIO " ni el sufijo "(D. M.)", espacios
     * colapsados. "Municipio Santiago", "santiago" y "SANTIAGO" → misma clave.
     */
    private function normalize(string $s): string
    {
        $s = strtr(trim($s), [
            'á'=>'A','é'=>'E','í'=>'I','ó'=>'O','ú'=>'U','ü'=>'U','ñ'=>'N',
            'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N',
        ]);
        $s = strtoupper($s);
        $s = preg_replace('/\(D\.?\s*M\.?\)\.?/', ' ', $s);      // sufijo distrito municipal
        $s = preg_replace('/^(PROVINCIA|MUNICIPIO)\s+/', '', $s); // prefijo de nivel
        $s = preg_replace('/[^A-Z0-9 ]+/', ' ', $s);
        $s = preg_replace('/\s+/', ' ', $s);
        return trim($s);
    }
}
