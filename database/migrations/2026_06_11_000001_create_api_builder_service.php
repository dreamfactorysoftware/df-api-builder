<?php

use DreamFactory\Core\ApiBuilder\Installer;
use DreamFactory\Core\Models\Service;
use Illuminate\Database\Migrations\Migration;

/**
 * Provision the api_builder management service so the admin UI works on a fresh
 * install. Idempotent via Installer::ensureManagementService().
 *
 * up() suppresses model events on purpose. Service::boot() fires
 * ServiceModifiedEvent from saved(), and subscribers read their own tables --
 * df-alerts' handler queries alert_rules, created by a migration timestamped
 * 11 days later. Laravel orders migrations by filename across every package
 * path, so the subscriber ran against a table that did not exist yet and a
 * fresh install died on SQLSTATE[42S02]. Suppression also skips created(),
 * which runs a service health check and calls UpdatesSender::sendServiceData();
 * neither belongs in a migration.
 *
 * Provisioning a row during install is not a business event. If a listener you
 * expect is not firing here, fix the listener -- do not unwrap this.
 *
 * down() needs no wrapper: whereName()->delete() is a query-builder bulk
 * delete, which does not fire Eloquent model events at all. Verified 2026-08-17.
 */
return new class extends Migration
{
    public function up(): void
    {
        Service::withoutEvents(static fn () => Installer::ensureManagementService());
    }

    public function down(): void
    {
        Service::whereName(Installer::MANAGEMENT_SERVICE)
            ->where('type', 'api_builder')
            ->delete();
    }
};
