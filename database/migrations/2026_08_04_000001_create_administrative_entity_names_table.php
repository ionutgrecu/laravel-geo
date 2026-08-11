<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Ionutgrecu\LaravelGeo\Models\GeoAdministrativeEntity;
use Ionutgrecu\LaravelGeo\Models\GeoAdministrativeEntityName;

class CreateAdministrativeEntityNamesTable extends Migration {
    protected string $table;

    public function __construct() {
        $this->table      = (new GeoAdministrativeEntityName())->getTable();
        $this->connection = (new GeoAdministrativeEntityName())->getConnectionName();
    }

    public function up() {
        if (!Schema::connection($this->connection)->hasTable($this->table))
            Schema::connection($this->connection)->create($this->table, function (Blueprint $table) {
                $table->string('id', 32);
                $table->string('language', 10);
                $table->string('name', 255);
                $table->timestamps();

                $table->primary(['id', 'language']);

                $table->foreign('id', 'fk_geo_name_entity')
                    ->references('id')->on((new GeoAdministrativeEntity())->getTable())
                    ->onUpdate('cascade')->onDelete('cascade');
            });
    }

    public function down() {
        Schema::connection($this->connection)->dropIfExists($this->table);
    }
}