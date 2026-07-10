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
                $table->string('city_code', 10);
                $table->string('code', 16)->nullable()->unique();
                $table->string('name', 64)->nullable()->index();
                $table->string('wiki_data_id', 16)->nullable();
                $table->string('latitude', 32)->nullable();
                $table->string('longitude', 32)->nullable();
                $table->longText('polygon')->nullable();
                $table->timestamps();

                $table->foreign('city_code')->references('code')->on((new City())->getTable())->onUpdate('cascade')->onDelete('cascade');
            });
    }

    public function down() {
        Schema::connection($this->connection)->dropIfExists($this->table);
    }
}
