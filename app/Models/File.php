<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property string $location
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Message> $messages
 */
class File extends Model
{
    /** @var list<string> */
    protected $fillable = ['uuid', 'location', 'name'];

    protected static function booted(): void
    {
        static::creating(function (File $file): void {
            $file->uuid ??= (string) Str::uuid();
        });
    }

    /** @return BelongsToMany<Message, $this> */
    public function messages(): BelongsToMany
    {
        return $this->belongsToMany(Message::class, 'message_files');
    }
}
