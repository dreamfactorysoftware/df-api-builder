<?php

namespace DreamFactory\Core\ApiBuilder\Services;

use DreamFactory\Core\ApiBuilder\Models\ApiDefinition;
use DreamFactory\Core\ApiBuilder\Models\EndpointDefinition;
use DreamFactory\Core\ApiBuilder\Resources\DocsResource;
use DreamFactory\Core\ApiBuilder\Resources\ApiDefinitionResource;
use DreamFactory\Core\ApiBuilder\Resources\EndpointDefinitionResource;
use DreamFactory\Core\ApiBuilder\Resources\RelationshipResource;
use DreamFactory\Core\ApiBuilder\Resources\TestResource;
use DreamFactory\Core\ApiBuilder\Resources\WorkspaceResource;
use DreamFactory\Core\ApiBuilder\Runtime\DefinitionExecutor;
use DreamFactory\Core\ApiBuilder\Support\OpenApiFactory;
use DreamFactory\Core\Contracts\ServiceRequestInterface;
use DreamFactory\Core\Exceptions\ForbiddenException;
use DreamFactory\Core\Utility\ResponseFactory;
use DreamFactory\Core\Utility\Session;
use DreamFactory\Core\Services\BaseRestService;

class ApiBuilder extends BaseRestService
{
    protected static $resources = [
        ApiDefinitionResource::RESOURCE_NAME => [
            'name'       => ApiDefinitionResource::RESOURCE_NAME,
            'class_name' => ApiDefinitionResource::class,
            'label'      => 'APIs',
        ],
        EndpointDefinitionResource::RESOURCE_NAME => [
            'name'       => EndpointDefinitionResource::RESOURCE_NAME,
            'class_name' => EndpointDefinitionResource::class,
            'label'      => 'Endpoints',
        ],
        WorkspaceResource::RESOURCE_NAME => [
            'name'       => WorkspaceResource::RESOURCE_NAME,
            'class_name' => WorkspaceResource::class,
            'label'      => 'Workspace',
        ],
        RelationshipResource::RESOURCE_NAME => [
            'name'       => RelationshipResource::RESOURCE_NAME,
            'class_name' => RelationshipResource::class,
            'label'      => 'Relationships',
        ],
        TestResource::RESOURCE_NAME => [
            'name'       => TestResource::RESOURCE_NAME,
            'class_name' => TestResource::class,
            'label'      => 'Test',
        ],
        DocsResource::RESOURCE_NAME => [
            'name'       => DocsResource::RESOURCE_NAME,
            'class_name' => DocsResource::class,
            'label'      => 'Docs',
        ],
    ];

    public function handleRequest(ServiceRequestInterface $request, $resource = null)
    {
        if ($resource === '_spec') {
            return parent::handleRequest($request, $resource);
        }

        // Access management (role editor) requests the component list via
        // GET /?as_access_list=true; that must reach the base handler and
        // never be dispatched as a built-API runtime call.
        if (empty($resource) && $request->getParameterAsBool('as_access_list')) {
            return parent::handleRequest($request, $resource);
        }

        $firstSegment = explode('/', trim((string)$resource, '/'))[0] ?? '';
        if ($this->name !== 'api_builder' || ($resource && !array_key_exists($firstSegment, static::$resources))) {
            $result = $this->handleBuiltApiRequest($request, $resource);
            return ResponseFactory::create($result);
        }

        // Designer/management resources (apis, endpoints, test, docs) build and
        // run API definitions — which provisions backing services and defines
        // permission-sensitive endpoints. That is a system-administrator
        // capability, gated here. The built-API RUNTIME path above is NOT gated:
        // end users still call published endpoints under their own role.
        if (!Session::isSysAdmin()) {
            throw new ForbiddenException(
                'API Builder management is restricted to system administrators.'
            );
        }

        return parent::handleRequest($request, $resource);
    }

    public function getAccessList()
    {
        // A built API's components are its own endpoint paths — not the
        // designer resources, and not other APIs' base paths.
        if ($this->name !== 'api_builder') {
            $resources = ['', '*'];
            $api = ApiDefinition::query()
                ->where('base_path', $this->name)
                ->where('status', '!=', 'archived')
                ->first();
            if ($api) {
                $paths = EndpointDefinition::query()
                    ->where('api_id', $api->id)
                    ->where('is_active', true)
                    ->pluck('path');
                foreach ($paths as $path) {
                    $path = trim($path, '/');
                    $resources[] = $path;
                    $resources[] = $path . '/*';
                }
            }

            return array_values(array_unique($resources));
        }

        $resources = parent::getAccessList();
        foreach (static::$resources as $name => $info) {
            $resources[] = $name . '/';
            $resources[] = $name . '/*';
        }
        foreach (ApiDefinition::query()->where('status', '!=', 'archived')->get() as $api) {
            $resources[] = trim($api->base_path, '/') . '/';
            $resources[] = trim($api->base_path, '/') . '/*';
        }

        return $resources;
    }

    public function getApiDocInfo()
    {
        if ($this->name !== 'api_builder') {
            $api = ApiDefinition::query()
                ->where('base_path', $this->name)
                ->where('status', '!=', 'archived')
                ->first();

            return (new OpenApiFactory())->make($api, false);
        }

        // The api_builder service is the designer/management backend only. Each
        // built API is exposed by its own dedicated service (see
        // ApiDefinition::syncServiceInstance), so the designer must NOT
        // re-advertise the runtime paths under /api/v2/api_builder/... — that
        // would put "api_builder" in the public URL structure.
        return [
            'openapi' => '3.0.0',
            'info'    => [
                'title'       => 'API Builder',
                'description' => 'API Builder management service. Each custom API '
                    . 'is published as its own service at /api/v2/{api_name}/.',
                'version'     => '1.0.0',
            ],
            'servers' => [
                ['url' => '/api/v2/api_builder', 'description' => 'API Builder management'],
            ],
            'paths'   => new \stdClass(),
        ];
    }

    protected function handleBuiltApiRequest(ServiceRequestInterface $request, ?string $resource)
    {
        if ($this->name !== 'api_builder') {
            $basePath = $this->name;
            $runtimePath = '/' . trim((string)$resource, '/');
        } else {
            $segments = explode('/', trim($resource, '/'));
            $basePath = array_shift($segments);
            $runtimePath = '/' . implode('/', $segments);
        }

        $api = ApiDefinition::query()
            ->where('base_path', $basePath)
            ->where('status', '!=', 'archived')
            ->first();

        if (!$api) {
            throw new \DreamFactory\Core\Exceptions\NotFoundException("Built API '{$basePath}' was not found.");
        }

        $endpoint = null;
        $pathParams = [];
        $method = strtoupper($request->getMethod());
        foreach (EndpointDefinition::query()
                     ->where('api_id', $api->id)
                     ->where('method', $method)
                     ->where('is_active', true)
                     ->get() as $candidate) {
            $match = $this->matchEndpointPath($candidate->path, $runtimePath);
            if ($match !== false) {
                $endpoint = $candidate;
                $pathParams = $match;
                break;
            }
        }

        if (!$endpoint) {
            throw new \DreamFactory\Core\Exceptions\NotFoundException("No built endpoint matched {$method} {$resource}.");
        }

        return (new DefinitionExecutor())->execute($endpoint, $this->buildExecutorInput($request, $pathParams));
    }

    /**
     * Assemble the execution-plan input (path params, query, body) from the
     * inbound request. Extracted so body/query forwarding is unit-testable.
     */
    protected function buildExecutorInput(ServiceRequestInterface $request, array $pathParams): array
    {
        return [
            'path'  => $pathParams,
            'query' => (array)$request->getParameters(),
            'body'  => (array)$request->getPayloadData(),
        ];
    }

    protected function matchEndpointPath(string $template, string $path)
    {
        // Build the regex from the path template by substituting {param} tokens
        // with capture groups and preg_quote-ing every literal segment, so an
        // admin-authored path can never inject regex metacharacters (ReDoS /
        // unintended alternations / over-broad matches).
        $paramNames = [];
        $pattern = '';
        $parts = preg_split(
            '/(\{[^}]+\})/',
            trim($template, '/'),
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            if (preg_match('/^\{([^}]+)\}$/', $part, $m)) {
                $paramNames[] = $m[1];
                $pattern .= '([^\/]+)';
            } else {
                $pattern .= preg_quote($part, '#');
            }
        }

        if (!preg_match('#^' . $pattern . '$#', trim($path, '/'), $matches)) {
            return false;
        }

        // Validate path parameters — reject null bytes and control characters.
        foreach ($paramNames as $idx => $name) {
            if (isset($matches[$idx + 1])) {
                $val = $matches[$idx + 1];
                if (preg_match('/[\x00-\x1f]/', $val)) {
                    throw new \DreamFactory\Core\Exceptions\BadRequestException(
                        "Path parameter '{$name}' contains invalid characters."
                    );
                }
            }
        }

        array_shift($matches);
        return array_combine($paramNames, $matches) ?: [];
    }
}
