<?php

namespace App\Enums;

enum ReportState: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Spam = 'spam';

    public function label(): string
    {
        return match ($this) {
            self::Open => __('status_open'),
            self::InProgress => __('status_in_progress'),
            self::Done => __('status_done'),
            self::Spam => __('status_spam'),
        };
    }

    public function allowsReply(): bool
    {
        // Reporters may still reply while the report is open or actively
        // being worked; closed (Done) and Spam end the conversation.
        return match ($this) {
            self::Open, self::InProgress => true,
            self::Done, self::Spam => false,
        };
    }
}
