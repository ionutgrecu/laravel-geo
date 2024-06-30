<?php

namespace Ionutgrecu\LaravelGeo\Console;

use Illuminate\Console\Command;

class LocationsImport extends Command {
    protected $signature   = 'command:name';
    protected $description = 'Command description';

    public function __construct() {
        parent::__construct();
    }

    public function handle() {
        return self::SUCCESS;
    }
}
