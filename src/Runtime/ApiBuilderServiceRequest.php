<?php

namespace DreamFactory\Core\ApiBuilder\Runtime;

use DreamFactory\Core\Components\Service2ServiceRequest;

class ApiBuilderServiceRequest extends Service2ServiceRequest
{
    public function getFile($key = null, $default = null)
    {
        if ($key !== null) {
            return $default;
        }

        return [];
    }
}
