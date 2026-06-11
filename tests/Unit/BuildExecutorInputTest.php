<?php

namespace DreamFactory\Core\ApiBuilder\Tests\Unit;

use DreamFactory\Core\ApiBuilder\Services\ApiBuilder;
use DreamFactory\Core\Contracts\ServiceRequestInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Built-API requests must forward the caller's body and query into the
 * execution-plan input, so steps can resolve {body.*} / {query.*}.
 * Regression guard for the hardcoded body => [] bug.
 */
class BuildExecutorInputTest extends TestCase
{
    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    private function invoke(ServiceRequestInterface $request, array $pathParams): array
    {
        $svc = (new ReflectionClass(ApiBuilder::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(ApiBuilder::class, 'buildExecutorInput');

        return $method->invoke($svc, $request, $pathParams);
    }

    public function test_forwards_request_body(): void
    {
        $request = \Mockery::mock(ServiceRequestInterface::class);
        $request->shouldReceive('getParameters')->andReturn(['q' => '1']);
        $request->shouldReceive('getPayloadData')->andReturn(['hello' => 'world']);

        $input = $this->invoke($request, ['id' => '42']);

        $this->assertSame(['hello' => 'world'], $input['body'], 'caller body must reach the execution plan');
        $this->assertSame(['q' => '1'], $input['query']);
        $this->assertSame(['id' => '42'], $input['path']);
    }

    public function test_empty_body_stays_array(): void
    {
        $request = \Mockery::mock(ServiceRequestInterface::class);
        $request->shouldReceive('getParameters')->andReturn([]);
        $request->shouldReceive('getPayloadData')->andReturn(null);

        $input = $this->invoke($request, []);

        $this->assertSame([], $input['body'], 'null payload must normalize to an empty array, not null');
    }
}
