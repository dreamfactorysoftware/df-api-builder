<?php

namespace DreamFactory\Core\ApiBuilder\Resources;

use DreamFactory\Core\ApiBuilder\Models\ApiServiceLink;
use DreamFactory\Core\System\Resources\BaseSystemResource;

/**
 * Manage the workspace (backing services) for a custom API.
 * GET/POST/DELETE /api/v2/api_builder/services?filter=api_id={id}
 */
class WorkspaceResource extends BaseSystemResource
{
    const RESOURCE_NAME = 'services';

    protected static $model = ApiServiceLink::class;
}
