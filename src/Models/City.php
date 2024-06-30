<?php

namespace Ionutgrecu\LaravelGeo\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Class City
 * @package Ionutgrecu\LaravelGeo\Models
 * @property int id
 * @property string county_id
 * @property string name
 * @property string latitude
 * @property string longitude
 * @property string created_at
 * @property string updated_at
 */
class City extends Model {
    protected $guarded = ['id', 'created_at'];
    
    public function __construct(array $attributes = []) {
        $this->setConnection(config('geo.database_connection'));
        $this->setTable(config('geo.table_prefix') . 'cities');
        parent::__construct($attributes);
    }
}
