<?php

namespace DreamFactory\Core\ApiBuilder;

use DreamFactory\Core\ApiBuilder\Services\ApiBuilder;
use DreamFactory\Core\ApiBuilder\Models\ApiBuilderConfig;
use DreamFactory\Core\Services\ServiceManager;
use DreamFactory\Core\Services\ServiceType;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    public function boot()
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    public function register()
    {
        $this->app->resolving('df.service', function (ServiceManager $df) {
            $df->addType(new ServiceType([
                'name'           => 'api_builder',
                'label'          => 'API Builder',
                'description'    => 'Build custom APIs from existing DreamFactory services.',
                'group'          => 'API Builder',
                'singleton'      => false,
                'config_handler' => ApiBuilderConfig::class,
                'factory'        => function ($config) {
                    return new ApiBuilder($config);
                },
            ]));
        });
    }
}
