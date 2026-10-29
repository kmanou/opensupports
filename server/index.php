<?php
// Slim 2.x used to convert every PHP error matching error_reporting() into a
// thrown ErrorException (Slim::handleErrors()). That mechanism is gone in
// Slim 4, but the bundled dependencies still predate PHP 8 and are chatty
// with E_DEPRECATED/E_WARNING/E_NOTICE noise under PHP 8.3, which would
// otherwise leak into the JSON output. Only true errors are escalated.
error_reporting(E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR);
ini_set('display_errors', '0');

@include 'config.php';
require_once 'vendor/autoload.php';
header('X-Frame-Options: DENY');

date_default_timezone_set ('UTC');
// REDBEAN CONFIGURATION
use RedBeanPHP\Facade as RedBean;

if(defined('MYSQL_HOST') && defined('MYSQL_DATABASE') && defined('MYSQL_USER') && defined('MYSQL_PASSWORD')) {
    if(!defined('MYSQL_PORT')) define('MYSQL_PORT', '3306');
    RedBean::setup('mysql:host='. MYSQL_HOST . ';port=' . MYSQL_PORT . ';dbname=' . MYSQL_DATABASE , MYSQL_USER, MYSQL_PASSWORD);
    RedBean::setAutoResolve(true);
    // TODO: Implement freeze
    // RedBean::freeze();
}

// SLIM FRAMEWORK
$app = \Slim\Factory\AppFactory::create();

// Slim 2.x used to strip the front controller's own directory from the
// request path automatically, so routes like "/system/login" matched
// whether api/index.php was the webroot or (as in the normal OpenSupports
// layout) nested under the main domain as api/index.php, reached as
// "/api/system/login". Slim 4 requires this base path to be set explicitly.
$basePath = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
if ($basePath !== '/' && $basePath !== '.' && $basePath !== '') {
    $app->setBasePath($basePath);
}

$app->addRoutingMiddleware();
$app->addErrorMiddleware(false, false, false);
Controller::init();
Controller::setAppInstance($app);

// LOAD CONTROLLERS (each controller group file registers its own routes
// with $app, via ControllerGroup::finalize(), as it is included)
foreach (glob('controllers/*.php') as $controller) {
    include_once $controller;
}

$app->run();
