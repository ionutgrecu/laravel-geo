<?php

namespace Ionutgrecu\LaravelGeo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use function config;

/**
 * Class Region
 * @package Ionutgrecu\LaravelGeo\Models
 * @property int id
 * @property string name
 * @property string code
 * @property string iso2
 * @property string wiki_data_id
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

    function countries(): HasMany {
        return $this->hasMany(Country::class, 'region_code', 'code');
    }
}
