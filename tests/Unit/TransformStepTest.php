<?php

namespace DreamFactory\Core\ApiBuilder\Tests\Unit;

use DreamFactory\Core\ApiBuilder\Runtime\DefinitionExecutor;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The `transform` step reshapes a prior step's data in-memory (no backing
 * request) so an endpoint can shape its response without a script.
 */
class TransformStepTest extends TestCase
{
    private DefinitionExecutor $ex;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ex = (new ReflectionClass(DefinitionExecutor::class))->newInstanceWithoutConstructor();
    }

    private function op($data, array $op)
    {
        $m = new ReflectionMethod(DefinitionExecutor::class, 'applyTransformOp');
        $m->setAccessible(true);
        return $m->invoke($this->ex, $data, $op);
    }

    private function transform(array $step, array $context)
    {
        $m = new ReflectionMethod(DefinitionExecutor::class, 'executeTransformStep');
        $m->setAccessible(true);
        return $m->invoke($this->ex, $step, $context);
    }

    public function test_pick_keeps_only_listed_fields(): void
    {
        $rows = [['id' => 1, 'name' => 'A', 'secret' => 'x'], ['id' => 2, 'name' => 'B', 'secret' => 'y']];
        $this->assertSame([['id' => 1, 'name' => 'A'], ['id' => 2, 'name' => 'B']], $this->op($rows, ['op' => 'pick', 'fields' => ['id', 'name']]));
    }

    public function test_omit_drops_listed_fields(): void
    {
        $this->assertSame([['id' => 1]], $this->op([['id' => 1, 'secret' => 'x']], ['op' => 'omit', 'fields' => ['secret']]));
    }

    public function test_rename_maps_keys(): void
    {
        $this->assertSame([['customer_name' => 'A']], $this->op([['name' => 'A']], ['op' => 'rename', 'map' => ['name' => 'customer_name']]));
    }

    public function test_first_count_limit(): void
    {
        $rows = [['id' => 1], ['id' => 2], ['id' => 3]];
        $this->assertSame(['id' => 1], $this->op($rows, ['op' => 'first']));
        $this->assertSame(['count' => 3], $this->op($rows, ['op' => 'count']));
        $this->assertSame([['id' => 1], ['id' => 2]], $this->op($rows, ['op' => 'limit', 'count' => 2]));
    }

    public function test_resource_wrapped_list_is_handled(): void
    {
        $this->assertSame(['resource' => [['id' => 1]]], $this->op(['resource' => [['id' => 1, 'x' => 9]]], ['op' => 'pick', 'fields' => ['id']]));
    }

    public function test_wrap_and_unwrap(): void
    {
        $this->assertSame(['data' => [1, 2]], $this->op([1, 2], ['op' => 'wrap', 'key' => 'data']));
        $this->assertSame([1, 2], $this->op(['resource' => [1, 2]], ['op' => 'unwrap', 'key' => 'resource']));
    }

    public function test_defaults_fills_missing_only(): void
    {
        $this->assertSame([['id' => 1, 'status' => 'new'], ['id' => 2, 'status' => 'done']], $this->op(
            [['id' => 1], ['id' => 2, 'status' => 'done']],
            ['op' => 'defaults', 'values' => ['status' => 'new']]
        ));
    }

    public function test_unsupported_op_throws(): void
    {
        $this->expectExceptionMessage("Unsupported transform op 'bogus'");
        $this->op([], ['op' => 'bogus']);
    }

    public function test_execute_runs_ops_in_order_from_context(): void
    {
        $context = ['steps' => ['c' => ['resource' => [['id' => 1, 'name' => 'A', 'z' => 0], ['id' => 2, 'name' => 'B', 'z' => 0]]]]];
        $step = ['type' => 'transform', 'from' => '{steps.c.resource}', 'ops' => [
            ['op' => 'pick', 'fields' => ['id', 'name']],
            ['op' => 'rename', 'map' => ['name' => 'n']],
            ['op' => 'limit', 'count' => 1],
        ]];
        $this->assertSame([['id' => 1, 'n' => 'A']], $this->transform($step, $context));
    }
}
