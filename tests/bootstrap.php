<?php
/**
 * Bootstrap for PHPUnit
 */

use Composer\InstalledVersions;

if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}
if (!defined('VENDORS')) {
    define('VENDORS', dirname(__DIR__) . DS . 'vendor' . DS);
}
if (!defined('ROOT')) {
    define('ROOT', realpath(InstalledVersions::getInstallPath('pieceofcake2/app')));
}

require_once InstalledVersions::getInstallPath('pieceofcake2/cakephp') . DS . 'tests' . DS . 'bootstrap.php';

CakePlugin::load('Image', [
    'path' => dirname(__DIR__) . DS,
]);

require_once __DIR__ . DS . 'TestCase' . DS . 'ImageTestTrait.php';

// The tests do not use a database: without config/database.php, the models use an empty configuration
if (!is_file(CONFIG . 'database.php') && !class_exists('DATABASE_CONFIG', false)) {
    class DATABASE_CONFIG // phpcs:ignore
    {
    }
}
