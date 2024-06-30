<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Ionutgrecu\LaravelGeo\Models\Country;
use Ionutgrecu\LaravelGeo\Models\Region;

class CreateCountriesTable extends Migration {
    protected string $table;

    public function __construct() {
        $this->table      = (new Country())->getTable();
        $this->connection = (new Country())->getConnectionName();
    }

    public function up() {
        if (!Schema::connection($this->connection)->hasTable($this->table))
            Schema::connection($this->connection)->create($this->table, function (Blueprint $table) {
                $table->id();
                $table->string('region_iso2', 2);
                $table->string('name', 64)->unique();
                $table->string('iso2', 2)->unique();
                $table->string('iso3', 3)->unique();
                $table->string('iso_numeric', 3)->unique();
                $table->string('phone_code', 8)->nullable();
                $table->string('capital', 64)->nullable();
                $table->string('currency', 8)->nullable();
                $table->string('language', 5)->nullable();
                $table->timestamps();

                $table->foreign('region_iso2')->references('iso2')->on((new Region())->getTable())->onUpdate('cascade')->onDelete('cascade');
            });
    }

    public function down() {
        Schema::connection($this->connection)->dropIfExists($this->table);
    }
}
