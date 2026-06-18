<?php

namespace DreamFactory\Core\ApiBuilder\Models;

use DreamFactory\Core\Exceptions\ForbiddenException;
use DreamFactory\Core\Models\BaseSystemModel;
use DreamFactory\Core\Utility\Session;

class EndpointDefinition extends BaseSystemModel
{
    /** True when the endpoint policy opts out of backing-service permission checks. */
    public static function policyIsPrivileged($policy): bool
    {
        return is_array($policy) && (bool)array_get($policy, 'privileged', false);
    }

    public static function boot()
    {
        parent::boot();

        // A privileged endpoint bypasses RBAC on the services its plan calls, so
        // creating/modifying one is a system-administrator-only action. Without
        // this gate, anyone with write access to the API Builder designer could
        // mint an endpoint that reads any service past role restrictions.
        static::saving(function (EndpointDefinition $endpoint) {
            if (
                static::policyIsPrivileged($endpoint->policy)
                && !Session::isSysAdmin()
            ) {
                throw new ForbiddenException(
                    'Only a system administrator may create or modify a privileged '
                    . '(permission-bypassing) endpoint.'
                );
            }

            return true;
        });
    }

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
