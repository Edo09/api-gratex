<?php
require_once(__DIR__ . '/TenantResolver.php');

/**
 * TenantEntitlements — que incluye la edicion de una empresa (Fiscalpoint Express).
 *
 * master.tenants.edicion (migracion 014) es 'completa' (la app de siempre, sin restricciones) o 'express'. Esta clase
 * resuelve, para una empresa Express, lo que su fila le da: los modulos (el preset de config/editions.php angostado por
 * modulos_habilitados), los tipos de e-CF (tipos_ecf_habilitados), los permisos que de ahi salen y el bloque que se le
 * entrega al cliente al iniciar sesion.
 *
 * Reglas del archivo:
 *   - Todo es estatico y no hay namespace. Incluirlo no imprime nada: se carga en medio de una peticion que contesta
 *     JSON.
 *   - Las peticiones solo la cargan despues de una prueba en linea que ya probo la empresa Express: para una empresa
 *     completa, una de integracion o ninguna, esta clase no se incluye jamas.
 *   - Las funciones de modulos, tipos, permisos y bloque son funciones PURAS de la fila: no preguntan la edicion (eso lo
 *     hace quien las llama, con esRestringido) y un $tenant null significa la empresa resuelta
 *     (TenantResolver::current()).
 *   - Un token mal escrito en las listas de la fila angosta y nunca ensancha: lo que no se reconoce no suma nada.
 */
final class TenantEntitlements
{
    /** Tipo que se deriva de cualquier tipo de venta: la nota de credito, con la que se anula una factura. */
    private const TIPO_ANULACION = '34';

    /** Tipo que solo cuenta si la empresa lo trae en su lista: la nota de debito. No tiene pantalla. */
    private const TIPO_OPCIONAL = '33';

    /** Token de permiso de los catalogos compartidos (unidades de medida, consulta de RNC, provincias). */
    private const PERMISO_CATALOGOS = 'unidades';

    /** Modulos cuyas pantallas leen esos catalogos: con cualquiera habilitado, el token va en los permisos. */
    private const MODULOS_CON_CATALOGOS = ['facturacion', 'clientes', 'productos'];

    private static ?array $config = null;

    /**
     * Valores de edicion desconocidos que ya escribieron su linea de log en este proceso.
     *
     * @var array<string,true>
     */
    private static array $edicionesAvisadas = [];

    /** config/editions.php: se lee con require una sola vez por proceso. */
    public static function config(): array
    {
        return self::$config ??= require __DIR__ . '/../config/editions.php';
    }

    /**
     * Lista separada por comas -> valores sin espacios, sin vacios y sin repetidos, en el orden en que aparecen.
     * Los operadores escriben estas listas a mano ("45, 31," con espacios y coma final): no deben angostar por eso.
     * No toca mayusculas ni corrige tokens: lo que no coincide con el catalogo exacto no suma nada.
     *
     * @return string[]
     */
    public static function csv(?string $valor): array
    {
        return array_values(array_unique(array_filter(
            array_map('trim', explode(',', (string) $valor)),
            static fn (string $item): bool => $item !== ''
        )));
    }

    /**
     * 'express' si la edicion de la fila, sin espacios y en minusculas, es exactamente express; 'completa' en
     * cualquier otro caso.
     *
     * Un valor presente que no es ni completa ni express (el vacio incluido: es lo que MySQL guarda en un ENUM cuando
     * se le da un valor invalido sin modo estricto) se trata como completa y escribe UNA linea en el log por valor
     * distinto y por proceso. Una columna ausente (master sin migrar) o sin fila es lo de siempre: no escribe nada.
     */
    public static function normalizarEdicion(?array $tenant): string
    {
        $valor = $tenant['edicion'] ?? null;
        if ($valor === null) {
            return 'completa';
        }
        $edicion = strtolower(trim((string) $valor));
        if ($edicion === 'express') {
            return 'express';
        }
        if ($edicion !== 'completa') {
            self::avisarEdicionDesconocida((string) $valor);
        }
        return 'completa';
    }

    /**
     * ¿Es una empresa Express? Solo una de tipo app cuya edicion normaliza a express: una de integracion (sin base
     * propia ni pantallas) nunca lo es, y la fila ausente o sin la columna tampoco.
     *
     * Es la misma prueba que las peticiones hacen en linea antes de cargar esta clase.
     */
    public static function esRestringido(?array $tenant): bool
    {
        return ($tenant['tipo'] ?? 'app') === 'app' && self::normalizarEdicion($tenant) === 'express';
    }

    /** ¿Es Express la empresa resuelta en esta peticion? */
    public static function restringido(): bool
    {
        return self::esRestringido(TenantResolver::current());
    }

    /**
     * Modulos de la empresa, en el orden del preset. modulos_habilitados vacio (NULL, '' o solo separadores) es el preset
     * completo; una lista se cruza con el preset: lo que no esta en el preset (o esta mal escrito) cae, y el resultado
     * puede quedar vacio.
     *
     * @return string[]
     */
    public static function modulosEfectivos(?array $tenant = null): array
    {
        $tenant ??= TenantResolver::current();
        $preset = self::config()['modulos'];
        $lista = self::csv($tenant['modulos_habilitados'] ?? null);
        return $lista === [] ? $preset : array_values(array_intersect($preset, $lista));
    }

    /**
     * Tipos de VENTA de la empresa (31, 32, 45), en orden canonico y no en el de la lista. Lista vacia o sin ninguno de
     * los tres: [] (cerrado, no "todos").
     *
     * @return string[]
     */
    public static function tiposVenta(?array $tenant = null): array
    {
        $tenant ??= TenantResolver::current();
        $listados = self::csv($tenant['tipos_ecf_habilitados'] ?? null);
        return array_values(array_intersect(self::config()['tipos_venta'], $listados));
    }

    /**
     * Todos los tipos de e-CF que la empresa puede emitir: sus tipos de venta, el 34 derivado cuando tiene alguno, y el 33
     * solo si lo trae en su lista (siempre al final). Es lo que el login entrega como tipos_ecf.
     *
     * @return string[]
     */
    public static function tiposEfectivos(?array $tenant = null): array
    {
        $tenant ??= TenantResolver::current();
        $tipos = self::tiposVenta($tenant);
        if ($tipos !== []) {
            $tipos[] = self::TIPO_ANULACION;
        }
        if (in_array(self::TIPO_OPCIONAL, self::csv($tenant['tipos_ecf_habilitados'] ?? null), true)) {
            $tipos[] = self::TIPO_OPCIONAL;
        }
        return $tipos;
    }

    /**
     * Permisos del rol cruzados con los tokens que la empresa tiene habilitados (un token por modulo efectivo, segun
     * config 'permisos', y el compartido 'unidades' si hay facturacion, clientes o productos). '*' se expande a todos
     * los habilitados; el resultado sale en el orden de esos tokens, no en el del rol.
     *
     * Es la lista de permisos que el login entrega al cliente. Los modulos los aplica de verdad la guarda de rutas, no
     * esta lista: el gate lee los permisos crudos del rol y el administrador tiene '*'.
     *
     * @param string[] $permisosRol
     * @return string[]
     */
    public static function permisosEfectivos(array $permisosRol, ?array $tenant = null): array
    {
        $modulos = self::modulosEfectivos($tenant);
        $habilitados = [];
        foreach (self::config()['permisos'] as $modulo => $token) {
            if (in_array($modulo, $modulos, true)) {
                $habilitados[] = $token;
            }
        }
        if (array_intersect(self::MODULOS_CON_CATALOGOS, $modulos) !== []) {
            $habilitados[] = self::PERMISO_CATALOGOS;
        }
        if (in_array('*', $permisosRol, true)) {
            return $habilitados;
        }
        return array_values(array_intersect($habilitados, $permisosRol));
    }

    /**
     * El bloque user.entitlements que el login y /me entregan a una empresa Express. Se arma campo por campo con una
     * lista blanca: la fila de tenants lleva certificado, claves y secretos y jamas se serializa.
     *
     * @return array{edicion:string,tenant:array{id:int,nombre:string,rnc:string,ambiente:string},modulos:string[],tipos_ecf:string[],capacidades:array<string,bool>}
     */
    public static function bloque(array $tenant): array
    {
        return [
            'edicion' => self::normalizarEdicion($tenant),
            'tenant' => [
                'id' => (int) ($tenant['id'] ?? 0),
                'nombre' => (string) ($tenant['nombre'] ?? ''),
                'rnc' => (string) ($tenant['rnc'] ?? ''),
                'ambiente' => (string) ($tenant['ambiente'] ?? ''),
            ],
            'modulos' => self::modulosEfectivos($tenant),
            'tipos_ecf' => self::tiposEfectivos($tenant),
            'capacidades' => self::config()['CAPACIDADES'],
        ];
    }

    /**
     * Una linea de log por valor de edicion distinto y por proceso. El valor entra recortado y entre comillas, con los
     * saltos de linea escapados: quien edite la columna no puede partir ni inventar lineas del log.
     */
    private static function avisarEdicionDesconocida(string $valor): void
    {
        if (isset(self::$edicionesAvisadas[$valor])) {
            return;
        }
        self::$edicionesAvisadas[$valor] = true;
        $literal = json_encode(
            substr($valor, 0, 64),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
        error_log('[TenantEntitlements] edicion desconocida ' . $literal . ' en master.tenants.edicion: se trata como completa');
    }
}
