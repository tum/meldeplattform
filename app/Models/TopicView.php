<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property int $user_id
 * @property int $topic_id
 * @property Carbon $last_seen_at
 */
class TopicView extends Model
{
    public $timestamps = false;

    protected $primaryKey = null;

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = ['user_id', 'topic_id', 'last_seen_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * Atomic upsert: mark this topic as "just seen" by the given user.
     */
    public static function markSeen(int $userId, int $topicId): void
    {
        DB::table('topic_views')->updateOrInsert(
            ['user_id' => $userId, 'topic_id' => $topicId],
            ['last_seen_at' => now()],
        );
    }
}
