<?php

namespace DreamFactory\Core\ApiBuilder\Runtime;

use DreamFactory\Core\ApiBuilder\Models\EndpointDefinition;
use DreamFactory\Core\Enums\DataFormats;
use DreamFactory\Core\Enums\Verbs;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\RestException;
use ServiceManager;

class DefinitionExecutor
{
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
        $plan = (array)$endpoint->execution_plan;
        $steps = (array)array_get($plan, 'steps', []);
        if (empty($steps)) {
            throw new BadRequestException('Endpoint execution_plan.steps must contain at least one step.');
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
            if ($type !== 'service_request') {
                throw new BadRequestException("Unsupported execution step type '{$type}'.");
            }

            $key = array_get($step, 'output_key', array_get($step, 'id', 'step_' . $index));
            $last = $this->executeServiceRequestStep($step, $context, $dryRun, $checkPermission);
            $context['steps'][$key] = $last;
        }

        $mapping = (array)$endpoint->response_mapping;
        if (!empty($mapping)) {
            return $this->resolveValue($mapping, $context);
        }

        return $last;
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

        return $content;
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
