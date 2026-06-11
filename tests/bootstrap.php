<?php

/**
 * PHPUnit bootstrap (runs in-container). Loads the app's L13 autoloader and
 * registers this package's Tests\ namespace so base classes like
 * FeatureTestCase resolve (the package has no standalone vendor/).
 *
 * @var \Composer\Autoload\ClassLoader $loader
 */
$loader = require __DIR__ . '/../../../autoload.php';
$loader->addPsr4('DreamFactory\\Core\\ApiBuilder\\Tests\\', __DIR__);

return $loader;
