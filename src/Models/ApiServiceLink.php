<?php

namespace DreamFactory\Core\ApiBuilder\Models;

use DreamFactory\Core\Models\BaseSystemModel;
use DreamFactory\Core\Models\Service;

/**
 * A workspace link: one backing service made available to one custom API.
 * The set of links for an API is the only set of services its endpoints may
 * compose (steps) or relate across (cross-service relationships).
 */
class ApiServiceLink extends BaseSystemModel
{
    protected $table = 'api_builder_api_service';

    protected $fillable = [
        'api_id',
        'service_id',
    ];

    protected $guarded = [
        'id',
        'created_date',
        'last_modified_date',
    ];

    protected $casts = [
        'id'         => 'integer',
        'api_id'     => 'integer',
        'service_id' => 'integer',
    ];

    protected $rules = [
        'api_id'     => 'required|integer',
        'service_id' => 'required|integer',
    ];

    public function api()
    {
        return $this->belongsTo(ApiDefinition::class, 'api_id');
    }

    public function service()
    {
        return $this->belongsTo(Service::class, 'service_id');
    }
}
