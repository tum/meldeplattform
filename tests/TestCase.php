<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

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

    /**
     * Authenticate as the env-allowlisted global admin. Returns `$this`
     * so callers can chain into request helpers
     * (`$this->actingAsGlobalAdmin()->postJson(...)`).
     */
    protected function actingAsGlobalAdmin(): self
    {
        $user = User::updateOrCreate(
            ['uid' => 'globaladmin'],
            ['name' => 'Global Admin', 'email' => 'globaladmin@example.com'],
        );

        return $this->actingAs($user);
    }

    /**
     * Authenticate as an arbitrary (non-global) user. The user is created
     * on demand from the given UID so tests don't have to seed users
     * themselves before exercising auth-gated endpoints.
     */
    protected function actingAsUser(string $uid): self
    {
        $user = User::updateOrCreate(
            ['uid' => $uid],
            ['name' => $uid, 'email' => "{$uid}@example.com"],
        );

        return $this->actingAs($user);
    }

    /**
     * The paging queries that were run against `reports`, with identifier
     * quoting stripped. SQLite wraps identifiers in double quotes and
     * MySQL in backticks, so the raw SQL differs per connection and a
     * literal match would silently find nothing on the other engine.
     *
     * @return list<string>
     */
    protected function pagedReportQueries(): array
    {
        $queries = [];

        foreach (DB::getQueryLog() as $entry) {
            $query = str_replace(['"', '`'], '', $entry['query']);

            if (str_contains($query, 'from reports') && str_contains($query, 'limit')) {
                $queries[] = $query;
            }
        }

        return $queries;
    }
}
