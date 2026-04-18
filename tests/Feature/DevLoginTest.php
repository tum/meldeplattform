<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DevLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_dev_login_available_in_testing_env(): void
    {
        $this->get('/dev/login')->assertOk();
    }

    public function test_dev_login_sets_session_user(): void
    {
        $this->post('/dev/login', [
            'uid' => 'globaladmin',
            'name' => 'Test Admin',
            'email' => 'admin@example.com',
        ])->assertRedirect('/');

        $this->assertSame([
            'uid' => 'globaladmin',
            'name' => 'Test Admin',
            'email' => 'admin@example.com',
        ], session('saml_user'));
    }

    public function test_dev_login_rejects_empty_uid(): void
    {
        $response = $this->from('/dev/login')->post('/dev/login', ['uid' => '']);
        $response->assertRedirect('/dev/login');
        $response->assertSessionHasErrors('uid');
    }

    public function test_dev_logout_clears_session(): void
    {
        $this->withSession(['saml_user' => ['uid' => 'x', 'name' => 'x', 'email' => 'x@x']])
            ->get('/dev/logout')
            ->assertRedirect('/');
        $this->assertNull(session('saml_user'));
    }
}
