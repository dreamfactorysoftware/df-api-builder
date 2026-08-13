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

        // Rate limit the test endpoint to prevent abuse. 10 requests per minute per IP.
        $rateLimit = Session::get('rate_limit', []);
        $now = time();
        $windowStart = $now - 60;

        // Clean old entries
        foreach ($rateLimit as $k => &$v) {
            if (is_array($v)) {
                $timestamps = array_values(array_filter($v, fn($t) => (int)$t > $windowStart));
                if (empty($timestamps)) {
                    unset($rateLimit[$k]);
                    continue;
                }
                $v = $timestamps;
            }
        }

        // Count this IP's requests in the window
        // ServiceRequest exposes no client-ip accessor; read the underlying
        // Illuminate request (null outside HTTP, e.g. script-invoked calls).
        $ip = request()->ip() ?? 'unknown';
        if (!isset($rateLimit[$ip])) {
            $rateLimit[$ip] = [];
        }
        $recentCount = count(array_values(array_filter($rateLimit[$ip], fn($t) => (int)$t > $windowStart)));

        if ($recentCount >= 10) {
            throw new BadRequestException('Test endpoint rate limit exceeded. Try again later.');
        }

        $rateLimit[$ip][] = $now;
        Session::put('rate_limit', $rateLimit);

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

        $input = [
            'path'  => (array)array_get($payload, 'path_params', []),
            'query' => (array)array_get($payload, 'query', []),
            'body'  => (array)array_get($payload, 'body', []),
        ];

        // Opt-in step-by-step trace for the Test panel. Returns an envelope with
        // per-step results and surfaces the failing step instead of a bare error.
        // Legacy callers (no `trace`) keep the raw result shape.
        if (array_get($payload, 'trace', false)) {
            try {
                $result = $executor->execute($endpoint, $input, $dryRun);
                return ['ok' => true, 'dry_run' => $dryRun, 'result' => $result, 'trace' => $executor->trace];
            } catch (\Throwable $ex) {
                return ['ok' => false, 'dry_run' => $dryRun, 'error' => $ex->getMessage(), 'trace' => $executor->trace];
            }
        }

        return $executor->execute($endpoint, $input, $dryRun);
    }
}
