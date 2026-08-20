<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Ionutgrecu\LaravelGeo\Models\GeoAdministrativeEntity;

class CreateAdministrativeEntitiesTable extends Migration {
    protected string $table;

    public function __construct() {
        $this->table      = (new GeoAdministrativeEntity())->getTable();
        $this->connection = (new GeoAdministrativeEntity())->getConnectionName();
    }

    public function up() {
        if (!Schema::connection($this->connection)->hasTable($this->table))
            Schema::connection($this->connection)->create($this->table, function (Blueprint $table) {
                $table->string('id', 32);
                $table->string('parent_id', 32)->nullable()->index();
                $table->string('country_id', 32)->nullable()->index();
                $table->string('name', 255);
                $table->string('type', 50);
                $table->smallInteger('admin_level')->nullable();
                $table->string('wikidata_id', 32)->nullable();
                $table->unsignedBigInteger('population')->nullable();
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
                $table->json('tags')->nullable();
                $table->timestamps();

                $table->primary('id');

                $table->index('parent_id', 'idx_geo_parent');
                $table->index('country_id', 'idx_geo_country');
                $table->index('type', 'idx_geo_type');
                $table->index('wikidata_id', 'idx_geo_wikidata');

                $selfTable = $this->table;
            });
    }

    public function down() {
        Schema::connection($this->connection)->dropIfExists($this->table);
    }
}