<?php

namespace DreamFactory\Core\ApiBuilder\Tests\Feature;

use DreamFactory\Core\ApiBuilder\Models\EndpointDefinition;
use DreamFactory\Core\ApiBuilder\Runtime\DefinitionExecutor;
use DreamFactory\Core\ApiBuilder\Tests\FeatureTestCase;
use DreamFactory\Core\Enums\ServiceTypeGroups;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\ForbiddenException;

class DefinitionExecutorSecurityTest extends FeatureTestCase
{
    protected function tearDown(): void
    {
        \Mockery::close();
        \ServiceManager::clearResolvedInstances();
        parent::tearDown();
    }

    private function endpoint(array $step): EndpointDefinition
    {
        $endpoint = new EndpointDefinition();
        $endpoint->execution_plan = ['steps' => [$step]];

        return $endpoint;
    }

    /**
     * Make every step service resolve to the given type group so the data-source
     * allowlist (assertAllowedStepService) passes and the behaviour under test is
     * reached. Defaults to a Database service (an allowed data source).
     */
    private function fakeServiceType(string $group = ServiceTypeGroups::DATABASE): void
    {
        $type = \Mockery::mock();
        $type->shouldReceive('getGroup')->andReturn($group);
        \ServiceManager::shouldReceive('getServiceTypeByName')->andReturn('mock_type');
        \ServiceManager::shouldReceive('getServiceType')->andReturn($type);
    }

    public function test_dry_run_does_not_dispatch_and_returns_planned_request(): void
    {
        $this->fakeServiceType();
        // No backing service should ever be invoked in dry-run.
        \ServiceManager::shouldReceive('handleServiceRequest')->never();

        $endpoint = $this->endpoint([
            'type'     => 'service_request',
            'service'  => 'orders_db',
            'resource' => '_table/orders',
            'method'   => 'POST',
            'body'     => '{body}',
        ]);

        $planned = (new DefinitionExecutor())->execute($endpoint, ['body' => ['x' => 1]], true);

        $this->assertSame('orders_db', $planned['service']);
        $this->assertSame('POST', $planned['method']);
        $this->assertSame(['x' => 1], $planned['body'], 'planned body should reflect the resolved input');
        $this->assertTrue($planned['dry_run']);
    }

    public function test_rejects_service_type_outside_data_allowlist(): void
    {
        // A system/script/auth service is never an allowed step target.
        $this->fakeServiceType(ServiceTypeGroups::SYSTEM);

        $endpoint = $this->endpoint([
            'type'    => 'service_request',
            'service' => 'system',
            'method'  => 'GET',
        ]);

        $this->expectException(ForbiddenException::class);
        (new DefinitionExecutor())->execute($endpoint, [], true);
    }

    public function test_rejects_method_outside_allowlist(): void
    {
        $this->fakeServiceType();

        $endpoint = $this->endpoint([
            'type'    => 'service_request',
            'service' => 'orders_db',
            'method'  => 'FETCH',
        ]);

        $this->expectException(BadRequestException::class);
        // dry-run so the rejection is proven to happen before any dispatch
        (new DefinitionExecutor())->execute($endpoint, [], true);
    }

    public function test_service_selector_is_not_caller_controlled(): void
    {
        $this->fakeServiceType();

        // A caller-supplied {body.svc} must NOT redirect which service is hit.
        $endpoint = $this->endpoint([
            'type'    => 'service_request',
            'service' => '{body.svc}',
            'method'  => 'GET',
        ]);

        $planned = (new DefinitionExecutor())->execute($endpoint, ['body' => ['svc' => 'secret_service']], true);

        $this->assertSame('{body.svc}', $planned['service'], 'service selector must be static, not interpolated from caller input');
    }

    public function test_enforces_caller_permissions_by_default(): void
    {
        $this->fakeServiceType();
        $captured = null;
        \ServiceManager::shouldReceive('handleServiceRequest')->once()
            ->andReturnUsing(function ($request, $service, $resource, $checkPermission) use (&$captured) {
                $captured = $checkPermission;
                return $this->fakeResponse();
            });

        $endpoint = $this->endpoint(['type' => 'service_request', 'service' => 'orders_db', 'method' => 'GET']);
        (new DefinitionExecutor())->execute($endpoint, []);

        $this->assertTrue($captured, 'caller permissions must be enforced by default');
    }

    public function test_privileged_policy_bypasses_permission_check(): void
    {
        $this->fakeServiceType();
        $captured = null;
        \ServiceManager::shouldReceive('handleServiceRequest')->once()
            ->andReturnUsing(function ($request, $service, $resource, $checkPermission) use (&$captured) {
                $captured = $checkPermission;
                return $this->fakeResponse();
            });

        $endpoint = $this->endpoint(['type' => 'service_request', 'service' => 'orders_db', 'method' => 'GET']);
        $endpoint->policy = ['privileged' => true];
        (new DefinitionExecutor())->execute($endpoint, []);

        $this->assertFalse($captured, 'privileged policy should dispatch without permission checks');
    }

    private function fakeResponse(int $status = 200, $content = [])
    {
        $response = \Mockery::mock(\DreamFactory\Core\Contracts\ServiceResponseInterface::class);
        $response->shouldReceive('getStatusCode')->andReturn($status);
        $response->shouldReceive('getContent')->andReturn($content);

        return $response;
    }
}
