<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Ionutgrecu\LaravelGeo\Models\City;
use Ionutgrecu\LaravelGeo\Models\Neighborhood;

class CreateNeighborhoodsTable extends Migration {
    protected string $table;

    public function __construct() {
        $this->table      = (new Neighborhood())->getTable();
        $this->connection = (new Neighborhood())->getConnectionName();
    }

    public function up() {
        if (!Schema::connection($this->connection)->hasTable($this->table))
            Schema::connection($this->connection)->create($this->table, function (Blueprint $table) {
                $table->bigIncrements('id')->unsigned();
                $table->integer('city_id')->unsigned();
                $table->string('name', 64)->index();
                $table->timestamps();

                $table->foreign('city_id')->references('id')->on((new City())->getTable())->onUpdate('cascade')->onDelete('cascade');
            });
    }

    public function down() {
        Schema::connection($this->connection)->dropIfExists($this->table);
    }
}
