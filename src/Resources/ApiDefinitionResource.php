<?php

namespace DreamFactory\Core\ApiBuilder\Resources;

use DreamFactory\Core\ApiBuilder\Models\ApiDefinition;
use DreamFactory\Core\System\Resources\BaseSystemResource;

class ApiDefinitionResource extends BaseSystemResource
{
    const RESOURCE_NAME = 'apis';

    protected static $model = ApiDefinition::class;
}
