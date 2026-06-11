<?php

namespace DreamFactory\Core\ApiBuilder\Tests\Feature;

use DreamFactory\Core\ApiBuilder\Installer;
use DreamFactory\Core\ApiBuilder\Tests\FeatureTestCase;
use DreamFactory\Core\Models\Service;

/**
 * The admin UI dead-ends ("Could not find a service for api_builder") unless a
 * management service instance exists. Installer provisions it on install.
 */
class InstallerTest extends FeatureTestCase
{
    public function test_ensures_management_service_exists(): void
    {
        Installer::ensureManagementService();

        $service = Service::whereName(Installer::MANAGEMENT_SERVICE)->first();

        $this->assertNotNull($service, 'api_builder management service should exist after install');
        $this->assertSame('api_builder', $service->type);
        $this->assertTrue((bool)$service->is_active);
    }

    public function test_is_idempotent(): void
    {
        Installer::ensureManagementService();
        Installer::ensureManagementService();

        $this->assertSame(
            1,
            Service::whereName(Installer::MANAGEMENT_SERVICE)->count(),
            'provisioning twice must not create duplicate services'
        );
    }
}
