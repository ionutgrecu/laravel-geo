<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Ionutgrecu\LaravelGeo\Models\Neighborhood;

class AddTypePlacerankToNeighborhoodsTable extends Migration {
    protected string $table;

    public function __construct() {
        $this->table      = (new Neighborhood())->getTable();
        $this->connection = (new Neighborhood())->getConnectionName();
    }

    public function up() {
        if (!Schema::connection($this->connection)->hasColumn($this->table, 'type'))
            Schema::connection($this->connection)->table($this->table, function (Blueprint $table) {
                $table->string('type', 32)->nullable()->after('wiki_data_id');
                $table->unsignedInteger('place_rank')->nullable()->after('type');
            });
    }

    public function down() {
        Schema::connection($this->connection)->table($this->table, function (Blueprint $table) {
            $table->dropColumn(['type', 'place_rank']);
        });
    }
}