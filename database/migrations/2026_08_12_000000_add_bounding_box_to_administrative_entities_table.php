<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Ionutgrecu\LaravelGeo\Models\GeoAdministrativeEntity;

class AddBoundingBoxToAdministrativeEntitiesTable extends Migration {
    protected string $table;

    public function __construct() {
        $this->table      = (new GeoAdministrativeEntity())->getTable();
        $this->connection = (new GeoAdministrativeEntity())->getConnectionName();
    }

    public function up() {
        if (!Schema::connection($this->connection)->hasColumn($this->table, 'bounding_box'))
            Schema::connection($this->connection)->table($this->table, function (Blueprint $table) {
                $table->json('bounding_box')->nullable();
            });
    }

    public function down() {
        Schema::connection($this->connection)->table($this->table, function (Blueprint $table) {
            $table->dropColumn('bounding_box');
        });
    }
}