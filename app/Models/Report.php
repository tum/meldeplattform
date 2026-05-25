<?php

namespace App\Models;

use App\Enums\ReportState;
use Database\Factories\ReportFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
 * @property ReportState $state
 * @property string|null $creator
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Topic $topic
 * @property-read Collection<int, Message> $messages
 */
class Report extends Model
{
    /** @use HasFactory<ReportFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'topic_id', 'reporter_token', 'administrator_token', 'state', 'creator',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'state' => ReportState::class,
    ];

    protected static function booted(): void
    {
        static::creating(function (Report $report): void {
            $report->reporter_token ??= (string) Str::uuid();
            $report->administrator_token ??= (string) Str::uuid();
            $report->state ??= ReportState::Open;
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
        return $this->state === ReportState::Done;
    }

    public function isSpam(): bool
    {
        return $this->state === ReportState::Spam;
    }

    public function statusLabel(): string
    {
        return $this->state->label();
    }

    public function dateFmt(): string
    {
        return $this->created_at?->format('d.m.Y H:i') ?? '';
    }
}
