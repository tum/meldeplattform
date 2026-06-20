<?php

namespace App\Models;

use App\Enums\ReportState;
use Carbon\CarbonInterface;
use Database\Factories\ReportFactory;
use Illuminate\Database\Eloquent\Builder;
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
 * @property Carbon|null $acknowledged_at
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
        'topic_id', 'reporter_token', 'administrator_token', 'state', 'acknowledged_at', 'creator',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'state' => ReportState::class,
        'acknowledged_at' => 'datetime',
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
     * Record the first administrator acknowledgement. Idempotent: an already
     * acknowledged report is left untouched so the original timestamp stands.
     */
    public function acknowledge(): void
    {
        if ($this->acknowledged_at !== null) {
            return;
        }

        $this->acknowledged_at = Carbon::now();
        $this->save();
    }

    public function isAcknowledged(): bool
    {
        return $this->acknowledged_at !== null;
    }

    public function acknowledgementDueAt(): ?CarbonInterface
    {
        return $this->created_at?->copy()->addDays(self::ackDeadlineDays());
    }

    public function feedbackDueAt(): ?CarbonInterface
    {
        return $this->created_at?->copy()->addDays(self::feedbackDeadlineDays());
    }

    /**
     * Overdue when the acknowledgement window has elapsed and the report is
     * still awaiting acknowledgement. Closed/spam reports are never overdue.
     */
    public function isAcknowledgementOverdue(): bool
    {
        if ($this->isAcknowledged() || $this->isClosed() || $this->isSpam()) {
            return false;
        }

        $due = $this->acknowledgementDueAt();

        return $due !== null && Carbon::now()->greaterThan($due);
    }

    /**
     * Overdue when the feedback window has elapsed while the report is still
     * being handled. Closed/spam reports are considered resolved, so never
     * overdue.
     */
    public function isFeedbackOverdue(): bool
    {
        if ($this->isClosed() || $this->isSpam()) {
            return false;
        }

        $due = $this->feedbackDueAt();

        return $due !== null && Carbon::now()->greaterThan($due);
    }

    /**
     * Reports that may need attention for SLA purposes: still open or in
     * progress (i.e. not closed/spam). Whether each is actually overdue is
     * decided per-row by the *Overdue() helpers, since that depends on `now`.
     *
     * @param Builder<Report> $query
     * @return Builder<Report>
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereIn('state', [ReportState::Open->value, ReportState::InProgress->value]);
    }

    /**
     * Reports that are actually past a deadline right now — the SQL mirror of
     * isAcknowledgementOverdue() / isFeedbackOverdue(), so a count() over this
     * scope stays accurate under pagination (unlike filtering a single page).
     *
     * @param Builder<Report> $query
     * @return Builder<Report>
     */
    public function scopeOverdueNow(Builder $query): Builder
    {
        $now = Carbon::now();
        $ackCutoff = $now->copy()->subDays(self::ackDeadlineDays());
        $feedbackCutoff = $now->copy()->subDays(self::feedbackDeadlineDays());

        return $query
            ->whereIn('state', [ReportState::Open->value, ReportState::InProgress->value])
            ->where(function (Builder $q) use ($ackCutoff, $feedbackCutoff): void {
                $q->where(function (Builder $q) use ($ackCutoff): void {
                    $q->whereNull('acknowledged_at')->where('created_at', '<', $ackCutoff);
                })->orWhere('created_at', '<', $feedbackCutoff);
            });
    }

    private static function ackDeadlineDays(): int
    {
        $days = config('meldeplattform.acknowledgement_deadline_days', 7);

        return is_int($days) ? $days : (int) (is_numeric($days) ? $days : 7);
    }

    private static function feedbackDeadlineDays(): int
    {
        $days = config('meldeplattform.feedback_deadline_days', 90);

        return is_int($days) ? $days : (int) (is_numeric($days) ? $days : 90);
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
