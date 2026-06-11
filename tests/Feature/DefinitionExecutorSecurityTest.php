<?php

namespace DreamFactory\Core\ApiBuilder\Tests\Feature;

use DreamFactory\Core\ApiBuilder\Models\EndpointDefinition;
use DreamFactory\Core\ApiBuilder\Runtime\DefinitionExecutor;
use DreamFactory\Core\ApiBuilder\Tests\FeatureTestCase;
use DreamFactory\Core\Exceptions\BadRequestException;

class DefinitionExecutorSecurityTest extends FeatureTestCase
{
    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    private function endpoint(array $step): EndpointDefinition
    {
        $endpoint = new EndpointDefinition();
        $endpoint->execution_plan = ['steps' => [$step]];

        return $endpoint;
    }

    public function test_dry_run_does_not_dispatch_and_returns_planned_request(): void
    {
        // No backing service should ever be invoked in dry-run.
        \ServiceManager::shouldReceive('handleServiceRequest')->never();

        $endpoint = $this->endpoint([
            'type'     => 'service_request',
            'service'  => 'system',
            'resource' => 'admin',
            'method'   => 'POST',
            'body'     => '{body}',
        ]);

        $planned = (new DefinitionExecutor())->execute($endpoint, ['body' => ['x' => 1]], true);

        $this->assertSame('system', $planned['service']);
        $this->assertSame('POST', $planned['method']);
        $this->assertSame(['x' => 1], $planned['body'], 'planned body should reflect the resolved input');
        $this->assertTrue($planned['dry_run']);
    }

    public function test_rejects_method_outside_allowlist(): void
    {
        $endpoint = $this->endpoint([
            'type'    => 'service_request',
            'service' => 'system',
            'method'  => 'FETCH',
        ]);

        $this->expectException(BadRequestException::class);
        // dry-run so the rejection is proven to happen before any dispatch
        (new DefinitionExecutor())->execute($endpoint, [], true);
    }
}
