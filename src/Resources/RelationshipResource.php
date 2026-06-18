<?php

namespace DreamFactory\Core\ApiBuilder\Resources;

use DreamFactory\Core\ApiBuilder\Models\ApiDefinition;
use DreamFactory\Core\ApiBuilder\Models\ApiServiceLink;
use DreamFactory\Core\Components\Service2ServiceRequest;
use DreamFactory\Core\Database\Models\DbRelationshipExtras;
use DreamFactory\Core\Database\Models\DbVirtualRelationship;
use DreamFactory\Core\Database\Schema\RelationSchema;
use DreamFactory\Core\Enums\Verbs;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\ForbiddenException;
use DreamFactory\Core\Exceptions\NotFoundException;
use DreamFactory\Core\Models\Service;
use DreamFactory\Core\Resources\BaseRestResource;
use ServiceManager;

/**
 * Define cross-service relationships for a custom API.
 *
 * Single source of truth: DreamFactory's own db_virtual_relationship. A POST
 * writes one (and flushes the schema cache so the resolver sees it immediately);
 * GET derives an API's relationships as the native virtual relationships whose
 * endpoints are all in the API's workspace; DELETE removes the native one. There
 * is no separate registry to drift out of sync with the schema.
 *
 * Both sides (and any junction) of a relationship must be services in the API's
 * workspace — a custom API may only relate across services it has added.
 */
class RelationshipResource extends BaseRestResource
{
    const RESOURCE_NAME = 'relationships';

    protected const ALLOWED_TYPES = ['belongs_to', 'has_one', 'has_many', 'many_many'];

    protected function handleGET()
    {
        if (!empty($this->resource)) {
            $row = DbVirtualRelationship::find($this->resource);
            if (!$row) {
                throw new NotFoundException("Relationship '{$this->resource}' not found.");
            }
            return $this->present($row, $this->serviceNames());
        }

        $apiId = $this->request->getParameter('api_id');
        if ($apiId === null) {
            return ['resource' => []];
        }

        $allowed = $this->workspaceServiceIds((int)$apiId);
        if (empty($allowed)) {
            return ['resource' => []];
        }

        // An API's relationships are the virtual relationships whose local side,
        // referenced side and any junction are all within its workspace.
        $rows = DbVirtualRelationship::whereIn('service_id', $allowed)->get()
            ->filter(function ($r) use ($allowed) {
                return in_array((int)$r->ref_service_id, $allowed, true)
                    && (empty($r->junction_service_id) || in_array((int)$r->junction_service_id, $allowed, true));
            });

        $names = $this->serviceNames();

        return ['resource' => $rows->map(function ($r) use ($names) {
            return $this->present($r, $names);
        })->values()->all()];
    }

    protected function handlePOST()
    {
        $p = $this->getPayloadData();

        $apiId = (int)$this->requireField($p, 'api_id');
        $service = (string)$this->requireField($p, 'service');
        $table = (string)$this->requireField($p, 'table');
        $type = strtolower((string)array_get($p, 'type', 'belongs_to'));
        $field = (string)$this->requireField($p, 'field');
        $refService = (string)$this->requireField($p, 'ref_service');
        $refTable = (string)$this->requireField($p, 'ref_table');
        $refField = (string)$this->requireField($p, 'ref_field');
        $alias = array_get($p, 'name', array_get($p, 'alias'));

        if (!in_array($type, static::ALLOWED_TYPES, true)) {
            throw new BadRequestException(
                "Unsupported relationship type '{$type}'. Allowed: " . implode(', ', static::ALLOWED_TYPES) . '.'
            );
        }

        if (!ApiDefinition::find($apiId)) {
            throw new BadRequestException("Custom API '{$apiId}' was not found.");
        }

        $allowed = $this->workspaceServiceIds($apiId);
        $serviceId = $this->resolveWorkspaceServiceId($service, $allowed);
        $refServiceId = $this->resolveWorkspaceServiceId($refService, $allowed);

        $rel = [
            'name'           => $alias,
            'alias'          => $alias,
            'type'           => $type,
            'field'          => $field,
            'is_virtual'     => true,
            'ref_service_id' => $refServiceId,
            'ref_table'      => $refTable,
            'ref_field'      => $refField,
        ];

        if ($type === 'many_many') {
            $junctionService = (string)$this->requireField($p, 'junction_service');
            $rel['junction_service_id'] = $this->resolveWorkspaceServiceId($junctionService, $allowed);
            $rel['junction_table']      = (string)$this->requireField($p, 'junction_table');
            $rel['junction_field']      = (string)$this->requireField($p, 'junction_field');
            $rel['junction_ref_field']  = (string)$this->requireField($p, 'junction_ref_field');
        }

        // Write the native virtual relationship, then flush so it resolves now.
        $this->dispatch(Verbs::PATCH, $service, '_schema/' . $table, ['related' => [$rel]]);
        ServiceManager::purge($service);

        $row = DbVirtualRelationship::where('service_id', $serviceId)
            ->where('table', $table)
            ->where('ref_service_id', $refServiceId)
            ->where('ref_table', $refTable)
            ->where('ref_field', $refField)
            ->where('type', $type)
            ->latest('id')
            ->first();

        return $row ? $this->present($row, $this->serviceNames()) : ['created' => true];
    }

    protected function handleDELETE()
    {
        if (empty($this->resource)) {
            throw new BadRequestException('A relationship id is required to delete.');
        }

        $row = DbVirtualRelationship::find($this->resource);
        if (!$row) {
            throw new NotFoundException("Relationship '{$this->resource}' not found.");
        }

        $names = $this->serviceNames();
        $service = $names[$row->service_id] ?? null;
        if ($service) {
            $name = $this->relationshipName($row, $names);
            try {
                $this->dispatch(Verbs::DELETE, $service, '_schema/' . $row->table . '/_related/' . $name);
            } catch (\Throwable $e) {
                // Already gone — fall through.
            }
            ServiceManager::purge($service);
        }

        return ['id' => (int)$row->id];
    }

    /** Service ids this custom API is allowed to compose. */
    protected function workspaceServiceIds(int $apiId): array
    {
        return ApiServiceLink::where('api_id', $apiId)->pluck('service_id')
            ->map(fn($v) => (int)$v)->all();
    }

    protected function resolveWorkspaceServiceId(string $name, array $allowed): int
    {
        $id = (int)Service::whereName($name)->value('id');
        if (empty($id)) {
            throw new BadRequestException("Service '{$name}' was not found.");
        }
        if (!in_array($id, $allowed, true)) {
            throw new ForbiddenException(
                "Service '{$name}' is not in this API's workspace. Add it to the workspace before relating across it."
            );
        }

        return $id;
    }

    /** id => name for every service, cached per request. */
    protected function serviceNames(): array
    {
        return Service::pluck('name', 'id')->all();
    }

    /** The name DreamFactory uses for this relationship in `related=`. */
    protected function relationshipName(DbVirtualRelationship $row, array $names): string
    {
        $crossService = (int)$row->ref_service_id !== (int)$row->service_id;
        $crossJunction = $row->junction_service_id
            && (int)$row->junction_service_id !== (int)$row->service_id;

        return RelationSchema::buildName([
            'type'             => $row->type,
            'field'            => $row->field,
            'ref_table'        => $row->ref_table,
            'ref_field'        => $row->ref_field,
            'ref_service'      => $crossService ? ($names[$row->ref_service_id] ?? null) : null,
            'junction_table'   => $row->junction_table,
            'junction_service' => $crossJunction ? ($names[$row->junction_service_id] ?? null) : null,
        ]);
    }

    protected function present(DbVirtualRelationship $row, array $names): array
    {
        $name = $this->relationshipName($row, $names);
        $alias = DbRelationshipExtras::where('service_id', $row->service_id)
            ->where('table', $row->table)
            ->where('relationship', $name)
            ->value('alias');

        return [
            'id'          => (int)$row->id,
            'service'     => $names[$row->service_id] ?? $row->service_id,
            'table'       => $row->table,
            'type'        => $row->type,
            'field'       => $row->field,
            'ref_service' => $names[$row->ref_service_id] ?? $row->ref_service_id,
            'ref_table'   => $row->ref_table,
            'ref_field'   => $row->ref_field,
            'name'        => $name,
            'alias'       => $alias ?: null,
        ];
    }

    protected function dispatch(string $verb, string $service, string $resource, ?array $payload = null, array $params = [])
    {
        $request = new Service2ServiceRequest($verb, $params);
        if ($payload !== null) {
            $request->setContent($payload, \DreamFactory\Core\Enums\DataFormats::PHP_ARRAY);
        }

        $response = ServiceManager::handleServiceRequest($request, $service, $resource, true);
        $status = $response->getStatusCode();
        $content = $response->getContent();
        if (is_string($content)) {
            $decoded = json_decode($content, true);
            $content = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $content;
        }

        if ($status < 200 || $status >= 300) {
            $message = is_array($content) ? (array_get($content, 'error.message') ?: 'Schema operation failed.') : (string)$content;
            throw new BadRequestException("Relationship schema operation failed ({$status}): {$message}");
        }

        return $content;
    }

    protected function requireField(array $payload, string $key)
    {
        $value = array_get($payload, $key);
        if ($value === null || $value === '') {
            throw new BadRequestException("Field '{$key}' is required.");
        }

        return $value;
    }
}
