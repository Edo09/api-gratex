<?php
require_once __DIR__ . '/FerreteriaFormato.php';
require_once __DIR__ . '/CotizacionFormatos.php';

/**
 * Conduce de mercancía de Ferretería: la nota de entrega que va con la
 * mercancía y que el cliente firma ("Recibido por"). Sale de una cotización de
 * Ferretería, lleva su propio número (CON-000001) y se imprime como la
 * cotización, pero sin precios ni totales (spec 2026-10-05-conduces-design).
 *
 * Las funciones estáticas son las reglas puras: la forma del cuerpo, el
 * número, quién puede usar conduces y lo que recibe el PDF. No tocan la base de
 * datos, para que tools/test_conduces.php las pruebe por CLI.
 *
 * Cada línea se revisa con las reglas y los textos de la cotización
 * (FerreteriaFormato::normalizarLinea), con una sola diferencia: el precio
 * puede ir en 0. El conduce no lo imprime; lo guarda para facturar, y la
 * factura no deja emitir una línea sin precio.
 */
final class FerreteriaConduce
{
    public const PREFIJO = 'CON-';
    public const MSG_NO_DISPONIBLE = 'Los conduces no están disponibles para tu empresa.';
    public const MSG_SIN_CLIENTE = 'Elige un cliente para el conduce.';
    public const MSG_SIN_LINEAS = 'Agrega al menos una línea al conduce.';
    public const MSG_FECHA = 'La fecha no es válida.';
    public const MSG_AJUSTES = 'Los conduces no llevan cargos ni abonos.';
    public const MSG_COTIZACION = 'Elige una cotización de Ferretería para crear el conduce.';
    public const MSG_NO_EXISTE = 'Este conduce ya no existe. Puede que lo hayan eliminado; vuelve al listado.';
    public const MSG_CHOQUE = 'Otro conduce se guardó al mismo tiempo. Vuelve a guardar.';
    public const MSG_PRODUCTO_FK = 'Un producto del conduce ya no existe en el catálogo (lo eliminaron mientras lo editabas). Búscalo de nuevo o quita la línea.';
    public const MSG_COTIZACION_FK = 'La cotización de origen ya no existe; vuelve a Cotizaciones.';

    /**
     * null si la empresa de la petición puede usar conduces; si no, el mensaje
     * del 422. Solo el formato ferreteria los tiene: Gratex, un formato
     * desconocido y una instalación de un solo tenant (sin tenant resuelto)
     * dan gratex en CotizacionFormatos::delTenant(), que ya avisa en el
     * error_log de un formato desconocido.
     */
    public static function errorDisponibilidad(): ?string
    {
        return CotizacionFormatos::delTenant() === FerreteriaFormato::NOMBRE ? null : self::MSG_NO_DISPONIBLE;
    }

    /** 'CON-000001': el número de conduce_secuencia, a 6 cifras (uno más largo no se corta). */
    public static function codigo(int $numero): string
    {
        return self::PREFIJO . str_pad((string) $numero, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Forma del cuerpo de crear, actualizar y vista previa, sin DB. Que el
     * cliente, la cotización y los productos existan se revisa aparte, contra
     * la DB.
     *
     * Las reglas de unidades vienen inyectadas, como en
     * FerreteriaFormato::validarForma: en producción son
     * unidadMedidaModel::problemaCantidad e ::isValid (fail-open).
     *
     * date: 'Y-m-d H:i:s' (solo el día lleva la hora de ahora en RD) o null si
     * no vino (al crear, ahora; al editar, la guardada). cotizacion_id solo se
     * lee al crear: un conduce nunca cambia de origen. Las claves formato y
     * tipo no se leen.
     *
     * @param callable(float $cantidad, string $unidad, int $maxDec): ?string $problemaCantidad
     * @param callable(string $unidad): bool $unidadValida
     * @return array{ok:true, cot:array{date:?string, client_id:int, cotizacion_id:?int, items:array}}|array{ok:false, error:string}
     */
    public static function validarForma(object $body, callable $problemaCantidad, callable $unidadValida, bool $esCreacion): array
    {
        // Cargos o abonos en el cuerpo: viene de otra pantalla (la de la
        // cotización). Se rechaza entero en vez de guardar el conduce sin ellos
        // en silencio; basta la clave, ni null ni {} se adivinan.
        if (property_exists($body, 'ajustes')) {
            return ['ok' => false, 'error' => self::MSG_AJUSTES];
        }
        $cotizacionId = null;
        if ($esCreacion) {
            $cotizacionId = FerreteriaFormato::leerEntero($body->cotizacion_id ?? null);
            if ($cotizacionId === null || $cotizacionId <= 0) {
                return ['ok' => false, 'error' => self::MSG_COTIZACION];
            }
        }
        $clientId = FerreteriaFormato::leerEntero($body->client_id ?? null);
        if ($clientId === null || $clientId <= 0) {
            return ['ok' => false, 'error' => self::MSG_SIN_CLIENTE];
        }
        $fecha = FerreteriaFormato::normalizarFecha($body->date ?? null);
        if ($fecha === false) {
            return ['ok' => false, 'error' => self::MSG_FECHA];
        }
        $items = $body->items ?? null;
        if (!is_array($items) || $items === []) {
            return ['ok' => false, 'error' => self::MSG_SIN_LINEAS];
        }
        $lineas = [];
        foreach (array_values($items) as $i => $item) {
            // Las líneas se numeran desde 1, como las ve el usuario. true: el
            // precio puede ir en 0 (nunca negativo).
            $linea = FerreteriaFormato::normalizarLinea($item, $i + 1, $problemaCantidad, $unidadValida, true);
            if (is_string($linea)) {
                return ['ok' => false, 'error' => $linea];
            }
            $lineas[] = $linea;
        }
        return ['ok' => true, 'cot' => [
            'date' => $fecha,
            'client_id' => $clientId,
            'cotizacion_id' => $cotizacionId,
            'items' => $lineas,
        ]];
    }

    /**
     * Líneas en la forma del PDF en modo conduce: descripción, cantidad y el
     * NOMBRE de la unidad, ya resuelto (el renderizador es puro y no lee el
     * catálogo). Nunca el precio: el conduce no lo imprime.
     *
     * Sirve igual para las filas guardadas (DECIMAL como texto) que para las
     * líneas ya validadas de una vista previa. Una unidad que no está en
     * $nombresUnidad (inactiva, desconocida o catálogo ilegible) imprime su
     * código guardado; sin unidad es Unidad (43), como al guardar.
     *
     * @param array<int|string,string> $nombresUnidad [código DGII => descripción]
     * @return array<int,array{description:string,quantity:float,unidad:string}>
     */
    public static function itemsPdf(array $items, array $nombresUnidad): array
    {
        $out = [];
        foreach (array_values($items) as $item) {
            $item = (array) $item;
            $codigo = trim((string) ($item['unidad_medida'] ?? ''));
            if ($codigo === '') {
                $codigo = FerreteriaFormato::UNIDAD_DEFAULT;
            }
            // '43' y 43 son la misma clave de arreglo en PHP.
            $nombre = trim((string) ($nombresUnidad[$codigo] ?? ''));
            $out[] = [
                'description' => (string) ($item['description'] ?? ''),
                'quantity' => (float) ($item['quantity'] ?? 0),
                'unidad' => $nombre !== '' ? $nombre : $codigo,
            ];
        }
        return $out;
    }

    /**
     * Lo que recibe FerreteriaCotizacionPdf en modo conduce (spec 4.4):
     * 'conduce' es el primer argumento del constructor (el documento) y
     * 'cliente' el tercero. El emisor y el logo los pone quien llama.
     *
     * $row es la fila de conduceModel::obtener(), o la que arma la vista previa
     * con la misma forma. $code null = todavía sin número: el renderizador
     * imprime VISTA PREVIA. Sin fecha, ahora en RD. cotizacion_code null = la
     * cotización de origen se eliminó, y el PDF no lleva la línea Cotización.
     *
     * El cliente es el de hoy (el LEFT JOIN de clients; razon_social si la fila
     * la trae). Si lo borraron, conduces.client_id no tiene FK y el JOIN no
     * trae nada: sale el nombre guardado al crear o editar el conduce, sin RNC
     * (el renderizador no imprime un RNC vacío).
     *
     * @return array{conduce: array{documento:string, code:?string, date:string, cotizacion_code:?string, items:array},
     *               cliente: array{razon_social:?string, company_name:?string, client_name:?string, rnc:?string}}
     */
    public static function datosPdf(array $row, array $nombresUnidad, ?string $code): array
    {
        $fecha = trim((string) ($row['date'] ?? ''));
        $cotizacion = trim((string) ($row['cotizacion_code'] ?? ''));
        return [
            'conduce' => [
                'documento' => 'conduce',
                'code' => $code,
                'date' => $fecha !== '' ? $fecha : FerreteriaFormato::ahoraRd(),
                'cotizacion_code' => $cotizacion !== '' ? $cotizacion : null,
                'items' => self::itemsPdf($row['items'] ?? [], $nombresUnidad),
            ],
            'cliente' => self::clientePdf($row),
        ];
    }

    /** El cliente del PDF: el de hoy, o el nombre guardado si lo borraron (ver datosPdf). */
    private static function clientePdf(array $row): array
    {
        // clients.client_name y company_name son NOT NULL: si el JOIN encontró
        // al cliente, al menos uno no es null.
        $encontrado = ($row['client_name'] ?? null) !== null || ($row['company_name'] ?? null) !== null
            || ($row['razon_social'] ?? null) !== null;
        if ($encontrado) {
            return [
                'razon_social' => $row['razon_social'] ?? null,
                'company_name' => $row['company_name'] ?? null,
                'client_name' => $row['client_name'] ?? null,
                'rnc' => $row['rnc'] ?? null,
            ];
        }
        return [
            'razon_social' => null,
            'company_name' => null,
            'client_name' => $row['client_name_guardado'] ?? null,
            'rnc' => '',
        ];
    }
}
