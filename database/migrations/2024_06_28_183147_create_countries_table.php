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
                $table->string('region_code', 2);
                $table->string('name', 64)->unique()->comment("Name in local language");
                $table->string('name_int', 64)->index()->comment("Name in English");
                $table->string('code', 2)->unique()->comment('ISO2');
                $table->string('iso2', 2)->unique();
                $table->string('iso3', 3)->unique();
                $table->string('iso_numeric', 3)->unique();
                $table->string('wiki_data_id', 16)->nullable();
                $table->string('phone_code', 8)->nullable();
                $table->string('currency', 8)->nullable();
                $table->text('languages')->nullable();
                $table->timestamps();

                $table->foreign('region_code')->references('code')->on((new Region())->getTable())->onUpdate('cascade')->onDelete('cascade');
            });
    }

    public function down() {
        Schema::connection($this->connection)->dropIfExists($this->table);
    }
}
