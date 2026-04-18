<?php

namespace Tests;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Disable CSRF across the suite – we test the controllers, not the
        // Laravel-internal session-token handshake.
        $this->withoutMiddleware(PreventRequestForgery::class);

        // Ensure the test "globaladmin" UID is always recognised, regardless
        // of the ambient `MELDE_ADMIN_USERS` env coming from Docker/CI.
        config(['meldeplattform.admin_users' => ['globaladmin']]);
    }
}
