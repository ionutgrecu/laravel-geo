<?php

namespace Ionutgrecu\LaravelGeo\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Ionutgrecu\LaravelGeo\Builders\RegionQueryBuilder;
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

    function newEloquentBuilder($query) {
        return new RegionQueryBuilder($query);
    }

    static function setFavorite(?string $code) {
        if ($code)
            static::addGlobalScope('withFavorite', function (Builder $builder) use ($code) {
                $builder->orderByRaw("`code` = '$code' DESC")->orderBy('name');
            });
        else
            static::addGlobalScope('withFavorite', function (Builder $builder) use ($code) {
                $builder->orderBy('name');
            });
    }

    function countries(): HasMany {
        return $this->hasMany(Country::class, 'region_code', 'code');
    }
}
