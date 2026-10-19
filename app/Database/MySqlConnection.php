<?php

namespace App\Database;

use Closure;
use Illuminate\Database\QueryException;

class MySqlConnection extends \Illuminate\Database\MySqlConnection
{
    protected function tryAgainIfCausedByLostConnection(QueryException $e, $query, $bindings, Closure $callback)
    {
        // A lost acknowledgement does not prove that a write was not committed.
        // Reconciliation belongs to the operation's idempotency protocol.
        throw $e;
    }
}
