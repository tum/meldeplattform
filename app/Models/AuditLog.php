<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Append-only audit trail of security-relevant administrative actions.
 *
 * Rows are written once and never mutated or deleted via the application —
 * the booted() guards enforce that. The model is privacy-aware: it records
 * the acting *admin* and a lightweight subject reference, never reporter PII
 * or report content.
 *
 * @property int $id
 * @property string|null $actor
 * @property string $action
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 */
class AuditLog extends Model
{
    // Append-only: only created_at is managed; there is no updated_at column.
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['actor', 'action', 'subject_type', 'subject_id', 'metadata'];

    /** @var array<string, string> */
    protected $casts = [
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        // Append-only guard: audit rows must never be mutated or deleted via
        // the app, so any update/delete attempt is a hard error.
        static::updating(function (AuditLog $log): void {
            throw new RuntimeException('Audit log entries are append-only and cannot be updated.');
        });

        static::deleting(function (AuditLog $log): void {
            throw new RuntimeException('Audit log entries are append-only and cannot be deleted.');
        });
    }

    /**
     * Map a known subject model to its short audit type string. Returns null
     * for unknown classes so the reference simply stays empty rather than
     * leaking a fully-qualified class name into the log.
     */
    private static function subjectType(Model $subject): ?string
    {
        return match ($subject::class) {
            Report::class => 'report',
            Topic::class => 'topic',
            User::class => 'user',
            Admin::class => 'admin',
            default => null,
        };
    }

    /**
     * Append an audit entry. Resolves the actor from the authenticated user
     * (their uid, or 'system' when no user is bound) and reduces the optional
     * subject to a short type string + id.
     *
     * @param array<string, mixed> $metadata
     */
    public static function record(string $action, ?Model $subject = null, array $metadata = []): self
    {
        $user = Auth::user();
        $actor = $user instanceof User ? $user->uid : 'system';

        $subjectType = null;
        $subjectId = null;
        if ($subject !== null) {
            $subjectType = self::subjectType($subject);
            $key = $subject->getKey();
            $subjectId = is_numeric($key) ? (int) $key : null;
        }

        return self::create([
            'actor' => $actor,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }
}
