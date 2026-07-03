<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Ionutgrecu\LaravelGeo\Models\ReverseGeocodeCache;

class CreateReverseGeocodesTable extends Migration {
    protected string $table;

    public function __construct() {
        $this->table      = (new ReverseGeocodeCache())->getTable();
        $this->connection = (new ReverseGeocodeCache())->getConnectionName();
    }

    public function up() {
        if (!Schema::connection($this->connection)->hasTable($this->table))
            Schema::connection($this->connection)->create($this->table, function (Blueprint $table) {
                $table->char('id', 20)->primary();
                $table->string('provider', 50)->default('nominatim');
                $table->string('cache_key', 32);
                $table->decimal('latitude', 7, 4);
                $table->decimal('longitude', 7, 4);
                $table->unsignedTinyInteger('zoom')->nullable();
                $table->string('accept_language', 100)->nullable();
                $table->json('results');
                $table->json('raw_response')->nullable();
                $table->unsignedSmallInteger('http_status')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('last_hit_at')->nullable();
                $table->timestamps();

                $table->unique(['provider', 'cache_key']);
                $table->index(['latitude', 'longitude']);
                $table->index(['expires_at']);
                $table->index(['last_hit_at']);
            });
    }

    public function down() {
        Schema::connection($this->connection)->dropIfExists($this->table);
    }
}