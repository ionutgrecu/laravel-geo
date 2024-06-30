<?php

namespace Ionutgrecu\LaravelGeo\Models;

use Illuminate\Database\Eloquent\Model;
use function config;

/**
 * Class Region
 * @package Ionutgrecu\LaravelGeo\Models
 * @property int id
 * @property string name
 * @property string iso2
 * @property string created_at
 * @property string updated_at
 */
class Region extends Model {
    protected $guarded = ['id', 'created_at'];

    public function __construct(array $attributes = []) {
        $this->setConnection(config('geo.database_connection'));
        $this->setTable(config('geo.table_prefix') . 'regions');
        parent::__construct($attributes);
    }
}
