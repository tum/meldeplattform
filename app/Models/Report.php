<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $topic_id
 * @property string $reporter_token
 * @property string $administrator_token
 * @property string $state
 * @property string|null $creator
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Topic $topic
 * @property-read Collection<int, Message> $messages
 */
class Report extends Model
{
    public const STATE_OPEN = 'open';

    public const STATE_DONE = 'done';

    public const STATE_SPAM = 'spam';

    /** @var list<string> */
    protected $fillable = [
        'topic_id', 'reporter_token', 'administrator_token', 'state', 'creator',
    ];

    protected static function booted(): void
    {
        static::creating(function (Report $report): void {
            $report->reporter_token ??= (string) Str::uuid();
            $report->administrator_token ??= (string) Str::uuid();
            $report->state ??= self::STATE_OPEN;
        });
    }

    /** @return BelongsTo<Topic, $this> */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->oldest();
    }

    public function isClosed(): bool
    {
        return $this->state === self::STATE_DONE;
    }

    public function isSpam(): bool
    {
        return $this->state === self::STATE_SPAM;
    }

    public function statusColor(): string
    {
        return match ($this->state) {
            self::STATE_OPEN => 'rgb(220 38 38)',
            self::STATE_DONE => 'rgb(74 222 128)',
            default => '#000',
        };
    }

    public function statusLabel(): string
    {
        return match ($this->state) {
            self::STATE_OPEN => 'Open',
            self::STATE_DONE => 'Done',
            self::STATE_SPAM => 'Spam',
            default => 'Unknown',
        };
    }

    public function dateFmt(): string
    {
        return $this->created_at?->format('d.m.Y H:i') ?? '';
    }
}
