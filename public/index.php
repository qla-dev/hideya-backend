<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Production lives at /hideya/endpoints. The parent .htaccess forwards clean
// URLs into public/ while REQUEST_URI keeps the prefix, so present the front
// controller at the prefix: Laravel then treats it as the base path.
$deploymentPrefix = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '', 2), '/\');
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
if (
    $deploymentPrefix !== ''
    && ($requestUri === $deploymentPrefix || str_starts_with($requestUri, $deploymentPrefix.'/'))
    && ! str_starts_with($requestUri, $deploymentPrefix.'/public')
) {
    $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = $deploymentPrefix.'/index.php';
}

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
