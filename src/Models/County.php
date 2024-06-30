<?php

namespace Ionutgrecu\LaravelGeo\Models;

use Illuminate\Database\Eloquent\Model;
use function config;

/**
 * Class County
 * @package Ionutgrecu\LaravelGeo\Models
 * @property int id
 * @property string country_iso2
 * @property string name
 * @property string code
 * @property string created_at
 * @property string updated_at
 */
class County extends Model {
    protected $guarded = ['id', 'created_at'];

    public function __construct(array $attributes = []) {
        $this->setConnection(config('geo.database_connection'));
        $this->setTable(config('geo.table_prefix') . 'counties');
        parent::__construct($attributes);
    }
}
