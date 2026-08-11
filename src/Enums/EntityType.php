<?php

namespace Ionutgrecu\LaravelGeo\Enums;

enum EntityType: string {
    case Continent     = 'continent';
    case Region        = 'region';
    case Country       = 'country';
    case State         = 'state';
    case Province      = 'province';
    case Territory     = 'territory';
    case County        = 'county';
    case District      = 'district';
    case Department    = 'department';
    case Prefecture    = 'prefecture';
    case Canton        = 'canton';
    case Parish        = 'parish';
    case Municipality  = 'municipality';
    case City          = 'city';
    case Town          = 'town';
    case Village       = 'village';
    case Hamlet        = 'hamlet';
    case Borough       = 'borough';
    case Suburb        = 'suburb';
    case Quarter       = 'quarter';
    case Neighborhood  = 'neighborhood';
}