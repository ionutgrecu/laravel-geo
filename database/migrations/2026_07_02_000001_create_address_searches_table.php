<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Ionutgrecu\LaravelGeo\Models\AddressSearchCache;

class CreateAddressSearchesTable extends Migration {
    protected string $table;

    public function __construct() {
        $this->table      = (new AddressSearchCache())->getTable();
        $this->connection = (new AddressSearchCache())->getConnectionName();
    }

    public function up() {
        if (!Schema::connection($this->connection)->hasTable($this->table))
            Schema::connection($this->connection)->create($this->table, function (Blueprint $table) {
                $table->char('id', 20)->primary();
                $table->string('provider', 50)->default('nominatim');
                $table->string('cache_key', 32);
                $table->string('query');
                $table->string('normalized_query');
                $table->string('countrycodes', 64)->nullable();
                $table->string('accept_language', 100)->nullable();
                $table->unsignedTinyInteger('limit')->default(5);
                $table->json('results');
                $table->json('raw_response')->nullable();
                $table->unsignedSmallInteger('result_count')->default(0);
                $table->unsignedSmallInteger('http_status')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('last_hit_at')->nullable();
                $table->timestamps();

                $table->unique(['provider', 'cache_key']);
                $table->index(['normalized_query']);
                $table->index(['expires_at']);
                $table->index(['last_hit_at']);
            });
    }

    public function down() {
        Schema::connection($this->connection)->dropIfExists($this->table);
    }
}