<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Ionutgrecu\LaravelGeo\Models\City;

class AddPopulationToCitiesTable extends Migration {
    protected string $table;

    public function __construct() {
        $this->table      = (new City())->getTable();
        $this->connection = (new City())->getConnectionName();
    }

    public function up() {
        if (!Schema::connection($this->connection)->hasColumn($this->table, 'population'))
            Schema::connection($this->connection)->table($this->table, function (Blueprint $table) {
                $table->unsignedBigInteger('population')->nullable()->after('place_rank');
            });
    }

    public function down() {
        Schema::connection($this->connection)->table($this->table, function (Blueprint $table) {
            $table->dropColumn('population');
        });
    }
}