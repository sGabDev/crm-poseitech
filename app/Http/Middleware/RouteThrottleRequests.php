<?php

namespace App\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests;

class RouteThrottleRequests extends ThrottleRequests
{
    protected function resolveRequestSignature($request)
    {
        // Use the route template so IDs cannot bypass the action's limit.
        $route = $request->route();

        return parent::resolveRequestSignature($request).'|'.implode('|', $route->methods()).'|'.$route->uri();
    }
}
