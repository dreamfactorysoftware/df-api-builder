<?php

namespace DreamFactory\Core\ApiBuilder\Resources;

use DreamFactory\Core\ApiBuilder\Models\EndpointDefinition;
use DreamFactory\Core\ApiBuilder\Runtime\DefinitionExecutor;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\ForbiddenException;
use DreamFactory\Core\Resources\BaseRestResource;
use DreamFactory\Core\Utility\Session;

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

            // fill() (not forceFill) so only whitelisted fields from untrusted
            // input are applied — never mass-assign arbitrary attributes.
            $endpoint = new EndpointDefinition();
            $endpoint->fill($definition);
        }

        // Running a privileged (permission-bypassing) plan is a system-admin-only
        // operation, whether it comes from a saved endpoint or an inline test
        // definition — otherwise the test runner is a one-shot RBAC bypass.
        if (
            EndpointDefinition::policyIsPrivileged($endpoint->policy)
            && !Session::isSysAdmin()
        ) {
            throw new ForbiddenException(
                'Only a system administrator may run a privileged '
                . '(permission-bypassing) endpoint.'
            );
        }

        $executor = new DefinitionExecutor();

        // Safe by default: testing a definition resolves the plan without
        // dispatching to backing services. Pass "dry_run": false to execute live.
        $dryRun = (bool)array_get($payload, 'dry_run', true);

        return $executor->execute($endpoint, [
            'path'  => (array)array_get($payload, 'path_params', []),
            'query' => (array)array_get($payload, 'query', []),
            'body'  => (array)array_get($payload, 'body', []),
        ], $dryRun);
    }
}
