<?php

namespace DreamFactory\Core\ApiBuilder\Support;

use DreamFactory\Core\ApiBuilder\Models\ApiDefinition;
use DreamFactory\Core\ApiBuilder\Models\EndpointDefinition;

class OpenApiFactory
{
    public function make(?ApiDefinition $api = null, bool $includeBasePath = true): array
    {
        $apis = $api ? collect([$api]) : ApiDefinition::query()->where('status', '!=', 'archived')->get();
        $title = $api ? ($api->label ?: $api->name) : 'API Builder Custom APIs';
        $description = $api
            ? (string)$api->description
            : 'Custom APIs built with DreamFactory API Builder.';

        $paths = [];
        foreach ($apis as $definition) {
            $endpoints = EndpointDefinition::query()
                ->where('api_id', $definition->id)
                ->where('is_active', true)
                ->orderBy('path')
                ->get();

            foreach ($endpoints as $endpoint) {
                $path = $includeBasePath
                    ? '/' . trim($definition->base_path, '/') . $endpoint->path
                    : $endpoint->path;
                $method = strtolower($endpoint->method);
                $paths[$path][$method] = $this->operation($definition, $endpoint);
            }
        }

        ksort($paths);

        return [
            'openapi' => '3.0.0',
            'info'    => [
                'title'       => $title,
                'description' => $description,
                'version'     => $api ? ($api->version ?: '1.0.0') : '1.0.0',
                'group'       => 'API Builder',
            ],
            'servers' => [
                [
                    'url'         => $api && !$includeBasePath
                        ? '/api/v2/' . trim($api->base_path, '/')
                        : '/api/v2',
                    'description' => 'DreamFactory API Builder runtime',
                ],
            ],
            'security' => [
                ['SessionTokenHeader' => []],
                ['ApiKeyHeader' => []],
            ],
            'tags' => $apis->map(function (ApiDefinition $definition) {
                return [
                    'name'        => $definition->label ?: $definition->name,
                    'description' => (string)$definition->description,
                ];
            })->values()->all(),
            'paths'      => $paths,
            'components' => [
                'securitySchemes' => [
                    'SessionTokenHeader' => [
                        'type'        => 'apiKey',
                        'in'          => 'header',
                        'name'        => 'X-DreamFactory-Session-Token',
                        'description' => 'JWT session token.',
                    ],
                    'ApiKeyHeader' => [
                        'type'        => 'apiKey',
                        'in'          => 'header',
                        'name'        => 'X-DreamFactory-API-Key',
                        'description' => 'Application API key.',
                    ],
                ],
                'schemas' => [
                    'ApiBuilderResponse' => [
                        'type'                 => 'object',
                        'additionalProperties' => true,
                    ],
                ],
            ],
        ];
    }

    protected function operation(ApiDefinition $api, EndpointDefinition $endpoint): array
    {
        $parameters = [];
        if (preg_match_all('/\{([^}]+)\}/', $endpoint->path, $matches)) {
            foreach ($matches[1] as $name) {
                $parameters[] = [
                    'name'     => $name,
                    'in'       => 'path',
                    'required' => true,
                    'schema'   => [
                        'type' => 'string',
                    ],
                ];
            }
        }

        return [
            'tags'        => [$api->label ?: $api->name],
            'summary'     => $endpoint->label ?: "{$endpoint->method} {$endpoint->path}",
            'description' => (string)$endpoint->description,
            'operationId' => $this->operationId($api, $endpoint),
            'parameters'  => $parameters,
            'responses'   => [
                '200' => [
                    'description' => 'Successful response',
                    'content'     => [
                        'application/json' => [
                            'schema' => [
                                '$ref' => '#/components/schemas/ApiBuilderResponse',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    protected function operationId(ApiDefinition $api, EndpointDefinition $endpoint): string
    {
        return preg_replace(
            '/[^A-Za-z0-9_]+/',
            '_',
            strtolower($api->name . '_' . $endpoint->method . '_' . trim($endpoint->path, '/'))
        );
    }
}
