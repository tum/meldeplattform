<?php

namespace App\Models;

use App\Support\Markdown;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $report_id
 * @property string $content
 * @property bool $is_admin
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Report $report
 * @property-read Collection<int, File> $files
 */
class Message extends Model
{
    /** @var list<string> */
    protected $fillable = ['report_id', 'content', 'is_admin'];

    /** @var array<string, string> */
    protected $casts = [
        'is_admin' => 'boolean',
    ];

    /** @return BelongsTo<Report, $this> */
    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    /** @return BelongsToMany<File, $this> */
    public function files(): BelongsToMany
    {
        return $this->belongsToMany(File::class, 'message_files');
    }

    public function renderedBody(): string
    {
        return Markdown::sanitize($this->content);
    }
}
