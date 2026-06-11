<?php

namespace DreamFactory\Core\ApiBuilder;

use DreamFactory\Core\Models\Service;

/**
 * Install-time provisioning for the API Builder module.
 */
class Installer
{
    /** Name of the singleton management service the admin UI talks to. */
    public const MANAGEMENT_SERVICE = 'api_builder';

    /**
     * Ensure the api_builder management service instance exists. Idempotent.
     * Without it the admin UI dead-ends on "Could not find a service for api_builder".
     */
    public static function ensureManagementService(): void
    {
        if (Service::whereName(self::MANAGEMENT_SERVICE)->exists()) {
            return;
        }

        Service::create([
            'name'        => self::MANAGEMENT_SERVICE,
            'label'       => 'API Builder',
            'description' => 'API Builder management service.',
            'type'        => 'api_builder',
            'is_active'   => true,
            'config'      => [],
        ]);
    }
}
