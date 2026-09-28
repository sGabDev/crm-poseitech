<?php

namespace App\Services;

class PortalLink
{
    public static function token(object $customer): ?string
    {
        if (! $customer->portal_hash || $customer->anonymized_at) {
            return null;
        }
        $signature = hash_hmac('sha256', 'portal:'.$customer->company_id.':'.$customer->id.':'.$customer->portal_hash, config('app.key'));

        return 'p.'.$customer->id.'.'.$signature;
    }

    public static function url(object $customer): ?string
    {
        $token = self::token($customer);

        return $token ? url('/portal/access/'.$token) : null;
    }
}
