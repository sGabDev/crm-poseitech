<?php

namespace App\Database;

use Illuminate\Database\DatabaseManager;

class ConnectionLifecycle
{
    public static function release(DatabaseManager $manager): void
    {
        // Only connections already opened by this application; never connect at shutdown.
        foreach ($manager->getConnections() as $connection) {
            if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
                continue;
            }
            try {
                if ($connection->transactionLevel() > 0) {
                    $connection->rollBack(0);
                }
            } catch (\Throwable) {
                // Closing a nonpersistent socket also abandons its open transaction.
                logger()->warning('database.shutdown_rollback_failed');
            } finally {
                $connection->disconnect();
            }
        }
    }
}
