<?php

namespace Ionutgrecu\LaravelGeo\Models;

use Illuminate\Database\Eloquent\Model;
use Ionutgrecu\LaravelGeo\Traits\HasUlidPrimaryKeyTrait;

class ReverseGeocodeCache extends Model {
    use HasUlidPrimaryKeyTrait;

    protected $table = 'geo_reverse_geocodes';
    protected $guarded = ['id', 'created_at'];
    public $incrementing = false;
    protected $keyType = 'string';

    protected $casts = [
        'latitude' => 'decimal:4',
        'longitude' => 'decimal:4',
        'zoom' => 'integer',
        'results' => 'array',
        'raw_response' => 'array',
        'http_status' => 'integer',
        'expires_at' => 'datetime',
        'last_hit_at' => 'datetime',
    ];

    public function getConnectionName() {
        return config('geo.database_connection');
    }

    public function isFresh(): bool {
        return $this->expires_at !== null && $this->expires_at->isFuture();
    }
}