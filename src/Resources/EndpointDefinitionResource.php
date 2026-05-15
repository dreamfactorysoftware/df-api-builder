<?php

namespace DreamFactory\Core\ApiBuilder\Resources;

use DreamFactory\Core\ApiBuilder\Models\EndpointDefinition;
use DreamFactory\Core\System\Resources\BaseSystemResource;

class EndpointDefinitionResource extends BaseSystemResource
{
    const RESOURCE_NAME = 'endpoints';

    protected static $model = EndpointDefinition::class;
}
