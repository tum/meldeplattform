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
 * @property string|null $receipt_hash
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

    /**
     * Transient plaintext receipt code, populated by issueReceiptCode() so the
     * caller can show it once. Never persisted — only its HMAC lives in the DB.
     */
    public ?string $plainReceiptCode = null;

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

    /**
     * Generate a fresh 16-digit numeric receipt code, store its keyed hash,
     * and return the plaintext (also kept on $plainReceiptCode). The plaintext
     * is shown to the reporter exactly once and never persisted.
     */
    public function issueReceiptCode(): string
    {
        $code = '';
        for ($i = 0; $i < 16; $i++) {
            $code .= (string) random_int(0, 9);
        }

        $this->receipt_hash = self::hashReceipt($code);
        $this->save();

        return $this->plainReceiptCode = $code;
    }

    public static function findByReceiptCode(string $code): ?self
    {
        $normalized = self::normalizeReceipt($code);
        if ($normalized === '') {
            return null;
        }

        return self::where('receipt_hash', self::hashReceipt($normalized))->first();
    }

    protected static function hashReceipt(string $code): string
    {
        $key = config('app.key');
        $key = is_string($key) ? $key : '';

        return hash_hmac('sha256', self::normalizeReceipt($code), $key);
    }

    private static function normalizeReceipt(string $code): string
    {
        return preg_replace('/\D+/', '', $code) ?? '';
    }
}
