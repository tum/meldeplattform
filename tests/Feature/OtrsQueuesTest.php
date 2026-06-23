<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OtrsQueuesTest extends TestCase
{
    use RefreshDatabase;

    private function configureQueueList(string $url = 'https://otrs.test/ws/TUM-SafeSignal/Queue'): void
    {
        config(['meldeplattform.otrs' => [
            'base_url' => 'https://otrs.test/ws/TUM-SafeSignal',
            'user_login' => 'svc',
            'password' => 'pw',
            'timeout' => 10,
            'queue_list_url' => $url,
        ]]);
    }

    public function test_queue_list_requires_auth(): void
    {
        $this->getJson('/api/otrs/queues')->assertUnauthorized();
    }

    public function test_returns_empty_and_calls_nothing_when_not_configured(): void
    {
        $this->configureQueueList('');
        Http::fake();

        $this->actingAsGlobalAdmin()->getJson('/api/otrs/queues')
            ->assertOk()
            ->assertExactJson(['queues' => []]);

        Http::assertNothingSent();
    }

    public function test_returns_sorted_unique_names_from_a_string_list(): void
    {
        $this->configureQueueList();
        Http::fake(['*' => Http::response(['Raw', 'Postmaster', 'Misc', 'Raw'], 200)]);

        $this->actingAsGlobalAdmin()->getJson('/api/otrs/queues')
            ->assertOk()
            ->assertExactJson(['queues' => ['Misc', 'Postmaster', 'Raw']]);
    }

    public function test_parses_objects_nested_under_a_queue_key(): void
    {
        $this->configureQueueList();
        Http::fake(['*' => Http::response([
            'Queue' => [
                ['QueueID' => 2, 'Name' => 'Whistleblowing'],
                ['QueueID' => 1, 'Name' => 'Raw'],
            ],
        ], 200)]);

        $this->actingAsGlobalAdmin()->getJson('/api/otrs/queues')
            ->assertOk()
            ->assertExactJson(['queues' => ['Raw', 'Whistleblowing']]);
    }

    public function test_returns_empty_when_otrs_reports_an_error(): void
    {
        $this->configureQueueList();
        Http::fake(['*' => Http::response(['Error' => ['ErrorCode' => 'X', 'ErrorMessage' => 'no']], 200)]);

        $this->actingAsGlobalAdmin()->getJson('/api/otrs/queues')
            ->assertOk()
            ->assertExactJson(['queues' => []]);
    }

    public function test_non_https_queue_url_is_refused(): void
    {
        $this->configureQueueList('http://otrs.test/ws/TUM-SafeSignal/Queue');
        Http::fake();

        $this->actingAsGlobalAdmin()->getJson('/api/otrs/queues')
            ->assertOk()
            ->assertExactJson(['queues' => []]);

        Http::assertNothingSent();
    }

    public function test_any_authenticated_admin_may_list_queues(): void
    {
        // Topic-admins (not global admins) edit their own topics, so they must
        // be able to populate the queue dropdown too — the route is auth-only.
        $this->configureQueueList();
        Http::fake(['*' => Http::response(['Raw'], 200)]);

        $this->actingAsUser('ge99tum')->getJson('/api/otrs/queues')
            ->assertOk()
            ->assertExactJson(['queues' => ['Raw']]);
    }
}
