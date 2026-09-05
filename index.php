<?php
/**
 * API Entry Point
 * Routes requests to appropriate controllers
 */

// Antes que nada: sin esto cualquier fallo no capturado sale como un 500 con el
// cuerpo vacio, imposible de diagnosticar sin abrir el error_log del servidor.
require_once __DIR__ . '/src/ErrorHandler.php';
ErrorHandler::register();

include 'src/Router.php';
