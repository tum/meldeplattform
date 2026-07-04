<?php

namespace App\Models;

use App\Support\Markdown;
use Database\Factories\TopicFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name_de
 * @property string $name_en
 * @property string|null $summary_de
 * @property string|null $summary_en
 * @property string|null $email
 * @property array<string, array<string, string>>|null $contacts
 * @property bool $require_login
 * @property int|null $retention_days
 * @property Carbon|null $deactivated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Field> $fields
 * @property-read Collection<int, Report> $reports
 * @property-read Collection<int, Admin> $admins
 */
class Topic extends Model
{
    /** @use HasFactory<TopicFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'name_de', 'name_en', 'summary_de', 'summary_en', 'email', 'contacts', 'require_login', 'retention_days',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'contacts' => 'array',
            'require_login' => 'boolean',
            'retention_days' => 'integer',
            'deactivated_at' => 'datetime',
        ];
    }

    /**
     * A topic is active (public, accepting new reports) while `deactivated_at`
     * is null. Deactivating hides it from the public list and blocks new
     * submissions; existing reports remain fully manageable either way.
     */
    public function isActive(): bool
    {
        return $this->deactivated_at === null;
    }

    public function isDeactivated(): bool
    {
        return $this->deactivated_at !== null;
    }

    /** Take the topic offline. Idempotent — an already-offline topic is left as-is. */
    public function deactivate(): void
    {
        if ($this->deactivated_at === null) {
            $this->deactivated_at = Carbon::now();
            $this->save();
        }
    }

    /** Bring the topic back online. Idempotent. */
    public function activate(): void
    {
        if ($this->deactivated_at !== null) {
            $this->deactivated_at = null;
            $this->save();
        }
    }

    /**
     * A topic may only be hard-deleted while it holds no reports. Reports carry
     * a statutory retention duty (HinSchG §11(5)), so a topic with history is
     * deactivated rather than deleted — deletion here would cascade its reports
     * away (see the reports foreign key). Callers must still enforce this; the
     * helper drives the UI hint and the controller guard.
     */
    public function isDeletable(): bool
    {
        return $this->reports()->doesntExist();
    }

    /**
     * Constrain a query to active (non-deactivated) topics — the set shown to
     * the public and reachable for new submissions.
     *
     * @param Builder<Topic> $query
     * @return Builder<Topic>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('deactivated_at');
    }

    /**
     * Effective data-retention window in days: the topic's own value, else the
     * global default, else null (keep forever / pruning disabled).
     */
    public function effectiveRetentionDays(): ?int
    {
        if ($this->retention_days !== null) {
            return $this->retention_days;
        }

        $default = config('meldeplattform.default_retention_days');

        return is_numeric($default) ? (int) $default : null;
    }

    /** @return HasMany<Field, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(Field::class)->orderBy('position');
    }

    /** @return HasMany<Report, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    /** @return BelongsToMany<Admin, $this> */
    public function admins(): BelongsToMany
    {
        return $this->belongsToMany(Admin::class, 'topic_admins');
    }

    /**
     * Constrain a query to the topics the given user may manage. Mirrors
     * TopicPolicy: a global admin (env allowlist or `is_global_admin`) sees
     * every topic; everyone else is limited to topics whose `topic_admins`
     * pivot names their UID. Pushing this into SQL keeps the dashboard and
     * the layout composer scalable instead of loading every topic and
     * filtering in PHP.
     *
     * @param Builder<Topic> $query
     * @return Builder<Topic>
     */
    public function scopeManageableBy(Builder $query, User $user): Builder
    {
        if ($user->isGlobalAdmin()) {
            return $query;
        }

        return $query->whereHas('admins', static function (Builder $q) use ($user): void {
            /** @var Builder<Admin> $q */
            $q->where('user_id', $user->uid);
        });
    }

    public function name(string $lang): string
    {
        return $lang === 'de' ? ($this->name_de !== '' ? $this->name_de : $this->name_en) : ($this->name_en !== '' ? $this->name_en : $this->name_de);
    }

    public function summary(string $lang): string
    {
        $value = $lang === 'de'
            ? ($this->summary_de ?? $this->summary_en)
            : ($this->summary_en ?? $this->summary_de);

        return $value ?? '';
    }

    /**
     * Render the summary's markdown to sanitised HTML for display. Uses the
     * same restrictive pipeline as report messages, so operator-authored
     * formatting (bold, lists, links) is allowed but unsafe HTML is stripped,
     * plus the brand colour shortcodes ({green}…{/green} etc.).
     */
    public function renderedSummary(string $lang): string
    {
        return Markdown::sanitizeWithColors($this->summary($lang));
    }

    public function isAdmin(?string $uid): bool
    {
        if ($uid === null || $uid === '') {
            return false;
        }

        return $this->admins->contains(fn (Admin $a): bool => $a->user_id === $uid);
    }
}
