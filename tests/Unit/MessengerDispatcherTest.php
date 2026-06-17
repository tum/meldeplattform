<?php

namespace Tests\Unit;

use App\Models\Topic;
use App\Services\MessengerDispatcher;
use App\Services\Messengers\EmailMessenger;
use App\Services\Messengers\WebhookMessenger;
use Tests\TestCase;

class MessengerDispatcherTest extends TestCase
{
    private MessengerDispatcher $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dispatcher = new MessengerDispatcher;
    }

    public function test_topic_email_field_builds_email_messenger(): void
    {
        $t = new Topic;
        $t->email = 'it-sec@tum.de';

        $messengers = $this->dispatcher->forTopic($t);

        $this->assertCount(1, $messengers);
        $this->assertInstanceOf(EmailMessenger::class, $messengers[0]);
    }

    public function test_all_messenger_types_from_contacts(): void
    {
        $t = new Topic;
        $t->contacts = [
            'email' => ['target' => 'a@b.de'],
            'webhook' => ['target' => 'https://hook.example/endpoint'],
        ];

        $messengers = $this->dispatcher->forTopic($t);

        $this->assertCount(2, $messengers);
        $this->assertInstanceOf(EmailMessenger::class, $messengers[0]);
        $this->assertInstanceOf(WebhookMessenger::class, $messengers[1]);
    }

    public function test_empty_contacts_yield_no_messengers(): void
    {
        $t = new Topic;
        $this->assertSame([], $this->dispatcher->forTopic($t));
    }
}
