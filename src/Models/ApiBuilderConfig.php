<?php

namespace DreamFactory\Core\ApiBuilder\Models;

use DreamFactory\Core\Models\BaseServiceConfigNoDbModel;

class ApiBuilderConfig extends BaseServiceConfigNoDbModel
{
    public static function getSchema()
    {
        return [
            [
                'name'        => 'api_id',
                'label'       => 'API Definition ID',
                'type'        => 'integer',
                'description' => 'The API Builder definition served by this DreamFactory service instance.',
            ],
        ];
    }
}
