<?php

namespace DreamFactory\Core\ApiBuilder\Services;

use DreamFactory\Core\ApiBuilder\Models\ApiDefinition;
use DreamFactory\Core\ApiBuilder\Models\EndpointDefinition;
use DreamFactory\Core\ApiBuilder\Resources\DocsResource;
use DreamFactory\Core\ApiBuilder\Resources\ApiDefinitionResource;
use DreamFactory\Core\ApiBuilder\Resources\EndpointDefinitionResource;
use DreamFactory\Core\ApiBuilder\Resources\TestResource;
use DreamFactory\Core\ApiBuilder\Runtime\DefinitionExecutor;
use DreamFactory\Core\ApiBuilder\Support\OpenApiFactory;
use DreamFactory\Core\Contracts\ServiceRequestInterface;
use DreamFactory\Core\Utility\ResponseFactory;
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

        $firstSegment = explode('/', trim((string)$resource, '/'))[0] ?? '';
        if ($this->name !== 'api_builder' || ($resource && !array_key_exists($firstSegment, static::$resources))) {
            $result = $this->handleBuiltApiRequest($request, $resource);
            return ResponseFactory::create($result);
        }

        return parent::handleRequest($request, $resource);
    }

    public function getAccessList()
    {
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

        return (new OpenApiFactory())->make(null, true);
    }

    protected function handleBuiltApiRequest(ServiceRequestInterface $request, string $resource)
    {
        if ($this->name !== 'api_builder') {
            $basePath = $this->name;
            $runtimePath = '/' . trim($resource, '/');
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
        $paramNames = [];
        $pattern = preg_replace_callback('/\{([^}]+)\}/', function ($matches) use (&$paramNames) {
            $paramNames[] = $matches[1];
            return '([^\/]+)';
        }, trim($template, '/'));

        if (!preg_match('#^' . $pattern . '$#', trim($path, '/'), $matches)) {
            return false;
        }

        array_shift($matches);
        return array_combine($paramNames, $matches) ?: [];
    }
}
