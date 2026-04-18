<?php

namespace App\Services\Messengers;

use App\Models\Message;

interface Messenger
{
    public function send(string $title, Message $message, string $reportUrl): void;
}
