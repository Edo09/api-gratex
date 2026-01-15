<?php
header('Access-Control-Allow-Origin: *');
header("Access-Control-Allow-Headers: X-API-KEY, Authorization, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Allow: GET, POST, OPTIONS, PUT, DELETE");
header('content-type: application/json; charset=utf-8');

require_once(__DIR__ . '/../Models/LandingModel.php');
require_once(__DIR__ . '/../Middleware/AuthMiddleware.php');

$landingModel = new LandingModel();
// $auth = new AuthMiddleware(); // Uncomment to enforce auth

$method = $_SERVER['REQUEST_METHOD'];
$request_uri = $_SERVER['REQUEST_URI'];
$endpoint_parts = explode('/', parse_url($request_uri, PHP_URL_PATH));
$resource = end($endpoint_parts); // carousel or services

// Public uploads directory
$uploadDir = __DIR__ . '/../../public/uploads/';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

switch ($method) {
    case 'GET':
        if (strpos($request_uri, 'carousel') !== false) {
            $items = $landingModel->getCarouselItems();
            echo json_encode(['success' => true, 'data' => $items]);
        } elseif (strpos($request_uri, 'services') !== false) {
            $items = $landingModel->getServices();
            echo json_encode(['success' => true, 'data' => $items]);
        }
        break;

    case 'POST':
        // Handle file uploads
        if (isset($_FILES['image'])) {
            $file = $_FILES['image'];
            $fileName = uniqid() . '_' . basename($file['name']);
            $targetPath = $uploadDir . $fileName;

            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                $imagePath = 'public/uploads/' . $fileName; // Relative path for storage

                if (strpos($request_uri, 'carousel') !== false) {
                    $title = $_POST['title'] ?? '';
                    $subtitle = $_POST['subtitle'] ?? '';
                    $result = $landingModel->addCarouselItem($title, $subtitle, $imagePath);
                } elseif (strpos($request_uri, 'services') !== false) {
                    $title = $_POST['title'] ?? '';
                    $description = $_POST['description'] ?? '';
                    $result = $landingModel->addService($title, $description, $imagePath);
                }

                if ($result[0] === 'success') {
                    echo json_encode(['success' => true, 'message' => $result[1], 'id' => $result[2]]);
                } else {
                    http_response_code(500);
                    echo json_encode(['success' => false, 'error' => $result[1]]);
                }
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Failed to upload image']);
            }
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'No image file provided']);
        }
        break;

    case 'DELETE':
        $data = json_decode(file_get_contents('php://input'), true);
        $id = $data['id'] ?? null;

        if (!$id) {
            // Try to get ID from query string if not in body
            if (isset($_GET['id'])) {
                $id = $_GET['id'];
            }
        }

        if ($id) {
            if (strpos($request_uri, 'carousel') !== false) {
                $result = $landingModel->deleteCarouselItem($id);
            } elseif (strpos($request_uri, 'services') !== false) {
                $result = $landingModel->deleteService($id);
            }

            if ($result[0] === 'success') {
                // Delete actual file
                $filePath = __DIR__ . '/../../' . $result[2];
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
                echo json_encode(['success' => true, 'message' => $result[1]]);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => $result[1]]);
            }
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'ID is required']);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['error', 'Method not allowed']);
        break;
}
