<?php

namespace Ionutgrecu\LaravelGeo\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use function config;

/**
 * Class Country
 * @package Ionutgrecu\LaravelGeo\Models
 * @property int id
 * @property string region_code
 * @property string code
 * @property string name
 * @property string name_int
 * @property string iso2
 * @property string iso3
 * @property string iso_numeric
 * @property string wiki_data_id
 * @property string phone_code
 * @property string currency
 * @property string[] languages
 * @property string created_at
 * @property string updated_at
 */
class Country extends Model {
    protected $guarded = ['id', 'created_at'];

    protected $casts = [
        'languages' => 'array',
    ];

    public function __construct(array $attributes = []) {
        $this->setConnection(config('geo.database_connection'));
        $this->setTable(config('geo.table_prefix') . 'countries');
        parent::__construct($attributes);
    }

    protected static function booted() {
        static::addGlobalScope('withRegion', function (Builder $builder) {
            $builder->with('region');
        });
    }

    function region(): BelongsTo {
        return $this->belongsTo(Region::class, 'region_code', 'code');
    }

    function counties(): HasMany {
        return $this->hasMany(County::class, 'country_code', 'code');
    }
}
