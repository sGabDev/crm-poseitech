<?php

namespace App\Database;

use Closure;
use Illuminate\Database\QueryException;

class MariaDbConnection extends \Illuminate\Database\MariaDbConnection
{
    protected function tryAgainIfCausedByLostConnection(QueryException $e, $query, $bindings, Closure $callback)
    {
        throw $e;
    }
}
