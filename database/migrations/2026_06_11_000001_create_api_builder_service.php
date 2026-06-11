<?php

use DreamFactory\Core\ApiBuilder\Installer;
use DreamFactory\Core\Models\Service;
use Illuminate\Database\Migrations\Migration;

/**
 * Provision the api_builder management service so the admin UI works on a fresh
 * install. Idempotent via Installer::ensureManagementService().
 */
return new class extends Migration
{
    public function up(): void
    {
        Installer::ensureManagementService();
    }

    public function down(): void
    {
        Service::whereName(Installer::MANAGEMENT_SERVICE)
            ->where('type', 'api_builder')
            ->delete();
    }
};
