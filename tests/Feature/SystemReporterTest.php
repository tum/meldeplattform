<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;
use Tum\SystemReporter\Reporter;

class SystemReporterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // No apt-get in tests: the result would depend on the runner.
        config(['system-reporter.os_patch' => false]);
    }

    public function test_the_reporter_sends_nothing_from_tests(): void
    {
        Http::fake();

        $command = $this->artisan('system-reporter:report');
        $this->assertInstanceOf(PendingCommand::class, $command);
        $command->expectsOutputToContain('deaktiviert')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_the_payload_describes_the_app_without_secrets(): void
    {
        $appKey = 'base64:'.base64_encode('geheimer-schluessel-0123456789ab');
        config([
            'app.key' => $appKey,
            'database.connections.mysql.password' => 'geheimes-db-passwort',
        ]);

        $payload = app(Reporter::class)->payload();
        $json = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);

        $this->assertSame('tum.system-report/v2', $payload['schema']);
        $this->assertSame('laravel', data_get($payload, 'app.framework.name'));
        $this->assertContains('laravel/framework', (array) data_get($payload, 'packages.composer.*.name'));
        // The stored (base64) form is what a leak would contain.
        $this->assertStringNotContainsString(substr($appKey, 7), $json);
        $this->assertStringNotContainsString('geheimes-db-passwort', $json);
    }
}
