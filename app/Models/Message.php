<?php

namespace App\Models;

use App\Support\AttachmentLinks;
use App\Support\Markdown;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $report_id
 * @property string $content
 * @property bool $is_admin
 * @property string|null $source
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Report $report
 * @property-read Collection<int, File> $files
 */
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['report_id', 'content', 'is_admin', 'source'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_admin' => 'boolean',
        ];
    }

    /**
     * Touch the parent Report on save/delete so its `updated_at` reflects
     * the freshest message timestamp. Drives the per-topic unread badge on
     * the admin home page (counts reports.updated_at > topic_views.last_seen_at).
     *
     * @var list<string>
     */
    protected $touches = ['report'];

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

    /**
     * Sanitised message HTML, with the plain download links the body carries
     * for its uploads rendered as attachment cards. Callers wanting the raw
     * markdown (messenger pushes, exports) keep using $content.
     *
     * $reporterToken is the access token of the reporter *currently viewing*
     * the thread; it is stitched into the attachment links so their downloads
     * authorise, and is passed only on the reporter-facing render. The stored
     * body never contains it — see AttachmentLinks.
     */
    public function renderedBody(?string $reporterToken = null): string
    {
        return AttachmentLinks::decorate(Markdown::sanitize($this->content), $this->files, $reporterToken);
    }
}
