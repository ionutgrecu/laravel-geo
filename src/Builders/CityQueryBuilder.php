<?php

namespace Ionutgrecu\LaravelGeo\Builders;

use Illuminate\Database\Eloquent\Builder;
use function is_array;

class CityQueryBuilder extends Builder {
    function find($identifier, $columns = ['*']) {
        if (is_array($identifier)) {
            return $this->whereIn('code', $identifier)
                ->orWhereIn('name', $identifier)
                ->get($columns);
        }

        return $this->where('code', $identifier)
            ->orWhere('name', $identifier)
            ->first($columns);
    }
}
