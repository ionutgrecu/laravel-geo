<?php

namespace Ionutgrecu\LaravelGeo\Models;

use Illuminate\Database\Eloquent\Model;
use function config;

/**
 * Class Country
 * @package Ionutgrecu\LaravelGeo\Models
 * @property int id
 * @property string region_iso2
 * @property string name
 * @property string iso2
 * @property string iso3
 * @property string iso_numeric
 * @property string phone_code
 * @property string capital
 * @property string currency
 * @property string language
 * @property string created_at
 * @property string updated_at
 */
class Country extends Model {
    protected $guarded = ['id', 'created_at'];

    public function __construct(array $attributes = []) {
        $this->setConnection(config('geo.database_connection'));
        $this->setTable(config('geo.table_prefix') . 'countries');
        parent::__construct($attributes);
    }
}
