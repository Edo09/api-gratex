<?php
header('Access-Control-Allow-Origin: *');
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: X-API-KEY, Authorization, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Allow: GET, POST, OPTIONS, PUT, DELETE");
header('content-type: application/json; charset=utf-8');

require_once(__DIR__ . '/../Models/productModel.php');
require_once(__DIR__ . '/../Models/unidadMedidaModel.php');
require_once(__DIR__ . '/../Middleware/AuthMiddleware.php');
require_once(__DIR__ . '/../Utils/ProductImageStorage.php');

$productModel = new productModel();
$auth = new AuthMiddleware();

// Token requerido para todo menos OPTIONS (en multi-tenant resuelve el tenant DB).
if ($_SERVER['REQUEST_METHOD'] !== 'OPTIONS') {
    $validation = $auth->validateRequest();
    if (!$validation['valid']) {
        $auth->sendUnauthorized($validation['message']);
    }
}

/**
 * Existencia y stock mínimo: pueden llevar decimales (hasta 3, DECIMAL(15,3))
 * solo si la unidad del producto los admite. A diferencia de una cantidad de
 * factura pueden ser 0, y la existencia negativa (se vende sin bloquear), así
 * que de problemaCantidad solo cuenta la parte de decimales y unidad.
 *
 * En PUT, un valor que no cambió, con la misma unidad, no se juzga: el libro
 * mueve la existencia (vender 1,5 metros de un producto en "Unidad" la deja en
 * 8,5) y el formulario la reenvía tal cual en cada guardado; rechazarla
 * impediría hasta corregir el precio. El formulario (fiscalo
 * ProductFormModal.tsx) aplica la misma excepción.
 *
 * @param array|null $actual fila guardada del producto (PUT) o null (POST)
 */
function problemaExistencias($p, ?array $actual): ?string
{
    static $unidades = null;
    $unidad = productModel::unidadDelPayload($p);
    $mismaUnidad = $actual !== null && trim((string) ($actual['unidad_medida'] ?? '')) === $unidad;
    foreach (['stock' => 'la existencia', 'stock_minimo' => 'el stock mínimo'] as $campo => $nombre) {
        $valor = $p->$campo ?? null;
        if ($valor === null || $valor === '') {
            continue;
        }
        if (!is_numeric($valor)) {
            return ucfirst($nombre) . ' tiene que ser un número.';
        }
        $n = (float) $valor;
        // DECIMAL(15,3) llega a 999,999,999,999.999; pasado eso MySQL lo rechaza
        // y el formulario diría "no se pudo guardar" sin decir por qué. Se mira
        // el valor ya redondeado a 3, que es lo que se guarda.
        if (abs(round($n, 3)) >= 1000000000000) {
            return ucfirst($nombre) . ' es demasiado grande.';
        }
        if (unidadMedidaModel::decimalesDe($n) === 0) {
            continue;
        }
        if ($mismaUnidad && isset($actual[$campo]) && abs((float) $actual[$campo] - $n) < 0.0005) {
            continue;
        }
        if ($unidades === null) {
            // Fail-open, como permiteDecimales: sin la master no se bloquea el
            // producto por esto (el valor se guarda redondeado a 3).
            try {
                $unidades = new unidadMedidaModel();
            } catch (Throwable $e) {
                error_log('[productos] sin catalogo de unidades (fail-open): ' . $e->getMessage());
                $unidades = false;
            }
        }
        if ($unidades === false) {
            return null;
        }
        // abs(): a la existencia negativa se le juzgan los decimales igual.
        $problema = $unidades->problemaCantidad(abs($n), $unidad, 3);
        if ($problema !== null) {
            return 'En ' . $nombre . ', ' . lcfirst($problema);
        }
    }
    return null;
}

/**
 * Valida los campos comunes de un producto en POST/PUT. Devuelve string de error o null.
 * Los textos llegan tal cual al formulario de producto: se escriben para quien
 * lo llena (sin nombres de campos del JSON).
 *
 * @param array|null $actual fila guardada (PUT), para no juzgar una existencia
 *                           que no se tocó (ver problemaExistencias)
 */
function validateProduct($p, ?array $actual = null): ?string
{
    if (!isset($p->nombre) || is_null($p->nombre) || empty(trim($p->nombre)) || strlen($p->nombre) > 150) {
        return 'El nombre del producto es obligatorio y puede tener hasta 150 caracteres.';
    }
    if (isset($p->precio) && (!is_numeric($p->precio) || (float) $p->precio < 0)) {
        return 'El precio no puede ser negativo.';
    }
    if (isset($p->costo) && (!is_numeric($p->costo) || (float) $p->costo < 0)) {
        return 'El costo no puede ser negativo.';
    }
    // Listas 2-4: opcionales. Vacio o null = "no aplica"; si viene algo, tiene
    // que ser un numero no negativo, igual que precio.
    foreach (['precio_2' => 2, 'precio_3' => 3, 'precio_4' => 4] as $campo => $lista) {
        $valor = $p->$campo ?? null;
        if ($valor === null || $valor === '') {
            continue;
        }
        if (!is_numeric($valor) || (float) $valor < 0) {
            return "El precio de la lista {$lista} no puede ser negativo.";
        }
    }
    if (isset($p->indicador_facturacion) && !in_array((int) $p->indicador_facturacion, [0, 1, 2, 3, 4], true)) {
        return 'Elige si el producto lleva ITBIS o está exento.';
    }
    if (isset($p->indicador_bien_servicio) && !in_array((int) $p->indicador_bien_servicio, [1, 2], true)) {
        return 'Elige si es un bien o un servicio.';
    }
    // Inventario: category_id opcional (nullable), warehouse_id opcional (el modelo
    // asigna "Almacén Principal" si no se envia). Si vienen, deben ser enteros > 0.
    if (isset($p->category_id) && $p->category_id !== null && $p->category_id !== ''
        && (!is_numeric($p->category_id) || (int) $p->category_id <= 0)) {
        return 'La categoría elegida no es válida. Elige otra de la lista.';
    }
    if (isset($p->warehouse_id) && $p->warehouse_id !== null && $p->warehouse_id !== ''
        && (!is_numeric($p->warehouse_id) || (int) $p->warehouse_id <= 0)) {
        return 'El almacén elegido no es válido. Elige otro de la lista.';
    }
    return problemaExistencias($p, $actual);
}

// ---------------------------------------------------------------------------
// Foto del producto (migracion 032). Una por producto.
//   POST   /api/products/imagen  multipart: id + imagen (JPG/PNG/WebP) -> {imagen_path}
//   DELETE /api/products/imagen  {id}                                  -> {imagen_path: null}
// El archivo nuevo se guarda antes de tocar la fila y el viejo se borra despues:
// si algo falla en medio, el producto nunca queda apuntando a un archivo borrado.
// ---------------------------------------------------------------------------
if (preg_match('#/products/imagen/?$#', (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH)) === 1) {
    $metodo = $_SERVER['REQUEST_METHOD'];
    if ($metodo === 'OPTIONS') {
        exit;
    }
    $responder = static function (int $http, array $cuerpo): void {
        http_response_code($http);
        echo json_encode($cuerpo);
        exit;
    };
    if ($metodo !== 'POST' && $metodo !== 'DELETE') {
        $responder(405, ['status' => false, 'error' => 'Usa POST para subir la foto o DELETE para quitarla.']);
    }
    $cuerpo = $metodo === 'DELETE' ? InputSanitizer::jsonInput() : null;
    $id = $metodo === 'POST' ? ($_POST['id'] ?? null) : (is_array($cuerpo) ? ($cuerpo['id'] ?? null) : null);
    if (!is_numeric($id) || (int) $id <= 0) {
        $responder(422, ['status' => false, 'error' => 'No se pudo identificar el producto. Cierra la ventana y ábrelo de nuevo.']);
    }
    $id = (int) $id;
    if ($productModel->getProducts($id) === []) {
        $responder(404, ['status' => false, 'error' => 'Este producto ya no existe; puede que otra persona lo haya eliminado.']);
    }

    $nueva = null;
    if ($metodo === 'POST') {
        $tenant = class_exists('TenantResolver') ? TenantResolver::current() : null;
        $guardado = ProductImageStorage::store($_FILES['imagen'] ?? [], isset($tenant['id']) ? (int) $tenant['id'] : null);
        if (!$guardado['ok']) {
            $responder($guardado['code'] ?? 422, ['status' => false, 'error' => $guardado['error']]);
        }
        $nueva = $guardado['imagen_path'];
    }
    try {
        $anterior = $productModel->cambiarImagen($id, $nueva);
    } catch (Throwable $e) {
        error_log('[products/imagen] no se pudo guardar en la base: ' . $e->getMessage());
        ProductImageStorage::removeFile($nueva);
        $responder(500, ['status' => false, 'error' => 'No se pudo guardar la foto. Inténtalo de nuevo más tarde.']);
    }
    if ($anterior === false) {
        ProductImageStorage::removeFile($nueva);
        $responder(404, ['status' => false, 'error' => 'Este producto ya no existe; puede que otra persona lo haya eliminado.']);
    }
    if ($anterior !== null && $anterior !== $nueva) {
        ProductImageStorage::removeFile($anterior);
    }
    AuditLogger::log([
        'module' => 'products', 'action' => $nueva !== null ? 'IMAGEN_CAMBIADA' : 'IMAGEN_QUITADA',
        'entity_type' => 'product', 'entity_id' => $id,
        'old_values' => ['imagen_path' => $anterior], 'new_values' => ['imagen_path' => $nueva],
        'description' => $nueva !== null ? 'Foto del producto cambiada.' : 'Foto del producto quitada.',
    ]);
    $responder(200, ['status' => true, 'data' => ['id' => $id, 'imagen_path' => $nueva]]);
}

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        if (isset($_GET['id'])) {
            $products = $productModel->getProducts($_GET['id']);
            if (empty($products)) {
                http_response_code(404);
                $respuesta = ['status' => false, 'error' => 'Este producto no existe.'];
            } else {
                $respuesta = ['status' => true, 'data' => $products[0]];
            }
        } else if (isset($_GET['page']) || isset($_GET['pageSize']) || isset($_GET['query']) || isset($_GET['category_id'])) {
            $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int) $_GET['page'] : 1;
            $pageSize = isset($_GET['pageSize']) && is_numeric($_GET['pageSize']) && $_GET['pageSize'] > 0 ? (int) $_GET['pageSize'] : 10;
            $query = isset($_GET['query']) ? $_GET['query'] : null;
            // category_id invalido (0, vacio o basura) = sin filtro, igual que en
            // /api/inventario/valor: un filtro que no se entiende no debe vaciar
            // el catalogo sin decir por que.
            $categoryId = isset($_GET['category_id']) && is_numeric($_GET['category_id']) && (int) $_GET['category_id'] > 0
                ? (int) $_GET['category_id']
                : null;
            $offset = ($page - 1) * $pageSize;
            $products = $productModel->getProductsPaginated($offset, $pageSize, $query, $categoryId);
            $total = $productModel->getProductsCount($query, $categoryId);
            $respuesta = [
                'status' => true,
                'data' => $products,
                'pagination' => [
                    'page' => $page,
                    'pageSize' => $pageSize,
                    'total' => $total,
                    'totalPages' => ceil($total / $pageSize)
                ]
            ];
        } else {
            $respuesta = ['status' => true, 'data' => $productModel->getProducts()];
        }
        echo json_encode($respuesta);
        break;

    case 'POST':
        $_POST = InputSanitizer::jsonInput(false);
        $error = validateProduct($_POST);
        if ($error !== null) {
            // 422: es un dato del formulario, no un fallo del servidor.
            http_response_code(422);
            $respuesta = ['status' => false, 'error' => $error];
        } else {
            $result = $productModel->saveProduct($_POST);
            if ($result[0] === 'success') {
                $respuesta = ['status' => true, 'data' => ['id' => $result[2] ?? null, 'message' => $result[1]]];
                AuditLogger::log([
                    'module' => 'products', 'action' => 'CREATE',
                    'entity_type' => 'product', 'entity_id' => $result[2] ?? null,
                    'new_values' => $_POST, 'description' => 'Producto creado.',
                ]);
            } else {
                $respuesta = ['status' => false, 'error' => $result[1]];
            }
        }
        echo json_encode($respuesta);
        break;

    case 'PUT':
        $_PUT = InputSanitizer::jsonInput(false);
        // La fila guardada se lee ANTES de validar: una existencia que no cambio
        // no se juzga (ver problemaExistencias). La misma sirve para la auditoria.
        $oldProduct = isset($_PUT->id) && trim((string) $_PUT->id) !== ''
            ? ($productModel->getProducts($_PUT->id)[0] ?? null)
            : null;
        if (!isset($_PUT->id) || is_null($_PUT->id) || empty(trim((string) $_PUT->id))) {
            $respuesta = ['status' => false, 'error' => 'No se pudo identificar el producto. Cierra la ventana y ábrelo de nuevo.'];
        } else if (($error = validateProduct($_PUT, $oldProduct)) !== null) {
            // 422: es un dato del formulario, no un fallo del servidor.
            http_response_code(422);
            $respuesta = ['status' => false, 'error' => $error];
        } else {
            $result = $productModel->updateProduct($_PUT->id, $_PUT);
            $respuesta = $result[0] === 'success'
                ? ['status' => true, 'data' => $result[1]]
                : ['status' => false, 'error' => $result[1]];
            if ($result[0] === 'success') {
                AuditLogger::log([
                    'module' => 'products', 'action' => 'UPDATE',
                    'entity_type' => 'product', 'entity_id' => $_PUT->id,
                    'old_values' => $oldProduct, 'new_values' => $_PUT,
                    'description' => 'Producto actualizado.',
                ]);
            }
        }
        echo json_encode($respuesta);
        break;

    case 'DELETE':
        $_DELETE = InputSanitizer::jsonInput(false);
        if (!isset($_DELETE->id) || is_null($_DELETE->id) || empty(trim((string) $_DELETE->id))) {
            $respuesta = ['status' => false, 'error' => 'No se pudo identificar el producto. Cierra la ventana y ábrelo de nuevo.'];
        } else {
            $oldProduct = $productModel->getProducts($_DELETE->id)[0] ?? null;
            $result = $productModel->deleteProduct($_DELETE->id);
            $respuesta = $result[0] === 'success'
                ? ['status' => true, 'data' => $result[1]]
                : ['status' => false, 'error' => $result[1]];
            if ($result[0] === 'success') {
                // Producto borrado: su foto ya no la usa nadie.
                ProductImageStorage::removeFile($oldProduct['imagen_path'] ?? null);
                AuditLogger::log([
                    'module' => 'products', 'action' => 'DELETE',
                    'entity_type' => 'product', 'entity_id' => $_DELETE->id,
                    'old_values' => $oldProduct, 'description' => 'Producto eliminado.',
                ]);
            }
        }
        echo json_encode($respuesta);
        break;
}
