<?php

namespace Ionutgrecu\LaravelGeo\Models;

use Illuminate\Database\Eloquent\Model;
use function config;

/**
 * Class Neighborhood
 * @package Ionutgrecu\LaravelGeo\Models
 * @property int id
 * @property string city_code
 * @property string|null code
 * @property string|null osm_id
 * @property string|null name
 * @property string wiki_data_id
 * @property string|null type
 * @property int|null place_rank
 * @property string latitude
 * @property string longitude
 * @property string polygon
 * @property string created_at
 * @property string updated_at
 */
class Neighborhood extends Model {
    protected $guarded = ['id', 'created_at'];

    public function __construct(array $attributes = []) {
        $this->setConnection(config('geo.database_connection'));
        $this->setTable(config('geo.table_prefix') . 'neighborhoods');
        parent::__construct($attributes);
    }

    public function city() {
        return $this->belongsTo(City::class, 'city_code', 'code');
    }
}
