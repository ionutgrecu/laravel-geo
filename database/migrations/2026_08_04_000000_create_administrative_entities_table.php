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
                $table->string('osm_id', 32);
                $table->string('parent_osm_id', 32)->nullable();
                $table->string('country_osm_id', 32)->nullable();
                $table->string('name', 255);
                $table->string('type', 50);
                $table->smallInteger('admin_level')->nullable();
                $table->string('wikidata_id', 32)->nullable();
                $table->unsignedBigInteger('population')->nullable();
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
                $table->json('tags')->nullable();
                $table->timestamps();

                $table->primary('osm_id');

                $table->index('parent_osm_id', 'idx_geo_parent');
                $table->index('country_osm_id', 'idx_geo_country');
                $table->index('type', 'idx_geo_type');
                $table->index('wikidata_id', 'idx_geo_wikidata');

                $selfTable = $this->table;
                $table->foreign('parent_osm_id', 'fk_geo_parent')
                    ->references('osm_id')->on($selfTable)
                    ->onUpdate('cascade')->onDelete('cascade');
                $table->foreign('country_osm_id', 'fk_geo_country')
                    ->references('osm_id')->on($selfTable)
                    ->onUpdate('cascade')->onDelete('cascade');
            });
    }

    public function down() {
        Schema::connection($this->connection)->dropIfExists($this->table);
    }
}