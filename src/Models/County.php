<?php

namespace Ionutgrecu\LaravelGeo\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Ionutgrecu\LaravelGeo\Builders\CountyQueryBuilder;
use function config;

/**
 * Class County
 * @package Ionutgrecu\LaravelGeo\Models
 * @property int id
 * @property string country_code
 * @property string code
 * @property string name
 * @property string fips
 * @property string wiki_data_id
 * @property string created_at
 * @property string updated_at
 */
class County extends Model {
    protected $guarded = ['id', 'created_at'];

    public function newEloquentBuilder($query) {
        return new CountyQueryBuilder($query);
    }

    public function __construct(array $attributes = []) {
        $this->setConnection(config('geo.database_connection'));
        $this->setTable(config('geo.table_prefix') . 'counties');
        parent::__construct($attributes);
    }

    protected static function booted() {
        static::addGlobalScope('withCountry', function (Builder $builder) {
            $builder->with('country');
        });
    }

    function country(): BelongsTo {
        return $this->belongsTo(Country::class, 'country_code', 'code');
    }

    function cities(): HasMany {
        return $this->hasMany(City::class, 'county_code', 'code');
    }
}
