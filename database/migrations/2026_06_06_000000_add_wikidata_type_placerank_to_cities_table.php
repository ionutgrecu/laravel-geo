<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Ionutgrecu\LaravelGeo\Models\City;

class AddWikidataTypePlacerankToCitiesTable extends Migration {
    protected string $table;

    public function __construct() {
        $this->table      = (new City())->getTable();
        $this->connection = (new City())->getConnectionName();
    }

    public function up() {
        if (!Schema::connection($this->connection)->hasColumn($this->table, 'wiki_data_id'))
            Schema::connection($this->connection)->table($this->table, function (Blueprint $table) {
                $table->string('wiki_data_id', 16)->nullable()->after('longitude');
                $table->string('type', 32)->nullable()->after('wiki_data_id');
                $table->unsignedInteger('place_rank')->nullable()->after('type');
                $table->string('place_id', 32)->nullable()->after('place_rank');
                $table->longText('polygon')->nullable()->after('place_id');
            });
    }

    public function down() {
        Schema::connection($this->connection)->table($this->table, function (Blueprint $table) {
            $table->dropColumn(['wiki_data_id', 'type', 'place_rank', 'place_id', 'polygon']);
        });
    }
}