<?php

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

/*
| Sur Windows, l’analyse antivirus du dossier vendor peut faire dépasser la limite PHP par défaut (30 s)
| pendant l’autoload, avant même AppServiceProvider. On élargit la fenêtre tôt pour le serveur de dev.
*/
if (PHP_OS_FAMILY === 'Windows') {
    @ini_set('max_execution_time', '120');
}

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
(require_once __DIR__.'/../bootstrap/app.php')
    ->handleRequest(Request::capture());
