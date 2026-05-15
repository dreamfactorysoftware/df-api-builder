<?php

namespace DreamFactory\Core\ApiBuilder\Resources;

use DreamFactory\Core\ApiBuilder\Models\EndpointDefinition;
use DreamFactory\Core\ApiBuilder\Runtime\DefinitionExecutor;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Resources\BaseRestResource;

class TestResource extends BaseRestResource
{
    const RESOURCE_NAME = 'test';

    protected function handlePOST()
    {
        $payload = $this->getPayloadData();
        $endpointId = array_get($payload, 'endpoint_id', array_get($payload, 'endpointId'));

        if (!empty($endpointId)) {
            $endpoint = EndpointDefinition::find($endpointId);
            if (!$endpoint) {
                throw new BadRequestException("Endpoint definition '{$endpointId}' was not found.");
            }
        } else {
            $definition = (array)array_get($payload, 'endpoint', []);
            if (empty($definition)) {
                throw new BadRequestException('endpoint_id or endpoint definition is required.');
            }

            $endpoint = new EndpointDefinition();
            $endpoint->forceFill($definition);
        }

        $executor = new DefinitionExecutor();

        return $executor->execute($endpoint, [
            'path'  => (array)array_get($payload, 'path_params', []),
            'query' => (array)array_get($payload, 'query', []),
            'body'  => (array)array_get($payload, 'body', []),
        ]);
    }
}
