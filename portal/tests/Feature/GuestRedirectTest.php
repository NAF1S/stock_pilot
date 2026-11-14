<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * There is no server-rendered login page — `/login` is a React route. The
 * framework nevertheless configures a default guest redirect to route('login')
 * (Illuminate\Foundation\Configuration\ApplicationBuilder::withMiddleware),
 * which does not exist in this app.
 *
 * That default fired inside the Authenticate middleware — before the exception
 * handler ran — so an unauthenticated request that did *not* send
 * `Accept: application/json` produced a 500 "Route [login] not defined" instead
 * of a 401. The SPA always sends the JSON Accept header, so it surfaced only for
 * direct/API clients, but it spammed the production error log.
 */
class GuestRedirectTest extends TestCase
{
    public function test_unauthenticated_api_request_without_json_accept_returns_401(): void
    {
        $this->get('/api/dashboard')->assertStatus(401);
    }

    public function test_unauthenticated_api_request_with_json_accept_returns_401(): void
    {
        $this->getJson('/api/auth/me')
            ->assertStatus(401)
            ->assertJson(['message' => 'Unauthenticated.']);
    }
}
