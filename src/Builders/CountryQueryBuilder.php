<?php

namespace Ionutgrecu\LaravelGeo\Builders;

use Illuminate\Database\Eloquent\Builder;
use Websea\Iqapp\Helpers\iq;
use function is_array;

class CountryQueryBuilder extends Builder {
    function find($identifier, $columns = ['*']) {
        if (is_array($identifier)) {
            return $this->whereIn('code', $identifier)
                ->orWhereIn('iso2', $identifier)
                ->orWhereIn('iso3', $identifier)
                ->orWhereIn('iso_numeric', $identifier)
                ->orWhereIn('name', $identifier)
                ->orWhereIn('name_int', $identifier)
                ->get($columns);
        }

        return $this->where('code', $identifier)
            ->orWhere('iso2', $identifier)
            ->orWhere('iso3', $identifier)
            ->orWhere('iso_numeric', $identifier)
            ->orWhere('name', $identifier)
            ->orWhere('name_int', $identifier)
            ->first($columns);
    }
}