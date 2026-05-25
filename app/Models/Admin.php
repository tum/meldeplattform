<?php

namespace App\Models;

use Database\Factories\AdminFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Topic> $topics
 * @property-read User|null $user
 */
class Admin extends Model
{
    /** @use HasFactory<AdminFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['user_id'];

    /** @return BelongsToMany<Topic, $this> */
    public function topics(): BelongsToMany
    {
        return $this->belongsToMany(Topic::class, 'topic_admins');
    }

    /**
     * Optional link back to the authenticated user. `admins.user_id` is a
     * free-text UID matched against `users.uid` — an admin row can exist
     * before the user has ever logged in, so the relation is nullable.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'uid');
    }
}
