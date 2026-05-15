<?php

namespace DreamFactory\Core\ApiBuilder\Models;

use DreamFactory\Core\Models\BaseSystemModel;

class EndpointDefinition extends BaseSystemModel
{
    protected $table = 'api_builder_endpoint';

    protected $fillable = [
        'api_id',
        'method',
        'path',
        'label',
        'description',
        'is_active',
        'request_schema',
        'response_schema',
        'execution_plan',
        'response_mapping',
        'policy',
        'docs',
    ];

    protected $guarded = [
        'id',
        'created_date',
        'last_modified_date',
        'created_by_id',
        'last_modified_by_id',
    ];

    protected $casts = [
        'id'               => 'integer',
        'api_id'           => 'integer',
        'is_active'        => 'boolean',
        'request_schema'   => 'array',
        'response_schema'  => 'array',
        'execution_plan'   => 'array',
        'response_mapping' => 'array',
        'policy'           => 'array',
        'docs'             => 'array',
    ];

    protected $rules = [
        'api_id' => 'required|integer',
        'method' => 'required|in:GET,POST,PUT,PATCH,DELETE',
        'path'   => 'required|string|max:255',
        'label'  => 'string|max:80',
    ];

    public function api()
    {
        return $this->belongsTo(ApiDefinition::class, 'api_id');
    }
}
