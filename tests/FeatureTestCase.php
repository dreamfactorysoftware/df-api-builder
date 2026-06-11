<?php

namespace DreamFactory\Core\ApiBuilder\Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Boots the real DreamFactory app inside the dev container so Feature tests can
 * use the service container, facades (e.g. ServiceManager) and the mysql DB.
 * Only runs in-container; the bootstrap path is the app root under /opt/dreamfactory.
 */
abstract class FeatureTestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = require '/opt/dreamfactory/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
