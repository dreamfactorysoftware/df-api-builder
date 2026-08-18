<?php

namespace DreamFactory\Core\ApiBuilder\Tests\Feature;

use DreamFactory\Core\ApiBuilder\Installer;
use DreamFactory\Core\ApiBuilder\Tests\FeatureTestCase;
use DreamFactory\Core\Events\ServiceModifiedEvent;
use DreamFactory\Core\Models\Service;
use Illuminate\Support\Facades\Event;

/**
 * Regression guard for the 7.7.0 install blocker.
 *
 * 2026_06_11_000001_create_api_builder_service provisions the api_builder
 * service. Service::boot() fires ServiceModifiedEvent from saved(), and
 * df-alerts subscribes to it and reads alert_rules -- a table created by a
 * migration timestamped 11 days later. Laravel orders migrations by filename
 * across all package paths, so a fresh install died on:
 *
 *   SQLSTATE[42S02]: Table '<db>.alert_rules' doesn't exist
 *
 * The migration wraps provisioning in Service::withoutEvents(). These tests
 * pin that behaviour and, just as importantly, pin that the suppression is
 * scoped to the closure and does not mute events for the rest of the app.
 */
class InstallServiceMigrationTest extends FeatureTestCase
{
    private const PROBE_PREFIX = 'zz_migration_probe_';

    protected function tearDown(): void
    {
        Service::where('name', 'like', self::PROBE_PREFIX . '%')->delete();
        parent::tearDown();
    }

    private function makeService(string $suffix): Service
    {
        return Service::create([
            'name'        => self::PROBE_PREFIX . $suffix,
            'label'       => 'migration probe',
            'description' => 'temporary fixture',
            'type'        => 'api_builder',
            'is_active'   => true,
            'config'      => [],
        ]);
    }

    public function test_the_migration_left_the_api_builder_service_in_place(): void
    {
        $service = Service::whereName(Installer::MANAGEMENT_SERVICE)->first();

        $this->assertNotNull(
            $service,
            'The api_builder service is missing. Without it the admin UI dead-ends on '
            . '"Could not find a service for api_builder".'
        );
        $this->assertSame('api_builder', $service->type);
    }

    /**
     * The real guard: run the migration's own up() and assert it provisions the
     * service silently. Fails if someone unwraps Service::withoutEvents() in
     * 2026_06_11_000001_create_api_builder_service.
     *
     * Deliberately NOT wrapped in a transaction. Eloquent defers model events
     * until commit, so running this inside a transaction that gets rolled back
     * swallows the very event we are asserting on and the test can never fail.
     * Verified 2026-08-17: with the wrapper removed, the transactional version
     * still passed while this version fails as it should.
     *
     * Safe to delete the row first: up() is idempotent and recreates it, so the
     * database is left exactly as it was found.
     */
    public function test_the_migration_provisions_the_service_without_firing_events(): void
    {
        $path = __DIR__ . '/../../database/migrations/2026_06_11_000001_create_api_builder_service.php';
        $this->assertFileExists($path);

        $migration = require $path;

        $fired = [];
        Event::listen(ServiceModifiedEvent::class, function ($event) use (&$fired) {
            $fired[] = $event->service->name ?? 'unknown';
        });

        // Clear the row so up() actually provisions rather than returning early.
        Service::withoutEvents(
            static fn () => Service::whereName(Installer::MANAGEMENT_SERVICE)->delete()
        );
        $this->assertDatabaseMissing('service', ['name' => Installer::MANAGEMENT_SERVICE]);

        try {
            $migration->up();
        } finally {
            // Guarantee the service is back even if up() threw.
            Service::withoutEvents(static fn () => Installer::ensureManagementService());
        }

        $this->assertDatabaseHas('service', ['name' => Installer::MANAGEMENT_SERVICE]);
        $this->assertSame(
            [],
            $fired,
            'The api_builder migration fired ServiceModifiedEvent. Subscribers read their '
            . 'own tables, and df-alerts\' alert_rules is created by a migration timestamped '
            . '11 days later -- a fresh install will die on SQLSTATE[42S02]. Keep the '
            . 'Service::withoutEvents() wrapper in up().'
        );
    }

    public function test_suppression_still_writes_the_row(): void
    {
        Service::withoutEvents(fn () => $this->makeService('written'));

        $this->assertDatabaseHas('service', ['name' => self::PROBE_PREFIX . 'written']);
    }

    public function test_suppression_is_scoped_and_does_not_mute_later_events(): void
    {
        $fired = [];
        Event::listen(ServiceModifiedEvent::class, function ($event) use (&$fired) {
            $fired[] = $event->service->name ?? 'unknown';
        });

        $this->makeService('before');
        Service::withoutEvents(fn () => $this->makeService('muted'));
        $this->makeService('after');

        $this->assertSame(
            [self::PROBE_PREFIX . 'before', self::PROBE_PREFIX . 'after'],
            $fired,
            'withoutEvents() must only suppress inside its closure. Muting events globally '
            . 'would silently break df-alerts and anything else subscribed to service changes.'
        );
    }
}
