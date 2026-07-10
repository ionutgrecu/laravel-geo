<?php

namespace Ionutgrecu\LaravelGeo\Builders;

use Illuminate\Database\Eloquent\Builder;
use function is_array;

class RegionQueryBuilder extends Builder {
    function find($identifier, $columns = ['*']) {
        if (is_array($identifier)) {
            return $this->whereIn('code', $identifier)
                ->orWhereIn('iso2', $identifier)
                ->orWhereIn('name', $identifier)
                ->get($columns);
        }

        return $this->where('code', $identifier)
            ->orWhere('iso2', $identifier)
            ->orWhere('name', $identifier)
            ->first($columns);
    }
}