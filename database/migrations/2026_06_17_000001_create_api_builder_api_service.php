<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Workspace: the set of backing DreamFactory services a custom API may compose.
 * Each row links one api_builder_api to one service. Endpoints in that API may
 * only build execution-plan steps and cross-service relationships against the
 * services in its workspace (least privilege, per custom API).
 */
class CreateApiBuilderApiService extends Migration
{
    public function up()
    {
        Schema::create('api_builder_api_service', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('api_id')->unsigned();
            $t->foreign('api_id')->references('id')->on('api_builder_api')->onDelete('cascade');
            $t->integer('service_id')->unsigned();
            $t->foreign('service_id')->references('id')->on('service')->onDelete('cascade');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('last_modified_date')->useCurrent();
            $t->unique(['api_id', 'service_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('api_builder_api_service');
    }
}
