<?php

namespace Ionutgrecu\LaravelGeo\Models;

use Illuminate\Database\Eloquent\Model;
use Ionutgrecu\LaravelGeo\Traits\HasUlidPrimaryKeyTrait;

class AddressSearchCache extends Model {
    use HasUlidPrimaryKeyTrait;

    protected $table = 'geo_address_searches';
    protected $guarded = ['id', 'created_at'];
    public $incrementing = false;
    protected $keyType = 'string';

    protected $casts = [
        'results' => 'array',
        'raw_response' => 'array',
        'result_count' => 'integer',
        'http_status' => 'integer',
        'limit' => 'integer',
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