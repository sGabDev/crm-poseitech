<?php

namespace App\Database;

class AccountLimits
{
    /** Extract only numeric resource limits; never expose account names or grants. */
    public static function parse(array $grants): array
    {
        $text = implode("\n", array_map(fn ($row) => implode('', (array) $row), $grants));
        preg_match_all('/\b(MAX_QUERIES_PER_HOUR|MAX_UPDATES_PER_HOUR|MAX_CONNECTIONS_PER_HOUR|MAX_USER_CONNECTIONS)\s+(\d+)\b/i', $text, $matches, PREG_SET_ORDER);
        $limits = [];
        foreach ($matches as $match) {
            $limits[strtolower($match[1])] = (int) $match[2];
        }

        return $limits;
    }
}
