<?php

namespace App\Enums;

enum ReportState: string
{
    case Open = 'open';
    case Done = 'done';
    case Spam = 'spam';

    public function label(): string
    {
        return match ($this) {
            self::Open => __('status_open'),
            self::Done => __('status_done'),
            self::Spam => __('status_spam'),
        };
    }
}
