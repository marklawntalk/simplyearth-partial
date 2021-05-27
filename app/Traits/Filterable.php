<?php

namespace App\Traits;
use App\QueryFilters;

/**
 * 
 */
trait Filterable
{
    function scopeFilter($query, QueryFilters $filters)
    {
        return $filters->apply($query);
    }
}
