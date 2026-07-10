<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Ionutgrecu\LaravelGeo\Models\Region;

class CreateRegionsTable extends Migration {
    protected string $table;

    public function __construct() {
        $this->table      = (new Region())->getTable();
        $this->connection = (new Region())->getConnectionName();
    }

    public function up() {
        if (!Schema::connection($this->connection)->hasTable($this->table))
            Schema::connection($this->connection)->create($this->table, function (Blueprint $table) {
                $table->mediumIncrements('id')->unsigned();
                $table->string('name', 32)->unique();
                $table->string('code', 2)->comment('ISO2')->unique();
                $table->string('iso2', 2)->unique();
                $table->string('wiki_data_id', 16)->nullable();
                $table->timestamps();
            });
    }

    public function down() {
        Schema::connection($this->connection)->dropIfExists($this->table);
    }
}
