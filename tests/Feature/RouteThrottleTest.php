<?php

namespace Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RouteThrottleTest extends TestCase
{
    public function test_polling_does_not_consume_other_actions_limits(): void
    {
        $this->actingAs(new GenericUser(['id' => 123]));
        Route::post('/throttle-test/state', fn () => response()->json(['ok' => true]))->middleware('throttle:120,1');
        Route::post('/throttle-test/send/{id}', fn () => response()->json(['ok' => true]))->middleware('throttle:2,1');

        for ($i = 0; $i < 35; $i++) {
            $this->postJson('/throttle-test/state')->assertOk();
        }

        $this->postJson('/throttle-test/send/1')->assertOk();
        $this->postJson('/throttle-test/send/2')->assertOk();
        $this->postJson('/throttle-test/send/3')->assertStatus(429)->assertHeader('Retry-After');
        $this->postJson('/throttle-test/state')->assertOk();
    }

    public function test_guest_routes_have_independent_limits_and_still_block_abuse(): void
    {
        Route::post('/throttle-test/login', fn () => response()->json(['ok' => true]))->middleware('throttle:2,1');
        Route::post('/throttle-test/register', fn () => response()->json(['ok' => true]))->middleware('throttle:1,60');

        $this->postJson('/throttle-test/login')->assertOk();
        $this->postJson('/throttle-test/login')->assertOk();
        $this->postJson('/throttle-test/login')->assertStatus(429);
        $this->postJson('/throttle-test/register')->assertOk();
        $this->postJson('/throttle-test/register')->assertStatus(429);
    }
}
