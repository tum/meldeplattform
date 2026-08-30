<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class DevLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_dev_login_available_in_testing_env(): void
    {
        $this->get('/dev/login')->assertOk();
    }

    public function test_dev_login_authenticates_user(): void
    {
        $this->post('/dev/login', [
            'uid' => 'globaladmin',
            'name' => 'Test Admin',
            'email' => 'admin@example.com',
        ])->assertRedirect('/');

        $this->assertDatabaseHas('users', [
            'uid' => 'globaladmin',
            'name' => 'Test Admin',
            'email' => 'admin@example.com',
        ]);
        $this->assertTrue(Auth::check());
        $this->assertSame('globaladmin', Auth::user()?->uid);
    }

    public function test_dev_login_rejects_empty_uid(): void
    {
        $response = $this->from('/dev/login')->post('/dev/login', ['uid' => '']);
        $response->assertRedirect('/dev/login');
        $response->assertSessionHasErrors('uid');
    }

    public function test_dev_logout_clears_auth(): void
    {
        $user = User::create(['uid' => 'x', 'name' => 'x', 'email' => 'x@x']);
        $this->actingAs($user)
            ->post('/dev/logout')
            ->assertRedirect('/');
        $this->assertFalse(Auth::check());
    }

    public function test_dev_logout_is_not_reachable_by_a_cross_origin_get(): void
    {
        // Forced logout (CWE-352): a GET would fire from a bare <img src=…> on
        // any site the victim visits.
        $user = User::create(['uid' => 'x', 'name' => 'x', 'email' => 'x@x']);
        $this->actingAs($user)->get('/dev/logout')->assertMethodNotAllowed();
        $this->assertTrue(Auth::check());
    }
}
