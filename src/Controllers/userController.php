<?php
header('Access-Control-Allow-Origin: *');
header("Access-Control-Allow-Headers: X-API-KEY, Authorization, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Allow: GET, POST, OPTIONS, PUT, DELETE");
header('content-type: application/json; charset=utf-8');
require_once(__DIR__ . '/../Models/userModel.php');
require_once(__DIR__ . '/../Middleware/AuthMiddleware.php');

$userModel = new userModel();
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
        $users = (!isset($_GET['id'])) ? $userModel->getUsers() : $userModel->getUsers($_GET['id']);
        $respuesta = [
            'status' => true,
            'data' => $users
        ];
        echo json_encode($respuesta);
    break;

    case 'POST':
        $_POST= json_decode(file_get_contents('php://input',true));
        if(!isset($_POST->name) || is_null($_POST->name) || empty(trim($_POST->name)) || strlen($_POST->name) > 80){
            $respuesta= ['status' => false, 'error','Name must not be empty and no more than 80 characters'];
        }
        else if(!isset($_POST->last_name) || is_null($_POST->last_name) || empty(trim($_POST->last_name)) || strlen($_POST->last_name) > 80){
            $respuesta= ['status' => false, 'error','Last Name must not be empty and no more than 100 characters'];
        }
        else if(!isset($_POST->email) || is_null($_POST->email) || empty(trim($_POST->email)) ||!filter_var($_POST->email, FILTER_VALIDATE_EMAIL) || strlen($_POST->email) > 50){
            $respuesta= ['status' => false, 'error','Email must not be empty, must be a valid email and no more than 20 characters'];
        }
        else{
            $result = $userModel->saveUser($_POST->name,$_POST->last_name,$_POST->email);
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
            $respuesta= ['status' => false, 'error','User ID is empty'];
        }
        else if(!isset($_PUT->name) || is_null($_PUT->name) || empty(trim($_PUT->name)) || strlen($_PUT->name) > 80){
            $respuesta= ['status' => false, 'error','name must not be empty and no more than 80 characters'];
        }
        else if(!isset($_PUT->last_name) || is_null($_PUT->last_name) || empty(trim($_PUT->last_name)) || strlen($_PUT->last_name) > 100){
            $respuesta= ['status' => false, 'error','Last Name must not be empty and no more than 100 characters'];
        }
        else if(!isset($_PUT->email) || is_null($_PUT->email) || empty(trim($_PUT->email)) || !filter_var($_PUT->email, FILTER_VALIDATE_EMAIL) || strlen($_PUT->email) > 20){
            $respuesta= ['status' => false, 'error','Email must not be empty, must be a valid email and no more than 20 characters'];
        }
        else{
            $result = $userModel->updateUser($_PUT->id,$_PUT->name,$_PUT->last_name,$_PUT->email);
            if($result[0] === 'success'){
                $respuesta = ['status' => true, 'data' => $result[1]];
            } else {
                $respuesta = ['status' => false, 'error' => $result[1]];
            }
        }
        echo json_encode($respuesta);
    break;

    case 'DELETE':
        $_DELETE= json_decode(file_get_contents('php://input',true));
        if(!isset($_DELETE->id) || is_null($_DELETE->id) || empty(trim($_DELETE->id))){
            $respuesta= ['status' => false, 'error','User ID is empty'];
        }
        else{
            $result = $userModel->deleteUser($_DELETE->id);
            if($result[0] === 'success'){
                $respuesta = ['status' => true, 'data' => $result[1]];
            } else {
                $respuesta = ['status' => false, 'error' => $result[1]];
            }
        }
        echo json_encode($respuesta);
    break;
}