<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
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
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Field> $fields
 * @property-read Collection<int, Report> $reports
 * @property-read Collection<int, Admin> $admins
 */
class Topic extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'name_de', 'name_en', 'summary_de', 'summary_en', 'email', 'contacts',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'contacts' => 'array',
    ];

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

    public function isAdmin(?string $uid): bool
    {
        if ($uid === null || $uid === '') {
            return false;
        }

        return $this->admins->contains(fn (Admin $a): bool => $a->user_id === $uid);
    }
}
