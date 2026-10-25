<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Production files live in /dist/hideya/endpoints and qla.dev's root
// .htaccess also maps the public URL /hideya/endpoints onto them. REQUEST_URI
// keeps whichever prefix was requested, so take the folder path and drop
// leading segments until it matches; present the front controller there so
// Laravel treats that prefix as its base path.
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$deploymentPrefix = rtrim(str_replace(chr(92), '/', dirname($_SERVER['SCRIPT_NAME'] ?? '', 2)), '/');
while (
    $deploymentPrefix !== ''
    && $requestUri !== $deploymentPrefix
    && ! str_starts_with($requestUri, $deploymentPrefix.'/')
) {
    $deploymentPrefix = (string) preg_replace('#^/[^/]+#', '', $deploymentPrefix);
}
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
