<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Ionutgrecu\LaravelGeo\Models\City;
use Ionutgrecu\LaravelGeo\Models\County;

class CreateCitiesTable extends Migration {
    protected string $table;

    public function __construct() {
        $this->table      = (new City())->getTable();
        $this->connection = (new City())->getConnectionName();
    }

    public function up() {
        if (!Schema::connection($this->connection)->hasTable($this->table))
            Schema::connection($this->connection)->create($this->table, function (Blueprint $table) {
                $table->id();
                $table->string('county_code', 10);
                $table->string('code', 10)->unique();
                $table->string('name', 64)->index();
                $table->string('latitude', 32)->nullable();
                $table->string('longitude', 32)->nullable();
                $table->timestamps();

                $table->foreign('county_code')->references('code')->on((new County())->getTable())->onUpdate('cascade')->onDelete('cascade');
            });
    }

    public function down() {
        Schema::connection($this->connection)->dropIfExists($this->table);
    }
}
