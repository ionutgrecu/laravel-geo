<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Ionutgrecu\LaravelGeo\Models\GeoAdministrativeEntity;

class AddPlaceRankToAdministrativeEntitiesTable extends Migration {
    protected string $table;

    public function __construct() {
        $this->table      = (new GeoAdministrativeEntity())->getTable();
        $this->connection = (new GeoAdministrativeEntity())->getConnectionName();
    }

    public function up() {
        if (!Schema::connection($this->connection)->hasColumn($this->table, 'place_rank'))
            Schema::connection($this->connection)->table($this->table, function (Blueprint $table) {
                $table->smallInteger('place_rank')->nullable()->after('admin_level');
            });
    }

    public function down() {
        Schema::connection($this->connection)->table($this->table, function (Blueprint $table) {
            $table->dropColumn('place_rank');
        });
    }
}