<?php

namespace DreamFactory\Core\ApiBuilder\Tests\Feature;

use DreamFactory\Core\ApiBuilder\Tests\FeatureTestCase;

/**
 * Confirms the Feature harness boots the app and the api_builder service type
 * is registered — the foundation the security/integration tests build on.
 */
class AppBootSmokeTest extends FeatureTestCase
{
    public function test_api_builder_service_type_is_registered(): void
    {
        $types = collect(app('df.service')->getServiceTypes())
            ->map(fn ($t) => $t->getName());

        $this->assertTrue($types->contains('api_builder'), 'api_builder type should be registered');
    }
}
