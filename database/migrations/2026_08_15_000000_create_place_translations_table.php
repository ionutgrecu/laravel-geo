<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Ionutgrecu\LaravelGeo\Models\GeoPlaceTranslation;

class CreatePlaceTranslationsTable extends Migration {
    protected string $table;

    public function __construct() {
        $this->table      = (new GeoPlaceTranslation())->getTable();
        $this->connection = (new GeoPlaceTranslation())->getConnectionName();
    }

    public function up() {
        if (!Schema::connection($this->connection)->hasTable($this->table))
            Schema::connection($this->connection)->create($this->table, function (Blueprint $table) {
                $table->string('base_name', 255);
                $table->string('language', 10);
                $table->string('translation', 255);
                $table->timestamps();

                $table->primary(['base_name', 'language']);
            });
    }

    public function down() {
        Schema::connection($this->connection)->dropIfExists($this->table);
    }
}