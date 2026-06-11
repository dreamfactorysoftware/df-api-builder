<?php

namespace DreamFactory\Core\ApiBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Proves the harness runs: the app autoloader resolves package classes and
 * PHPUnit executes from inside the dev container. Pure unit, no app boot.
 */
class SanityTest extends TestCase
{
    public function test_harness_runs(): void
    {
        $this->assertTrue(true);
    }

    public function test_package_classes_autoload(): void
    {
        $this->assertTrue(
            class_exists(\DreamFactory\Core\ApiBuilder\Runtime\DefinitionExecutor::class),
            'df-api-builder classes should autoload via the app vendor tree'
        );
    }
}
