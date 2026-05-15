<?php

namespace DreamFactory\Core\ApiBuilder\Resources;

use DreamFactory\Core\ApiBuilder\Models\ApiDefinition;
use DreamFactory\Core\ApiBuilder\Support\OpenApiFactory;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Resources\BaseRestResource;

class DocsResource extends BaseRestResource
{
    const RESOURCE_NAME = 'docs';

    protected function handleGET()
    {
        $apiId = $this->resource;
        if (empty($apiId)) {
            return (new OpenApiFactory())->make(null, true);
        }

        $api = ApiDefinition::find($apiId);
        if (!$api) {
            throw new BadRequestException("API definition '{$apiId}' was not found.");
        }

        return (new OpenApiFactory())->make($api, true);
    }
}
