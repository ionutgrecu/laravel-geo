<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Ionutgrecu\LaravelGeo\Models\Country;
use Ionutgrecu\LaravelGeo\Models\County;

class CreateCountiesTable extends Migration {
    protected string $table;

    public function __construct() {
        $this->table      = (new County())->getTable();
        $this->connection = (new County())->getConnectionName();
    }

    public function up() {
        if (!Schema::connection($this->connection)->hasTable($this->table))
            Schema::connection($this->connection)->create($this->table, function (Blueprint $table) {
                $table->id();
                $table->string('country_iso2', 2);
                $table->string('name', 64)->unique();
                $table->string('iso_code', 4)->unique();
                $table->timestamps();

                $table->foreign('country_iso2')->references('iso2')->on((new Country())->getTable())->onUpdate('cascade')->onDelete('cascade');
            });
    }

    public function down() {
        Schema::connection($this->connection)->dropIfExists($this->table);
    }
}
