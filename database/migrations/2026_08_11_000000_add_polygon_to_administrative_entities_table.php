<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Ionutgrecu\LaravelGeo\Models\GeoAdministrativeEntity;

class AddPolygonToAdministrativeEntitiesTable extends Migration {
    protected string $table;

    public function __construct() {
        $this->table      = (new GeoAdministrativeEntity())->getTable();
        $this->connection = (new GeoAdministrativeEntity())->getConnectionName();
    }

    public function up() {
        if (!Schema::connection($this->connection)->hasColumn($this->table, 'polygon'))
            Schema::connection($this->connection)->table($this->table, function (Blueprint $table) {
                $table->longText('polygon')->nullable()->after('wikidata_id');
            });
    }

    public function down() {
        Schema::connection($this->connection)->table($this->table, function (Blueprint $table) {
            $table->dropColumn('polygon');
        });
    }
}