<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateApiBuilderTables extends Migration
{
    public function up()
    {
        $driver = Schema::getConnection()->getDriverName();
        $onDelete = (('sqlsrv' === $driver) ? 'no action' : 'set null');

        Schema::create('api_builder_api', function (Blueprint $t) use ($onDelete) {
            $t->increments('id');
            $t->string('name', 64)->unique();
            $t->string('label', 80);
            $t->string('description')->nullable();
            $t->string('base_path', 255)->unique();
            $t->string('status', 32)->default('draft');
            $t->string('version', 32)->default('0.1.0');
            $t->mediumText('metadata')->nullable();
            $t->timestamp('created_date')->nullable();
            $t->timestamp('last_modified_date')->useCurrent();
            $t->integer('created_by_id')->unsigned()->nullable();
            $t->foreign('created_by_id')->references('id')->on('user')->onDelete($onDelete);
            $t->integer('last_modified_by_id')->unsigned()->nullable();
            $t->foreign('last_modified_by_id')->references('id')->on('user')->onDelete($onDelete);
        });

        Schema::create('api_builder_endpoint', function (Blueprint $t) use ($onDelete) {
            $t->increments('id');
            $t->integer('api_id')->unsigned();
            $t->foreign('api_id')->references('id')->on('api_builder_api')->onDelete('cascade');
            $t->string('method', 16);
            $t->string('path', 255);
            $t->string('label', 80)->nullable();
            $t->string('description')->nullable();
            $t->boolean('is_active')->default(true);
            $t->mediumText('request_schema')->nullable();
            $t->mediumText('response_schema')->nullable();
            $t->mediumText('execution_plan')->nullable();
            $t->mediumText('response_mapping')->nullable();
            $t->mediumText('policy')->nullable();
            $t->mediumText('docs')->nullable();
            $t->timestamp('created_date')->nullable();
            $t->timestamp('last_modified_date')->useCurrent();
            $t->integer('created_by_id')->unsigned()->nullable();
            $t->foreign('created_by_id')->references('id')->on('user')->onDelete($onDelete);
            $t->integer('last_modified_by_id')->unsigned()->nullable();
            $t->foreign('last_modified_by_id')->references('id')->on('user')->onDelete($onDelete);
            $t->unique(['api_id', 'method', 'path']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('api_builder_endpoint');
        Schema::dropIfExists('api_builder_api');
    }
}
