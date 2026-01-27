<?php
header('Access-Control-Allow-Origin: *');
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: X-API-KEY, Authorization, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Allow: GET, POST, OPTIONS, PUT, DELETE");
header('content-type: application/json; charset=utf-8');

require_once(__DIR__ . '/../Models/clientModel.php');
require_once(__DIR__ . '/../Middleware/AuthMiddleware.php');

$clientModel = new clientModel();
$auth = new AuthMiddleware();

// Validate token for all requests except OPTIONS
if ($_SERVER['REQUEST_METHOD'] !== 'OPTIONS') {
    $validation = $auth->validateRequest();
    if (!$validation['valid']) {
        $auth->sendUnauthorized($validation['message']);
    }
}

switch($_SERVER['REQUEST_METHOD']){
    case 'GET':
        if (isset($_GET['id'])) {
            $clients = $clientModel->getClients($_GET['id']);
            if (empty($clients)) {
                $respuesta = ['status' => false, 'error' => 'Client not found'];
                http_response_code(404);
            } else {
                $respuesta = [
                    'status' => true,
                    'data' => $clients[0]
                ];
            }
        } else {
            $clients = $clientModel->getClients();
            $respuesta = [
                'status' => true,
                'data' => $clients
            ];
        }
        echo json_encode($respuesta);
    break;

    case 'POST':
        $_POST= json_decode(file_get_contents('php://input',true));
        if(!isset($_POST->email) || is_null($_POST->email) || empty(trim($_POST->email)) || !filter_var($_POST->email, FILTER_VALIDATE_EMAIL) || strlen($_POST->email) > 100){
            $respuesta= ['status' => false, 'error','Email must not be empty, must be a valid email and no more than 100 characters'];
        }
        else if(!isset($_POST->client_name) || is_null($_POST->client_name) || empty(trim($_POST->client_name)) || strlen($_POST->client_name) > 100){
            $respuesta= ['status' => false, 'error','Client name must not be empty and no more than 100 characters'];
        }
        else if(!isset($_POST->company_name) || is_null($_POST->company_name) || empty(trim($_POST->company_name)) || strlen($_POST->company_name) > 100){
            $respuesta= ['status' => false, 'error','Company name must not be empty and no more than 100 characters'];
        }
        else if(!isset($_POST->phone_number) || is_null($_POST->phone_number) || empty(trim($_POST->phone_number)) || strlen($_POST->phone_number) > 20){
            $respuesta= ['status' => false, 'error','Phone number must not be empty and no more than 20 characters'];
        }
        else{
            $result = $clientModel->saveClient($_POST->email,$_POST->client_name,$_POST->company_name,$_POST->phone_number);
            if($result[0] === 'success'){
                $respuesta = ['status' => true, 'data' => $result[1]];
            } else {
                $respuesta = ['status' => false, 'error' => $result[1]];
            }
        }
        echo json_encode($respuesta);
    break;

    case 'PUT':
        $_PUT= json_decode(file_get_contents('php://input',true));
        if(!isset($_PUT->id) || is_null($_PUT->id) || empty(trim($_PUT->id))){
            $respuesta= ['status' => false, 'error','Client ID is empty'];
        }
        else if(!isset($_PUT->email) || is_null($_PUT->email) || empty(trim($_PUT->email)) || !filter_var($_PUT->email, FILTER_VALIDATE_EMAIL) || strlen($_PUT->email) > 100){
            $respuesta= ['status' => false, 'error','Email must not be empty, must be a valid email and no more than 100 characters'];
        }
        else if(!isset($_PUT->client_name) || is_null($_PUT->client_name) || empty(trim($_PUT->client_name)) || strlen($_PUT->client_name) > 100){
            $respuesta= ['status' => false, 'error','Client name must not be empty and no more than 100 characters'];
        }
        else if(!isset($_PUT->company_name) || is_null($_PUT->company_name) || empty(trim($_PUT->company_name)) || strlen($_PUT->company_name) > 100){
            $respuesta= ['status' => false, 'error','Company name must not be empty and no more than 100 characters'];
        }
        else if(!isset($_PUT->phone_number) || is_null($_PUT->phone_number) || empty(trim($_PUT->phone_number)) || strlen($_PUT->phone_number) > 20){
            $respuesta= ['status' => false, 'error','Phone number must not be empty and no more than 20 characters'];
        }
        else{
            $result = $clientModel->updateClient($_PUT->id,$_PUT->email,$_PUT->client_name,$_PUT->company_name,$_PUT->phone_number);
            if($result[0] === 'success'){
                $respuesta = ['status' => true, 'data' => $result[1]];
            } else {
                $respuesta = ['status' => false, 'error' => $result[1]];
            }
        }
        echo json_encode($respuesta);
    break;

    case 'DELETE';
        $_DELETE= json_decode(file_get_contents('php://input',true));
        if(!isset($_DELETE->id) || is_null($_DELETE->id) || empty(trim($_DELETE->id))){
            $respuesta= ['status' => false, 'error','Client ID is empty'];
        }
        else{
            $result = $clientModel->deleteClient($_DELETE->id);
            if($result[0] === 'success'){
                $respuesta = ['status' => true, 'data' => $result[1]];
            } else {
                $respuesta = ['status' => false, 'error' => $result[1]];
            }
        }
        echo json_encode($respuesta);
    break;
}
