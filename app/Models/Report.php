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
 * @property string|null $receipt_hash
 * @property ReportState $state
 * @property Carbon|null $acknowledged_at
 * @property Carbon|null $closed_at
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
        'topic_id', 'reporter_token', 'receipt_hash', 'state', 'acknowledged_at', 'closed_at', 'creator',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'state' => ReportState::class,
        'acknowledged_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Report $report): void {
            $report->reporter_token ??= (string) Str::uuid();
            $report->state ??= ReportState::Open;
            // A report seeded directly in a concluded state still needs its
            // retention anchor. (Normal flow always creates Open and concludes
            // later, which the updating hook below handles.)
            if ($report->isConcluded() && $report->closed_at === null) {
                $report->closed_at = Carbon::now();
            }
        });

        // Keep `closed_at` in lock-step with the workflow state so retention
        // (HinSchG §11(5): delete 3 years after the procedure is concluded)
        // is anchored on conclusion. Stamped when a report first becomes
        // Done/Spam; cleared if it is reopened. Mass updates that bypass model
        // events (bulkSetStatus) maintain the column explicitly instead.
        static::updating(function (Report $report): void {
            if ($report->isDirty('state')) {
                $report->syncClosedAt();
            }
        });
    }

    /**
     * A report's procedure is concluded once it is closed (Done) or filed as
     * spam — both end the case for retention purposes.
     */
    public function isConcluded(): bool
    {
        return $this->state === ReportState::Done || $this->state === ReportState::Spam;
    }

    /**
     * Stamp/clear `closed_at` to match the current state. Stamping is
     * idempotent so reopening then re-closing does not reset the original
     * conclusion date until the report is actually reopened.
     */
    private function syncClosedAt(): void
    {
        if ($this->isConcluded()) {
            $this->closed_at ??= Carbon::now();

            return;
        }

        $this->closed_at = null;
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
     * Uses an atomic WHERE NULL update to prevent a race where two concurrent
     * requests both pass the in-memory guard before either write commits.
     */
    public function acknowledge(): void
    {
        if ($this->acknowledged_at !== null) {
            return;
        }

        $now = Carbon::now();
        $rows = self::where('id', $this->id)
            ->whereNull('acknowledged_at')
            ->update(['acknowledged_at' => $now]);

        if ($rows > 0) {
            $this->acknowledged_at = $now;
        }
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
     * Alias of scopeOverdue with an intent-revealing name for the reminder job.
     *
     * @param Builder<Report> $query
     * @return Builder<Report>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $this->scopeOverdue($query);
    }

    /**
     * True when the acknowledgement deadline is within $leadDays from now (or
     * already passed) while the report still awaits acknowledgement — i.e. a
     * reminder to the case handlers is warranted. Concluded reports never are.
     */
    public function needsAcknowledgementReminder(int $leadDays): bool
    {
        if ($this->isAcknowledged() || $this->isClosed() || $this->isSpam()) {
            return false;
        }

        $due = $this->acknowledgementDueAt();

        return $due !== null && Carbon::now()->addDays($leadDays)->greaterThanOrEqualTo($due);
    }

    /**
     * True when the feedback deadline is within $leadDays from now (or already
     * passed) while the report is still being handled.
     */
    public function needsFeedbackReminder(int $leadDays): bool
    {
        if ($this->isClosed() || $this->isSpam()) {
            return false;
        }

        $due = $this->feedbackDueAt();

        return $due !== null && Carbon::now()->addDays($leadDays)->greaterThanOrEqualTo($due);
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
                })->orWhere(function (Builder $q) use ($feedbackCutoff): void {
                    $q->whereNotNull('acknowledged_at')->where('created_at', '<', $feedbackCutoff);
                });
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
     * Generate a fresh 24-character hex receipt code (12 random bytes = 96 bits
     * of entropy), store its keyed HMAC hash, and return the plaintext uppercase
     * code (also kept on $plainReceiptCode for single display). The plaintext
     * is shown to the reporter exactly once and never persisted.
     *
     * Legacy 16-digit numeric codes remain valid: normaliseReceipt strips
     * non-hex characters and lowercases, so digits-only codes still hash
     * identically under the new normaliser.
     */
    public function issueReceiptCode(): string
    {
        $code = strtoupper(bin2hex(random_bytes(12)));

        $this->receipt_hash = self::hashReceipt(strtolower($code)); // normalize before hash
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

        return hash_hmac('sha256', $code, $key);
    }

    private static function normalizeReceipt(string $code): string
    {
        // Strip anything that isn't a hex digit; lowercase for consistent
        // hashing. Legacy numeric-only codes (0-9) are a subset of hex and
        // hash identically to before.
        return strtolower(preg_replace('/[^0-9a-fA-F]+/', '', $code) ?? '');
    }
}
