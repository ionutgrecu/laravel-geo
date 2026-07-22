<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Ionutgrecu\LaravelGeo\Models\Country;

class AddOsmIdToCountriesTable extends Migration {
    protected string $table;

    public function __construct() {
        $this->table      = (new Country())->getTable();
        $this->connection = (new Country())->getConnectionName();
    }

    public function up() {
        if (!Schema::connection($this->connection)->hasColumn($this->table, 'osm_id'))
            Schema::connection($this->connection)->table($this->table, function (Blueprint $table) {
                $table->string('osm_id', 16)->nullable()->after('code');
            });
    }

    public function down() {
        Schema::connection($this->connection)->table($this->table, function (Blueprint $table) {
            $table->dropColumn('osm_id');
        });
    }
}