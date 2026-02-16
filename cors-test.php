<?php
/**
 * CORS Test Endpoint
 * Use this to verify CORS headers are being sent correctly
 * Access via: https://gratex.net/cors-test.php or https://gratex.net/api/cors-test.php
 */

// Set CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-API-KEY, Authorization, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE');
header('Access-Control-Max-Age: 86400');
header('Content-Type: application/json; charset=utf-8');

// Handle OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => 'CORS preflight successful',
        'method' => 'OPTIONS'
    ]);
    exit();
}

// Handle normal requests
http_response_code(200);
echo json_encode([
    'status' => 'success',
    'message' => 'CORS test endpoint is working',
    'method' => $_SERVER['REQUEST_METHOD'],
    'headers_sent' => [
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Headers' => 'X-API-KEY, Authorization, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method',
        'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS, PUT, DELETE',
        'Access-Control-Max-Age' => '86400'
    ],
    'server_info' => [
        'request_uri' => $_SERVER['REQUEST_URI'],
        'script_name' => $_SERVER['SCRIPT_NAME'],
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown'
    ]
]);
