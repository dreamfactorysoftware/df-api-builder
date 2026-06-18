<?php

namespace DreamFactory\Core\ApiBuilder\Tests\Feature;

use DreamFactory\Core\ApiBuilder\Models\ApiDefinition;
use DreamFactory\Core\ApiBuilder\Models\ApiServiceLink;
use DreamFactory\Core\ApiBuilder\Tests\FeatureTestCase;
use DreamFactory\Core\Components\Service2ServiceRequest;
use DreamFactory\Core\Enums\DataFormats;
use DreamFactory\Core\Enums\Verbs;
use DreamFactory\Core\Models\Service;
use DreamFactory\Core\Models\User;
use DreamFactory\Core\Utility\Session;

/**
 * End-to-end proof that a custom API can compose two different database services
 * (MySQL + PostgreSQL) and cross-reference records across them — exercising the
 * workspace, the relationship-authoring resource (with its automatic schema-cache
 * flush), workspace least-privilege, and the built-API runtime.
 *
 * Runs against the dev container's live services (test_mysql / test_pgsql). If
 * those are absent the test skips rather than fails.
 *
 * Uses an orders.id -> pgsql.orders.id mapping on purpose: distinct from any
 * manually-created demo relationship (orders -> customers), so re-running the
 * suite never disturbs demo data.
 */
class CrossServiceCompositionTest extends FeatureTestCase
{
    private $mysqlId;
    private $pgsqlId;
    private $api;
    private $relationshipId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mysqlId = Service::whereName('test_mysql')->value('id');
        $this->pgsqlId = Service::whereName('test_pgsql')->value('id');
        if (empty($this->mysqlId) || empty($this->pgsqlId)) {
            $this->markTestSkipped('test_mysql/test_pgsql services are not configured on this stack.');
        }

        $admin = User::where('is_sys_admin', 1)->first();
        if (empty($admin)) {
            $this->markTestSkipped('No system administrator user available.');
        }
        Session::setUserInfoWithJWT($admin);

        $this->api = ApiDefinition::create([
            'name'      => 'xs_itest',
            'label'     => 'Cross-service itest',
            'base_path' => 'xs_itest',
            'status'    => 'published',
        ]);

        ApiServiceLink::create(['api_id' => $this->api->id, 'service_id' => $this->mysqlId]);
        ApiServiceLink::create(['api_id' => $this->api->id, 'service_id' => $this->pgsqlId]);
    }

    protected function tearDown(): void
    {
        if ($this->relationshipId) {
            try {
                $this->apiBuilder(Verbs::DELETE, 'relationships/' . $this->relationshipId);
            } catch (\Throwable $e) {
            }
        }
        if ($this->api) {
            try {
                $this->api->delete();
            } catch (\Throwable $e) {
            }
        }
        Session::setUserInfoWithJWT(User::where('is_sys_admin', 1)->first());
        \Mockery::close();
        \ServiceManager::clearResolvedInstances();
        parent::tearDown();
    }

    /** The relationship under test — a distinct mapping that won't touch demo data. */
    private function relPayload(): array
    {
        return [
            'api_id'      => $this->api->id,
            'service'     => 'test_mysql',
            'table'       => 'orders',
            'name'        => 'xs_porder',
            'type'        => 'belongs_to',
            'field'       => 'id',
            'ref_service' => 'test_pgsql',
            'ref_table'   => 'orders',
            'ref_field'   => 'id',
        ];
    }

    public function test_relationship_create_resolves_cross_service_without_manual_cache_clear(): void
    {
        $created = $this->apiBuilder(Verbs::POST, 'relationships', $this->relPayload());
        $this->relationshipId = $created['id'] ?? null;
        $this->assertNotNull($this->relationshipId, 'relationship should be created');

        // No manual cache clear — the resource's purge() must have flushed it.
        $orders = $this->callService('test_mysql', '_table/orders', [
            'limit'   => 3,
            'fields'  => 'id',
            'related' => 'xs_porder',
        ]);

        $rows = $orders['resource'] ?? [];
        $this->assertNotEmpty($rows);
        $stitched = 0;
        foreach ($rows as $r) {
            if (is_array($r['xs_porder'] ?? null) && !empty($r['xs_porder'])) {
                $stitched++;
            }
        }
        $this->assertGreaterThan(0, $stitched, 'a pgsql record should be stitched onto mysql orders with no manual cache clear');
    }

    public function test_relationship_rejects_service_outside_workspace(): void
    {
        // 'system' is not in this API's workspace.
        $resp = $this->apiBuilderRaw(Verbs::POST, 'relationships', [
            'api_id'      => $this->api->id,
            'service'     => 'test_mysql',
            'table'       => 'orders',
            'type'        => 'belongs_to',
            'field'       => 'id',
            'ref_service' => 'system',
            'ref_table'   => 'admin',
            'ref_field'   => 'id',
        ]);

        $this->assertSame(403, $resp['status'], 'relating across a non-workspace service must be forbidden');
    }

    public function test_built_endpoint_returns_cross_service_data(): void
    {
        $created = $this->apiBuilder(Verbs::POST, 'relationships', $this->relPayload());
        $this->relationshipId = $created['id'] ?? null;

        $this->apiBuilder(Verbs::POST, 'endpoints', [
            'resource' => [[
                'api_id'           => $this->api->id,
                'method'           => 'GET',
                'path'             => '/orders_xs',
                'is_active'        => true,
                'execution_plan'   => ['steps' => [[
                    'id'       => 'orders',
                    'type'     => 'service_request',
                    'service'  => 'test_mysql',
                    'method'   => 'GET',
                    'resource' => '_table/orders',
                    'params'   => ['fields' => 'id', 'related' => 'xs_porder', 'limit' => '3'],
                    'aliases'  => ['xs_porder' => 'pg'],
                ]]],
                'response_mapping' => ['orders' => '{steps.orders.resource}'],
            ]],
        ]);

        $result = $this->callService('xs_itest', 'orders_xs', []);
        $rows = $result['orders'] ?? [];
        $this->assertNotEmpty($rows, 'built endpoint should return rows');
        $found = false;
        foreach ($rows as $r) {
            if (is_array($r['pg'] ?? null) && !empty($r['pg'])) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'built endpoint should return a pgsql record stitched onto mysql orders');
    }

    private function apiBuilder(string $verb, string $resource, ?array $payload = null, array $params = [])
    {
        return $this->apiBuilderRaw($verb, $resource, $payload, $params)['content'];
    }

    private function apiBuilderRaw(string $verb, string $resource, ?array $payload = null, array $params = []): array
    {
        return $this->dispatch($verb, 'api_builder', $resource, $payload, $params);
    }

    private function callService(string $service, string $resource, array $params): array
    {
        $resp = $this->dispatch(Verbs::GET, $service, $resource, null, $params);
        return is_array($resp['content']) ? $resp['content'] : ['content' => $resp['content']];
    }

    private function dispatch(string $verb, string $service, string $resource, ?array $payload, array $params): array
    {
        $request = new Service2ServiceRequest($verb, $params);
        if ($payload !== null) {
            $request->setContent($payload, DataFormats::PHP_ARRAY);
        }
        $response = \ServiceManager::handleServiceRequest($request, $service, $resource, true);
        $content = $response->getContent();
        if (is_string($content)) {
            $decoded = json_decode($content, true);
            $content = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $content;
        }

        return ['status' => $response->getStatusCode(), 'content' => $content];
    }
}
