<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Ionutgrecu\LaravelGeo\Models\GeoAdministrativeEntity;

class AddHasNoChildrenToAdministrativeEntitiesTable extends Migration {
    protected string $table;

    public function __construct() {
        $this->table      = (new GeoAdministrativeEntity())->getTable();
        $this->connection = (new GeoAdministrativeEntity())->getConnectionName();
    }

    public function up() {
        if (!Schema::connection($this->connection)->hasColumn($this->table, 'has_no_children'))
            Schema::connection($this->connection)->table($this->table, function (Blueprint $table) {
                $table->boolean('has_no_children')->default(false)->after('place_rank');
            });
    }

    public function down() {
        Schema::connection($this->connection)->table($this->table, function (Blueprint $table) {
            $table->dropColumn('has_no_children');
        });
    }
}