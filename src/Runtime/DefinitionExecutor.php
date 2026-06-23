<?php

namespace DreamFactory\Core\ApiBuilder\Runtime;

use DreamFactory\Core\ApiBuilder\Models\ApiServiceLink;
use DreamFactory\Core\ApiBuilder\Models\EndpointDefinition;
use DreamFactory\Core\Models\Service;
use DreamFactory\Core\Enums\DataFormats;
use DreamFactory\Core\Enums\ServiceTypeGroups;
use DreamFactory\Core\Enums\Verbs;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\ForbiddenException;
use DreamFactory\Core\Exceptions\RestException;
use ServiceManager;

class DefinitionExecutor
{
    /**
     * Service type groups a plan step may target. Restricted to data sources so
     * a plan can never dispatch to system/admin, scripted (RCE), auth, email,
     * or the API Builder itself.
     */
    protected const ALLOWED_STEP_GROUPS = [
        ServiceTypeGroups::DATABASE,
        ServiceTypeGroups::FILE,
        ServiceTypeGroups::REMOTE,
    ];

    /** Cap on steps per plan — each step dispatches a real backing request. */
    protected const MAX_STEPS = 25;

    /**
     * Lower-cased service names this endpoint's API workspace permits, or null
     * when the API has no workspace defined (then only the type allowlist below
     * applies — preserves behaviour for APIs created before workspaces existed).
     *
     * @var string[]|null
     */
    protected $workspaceServices = null;

    /** Per-step execution trace (key/type/target/status/preview/ms) for the test UI. */
    public array $trace = [];

    /** HTTP verbs a service_request step is allowed to invoke. */
    protected const ALLOWED_METHODS = [
        Verbs::GET,
        Verbs::POST,
        Verbs::PUT,
        Verbs::PATCH,
        Verbs::DELETE,
    ];

    /**
     * @param bool $dryRun When true, steps are resolved and validated but no
     *                     backing service is dispatched (no live side effects).
     */
    public function execute(EndpointDefinition $endpoint, array $input = [], bool $dryRun = false)
    {
        $this->workspaceServices = $this->resolveWorkspace($endpoint);
        $this->trace = [];

        $plan = (array)$endpoint->execution_plan;
        $steps = (array)array_get($plan, 'steps', []);
        if (empty($steps)) {
            throw new BadRequestException('Endpoint execution_plan.steps must contain at least one step.');
        }
        if (count($steps) > static::MAX_STEPS) {
            throw new BadRequestException(
                'Endpoint execution_plan has too many steps (max '
                . static::MAX_STEPS . '). Each step dispatches a backing request.'
            );
        }

        $context = [
            'path'  => (array)array_get($input, 'path', []),
            'query' => (array)array_get($input, 'query', []),
            'body'  => (array)array_get($input, 'body', []),
            'steps' => [],
        ];

        // By default a built API enforces the caller's permissions on the
        // backing services it composes. An endpoint may opt into privileged
        // (gateway) dispatch via its policy: {"privileged": true}.
        $policy = (array)$endpoint->policy;
        $checkPermission = !(bool)array_get($policy, 'privileged', false);

        $last = null;
        foreach ($steps as $index => $step) {
            $type = array_get($step, 'type', 'service_request');
            $key = array_get($step, 'output_key', array_get($step, 'id', 'step_' . $index));
            $entry = [
                'key'      => $key,
                'type'     => $type,
                'service'  => array_get($step, 'service'),
                'resource' => array_get($step, 'resource', ''),
                'method'   => strtoupper((string)array_get($step, 'method', Verbs::GET)),
            ];
            $started = microtime(true);
            try {
                if ($type !== 'service_request') {
                    throw new BadRequestException("Unsupported execution step type '{$type}'.");
                }
                $last = $this->executeServiceRequestStep($step, $context, $dryRun, $checkPermission);
                $context['steps'][$key] = $last;
                $entry['ok'] = true;
                $entry['preview'] = $this->previewResult($last);
            } catch (\Throwable $ex) {
                $entry['ok'] = false;
                $entry['error'] = $ex->getMessage();
                $entry['ms'] = (int)round((microtime(true) - $started) * 1000);
                $this->trace[] = $entry;
                throw $ex;
            }
            $entry['ms'] = (int)round((microtime(true) - $started) * 1000);
            $this->trace[] = $entry;
        }

        $mapping = (array)$endpoint->response_mapping;
        if (!empty($mapping)) {
            return $this->resolveValue($mapping, $context);
        }

        return $last;
    }

    /** Short human-readable summary of a step result for the test trace. */
    protected function previewResult($result): string
    {
        if (is_array($result)) {
            if (array_key_exists('dry_run', $result)) {
                return 'dry-run: ' . ($result['method'] ?? '') . ' ' . ($result['service'] ?? '') . '/' . ($result['resource'] ?? '');
            }
            $rows = $result['resource'] ?? null;
            if (is_array($rows) && array_is_list($rows)) {
                return count($rows) . ' row(s)';
            }
            $json = json_encode($result);
            return strlen($json) > 120 ? substr($json, 0, 120) . '…' : $json;
        }
        $s = is_scalar($result) ? (string)$result : gettype($result);
        return strlen($s) > 120 ? substr($s, 0, 120) . '…' : $s;
    }

    protected function executeServiceRequestStep(array $step, array $context, bool $dryRun = false, bool $checkPermission = true)
    {
        // Selectors (which service/resource/method to invoke) are admin-authored
        // and STATIC — never interpolated from caller input — so a caller cannot
        // redirect the call to an internal service (SSRF). Only params/body below
        // resolve caller-derived data.
        $service = array_get($step, 'service');
        $resource = array_get($step, 'resource', '');
        $method = strtoupper((string)array_get($step, 'method', Verbs::GET));
        $params = (array)$this->resolveValue((array)array_get($step, 'params', []), $context);
        $body = $this->resolveValue(array_get($step, 'body'), $context);

        if (empty($service)) {
            throw new BadRequestException('service_request step requires service.');
        }

        $this->assertAllowedStepService($service);

        if (!in_array($method, static::ALLOWED_METHODS, true)) {
            throw new BadRequestException("Unsupported HTTP method '{$method}' in service_request step.");
        }

        $sendsBody = !is_null($body) && !in_array($method, [Verbs::GET, Verbs::DELETE], true);

        if ($dryRun) {
            return [
                'dry_run'  => true,
                'service'  => $service,
                'resource' => $resource,
                'method'   => $method,
                'params'   => $params,
                'body'     => $sendsBody ? $body : null,
            ];
        }

        $request = new ApiBuilderServiceRequest($method, $params);
        if ($sendsBody) {
            $request->setContent($body, DataFormats::PHP_ARRAY);
        }

        $response = ServiceManager::handleServiceRequest($request, $service, $resource, $checkPermission);
        $status = $response->getStatusCode();
        $content = $this->normalizeResponseContent($response->getContent());

        if ($status < 200 || $status >= 300) {
            $message = "Service request step failed with status {$status}.";
            if (is_array($content) && isset($content['error']['message'])) {
                $message = $content['error']['message'];
            }
            throw new RestException($status, $message);
        }

        // Output rename: a flat {source_name: alias} map on the step renames the
        // matching keys on each returned row (fields AND relationship keys). This
        // is a single in-memory key-remap over rows already fetched — no extra
        // query — so its cost is O(rows × renamed-keys), negligible vs the query.
        $aliases = (array)array_get($step, 'aliases', []);
        if (!empty($aliases)) {
            $content = $this->applyAliases($content, $aliases);
        }

        return $content;
    }

    protected function applyAliases($content, array $aliases)
    {
        if (!is_array($content)) {
            return $content;
        }

        // List wrapper: {"resource": [ ...rows... ]}
        if (isset($content['resource']) && is_array($content['resource'])) {
            $content['resource'] = array_map(
                fn($row) => $this->renameKeys($row, $aliases),
                $content['resource']
            );

            return $content;
        }

        // Bare list of rows.
        if ($content === [] || array_keys($content) === range(0, count($content) - 1)) {
            return array_map(fn($row) => $this->renameKeys($row, $aliases), $content);
        }

        // Single record.
        return $this->renameKeys($content, $aliases);
    }

    protected function renameKeys($row, array $aliases)
    {
        if (!is_array($row)) {
            return $row;
        }

        $out = [];
        foreach ($row as $key => $value) {
            $out[$aliases[$key] ?? $key] = $value;
        }

        return $out;
    }

    /**
     * Lower-cased names of the services in this endpoint's API workspace, or null
     * if the API has no workspace (unrestricted apart from the type allowlist).
     */
    protected function resolveWorkspace(EndpointDefinition $endpoint): ?array
    {
        $apiId = $endpoint->api_id;
        if (empty($apiId)) {
            return null;
        }

        $serviceIds = ApiServiceLink::where('api_id', $apiId)->pluck('service_id')->all();
        if (empty($serviceIds)) {
            return null;
        }

        return Service::whereIn('id', $serviceIds)
            ->pluck('name')
            ->map(function ($n) { return strtolower((string)$n); })
            ->all();
    }

    protected function assertAllowedStepService(string $service): void
    {
        // When the API defines a workspace, a step may only target a service in
        // it — the composition boundary the designer declared. The type allowlist
        // below still applies as a floor (no system/script/auth even if added).
        if (
            is_array($this->workspaceServices)
            && !in_array(strtolower($service), $this->workspaceServices, true)
        ) {
            throw new ForbiddenException(
                "Execution step service '{$service}' is not in this API's workspace."
            );
        }

        $typeName = ServiceManager::getServiceTypeByName($service);
        $typeInfo = $typeName ? ServiceManager::getServiceType($typeName) : null;
        $group = $typeInfo ? $typeInfo->getGroup() : null;

        if (!in_array($group, static::ALLOWED_STEP_GROUPS, true)) {
            throw new ForbiddenException(
                "Execution step service '{$service}'"
                . ($typeName ? " (type '{$typeName}')" : '')
                . ' is not an allowed data source. API Builder steps may only '
                . 'target database, file, or remote API services.'
            );
        }
    }

    protected function normalizeResponseContent($content)
    {
        if (!is_string($content)) {
            return $content;
        }

        $decoded = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return $content;
    }

    protected function resolveValue($value, array $context)
    {
        if (is_array($value)) {
            $resolved = [];
            foreach ($value as $key => $item) {
                $resolved[$key] = $this->resolveValue($item, $context);
            }

            return $resolved;
        }

        if (!is_string($value)) {
            return $value;
        }

        if (preg_match('/^\{([^}]+)\}$/', $value, $exactMatch)) {
            return data_get($context, trim($exactMatch[1]));
        }

        return preg_replace_callback('/\{([^}]+)\}/', function ($matches) use ($context, $value) {
            $path = trim($matches[1]);
            $resolved = data_get($context, $path);
            if ($resolved === null) {
                return '';
            }

            return is_scalar($resolved) ? (string)$resolved : json_encode($resolved);
        }, $value);
    }
}
