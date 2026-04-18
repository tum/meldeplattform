<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $topic_id
 * @property string $name_de
 * @property string $name_en
 * @property string|null $description_de
 * @property string|null $description_en
 * @property string $type
 * @property bool $required
 * @property list<string>|null $choices
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Topic $topic
 */
class Field extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'topic_id', 'name_de', 'name_en',
        'description_de', 'description_en',
        'type', 'required', 'choices', 'position',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'required' => 'boolean',
        'choices' => 'array',
        'position' => 'integer',
    ];

    /** @return BelongsTo<Topic, $this> */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    public function name(string $lang): string
    {
        return $lang === 'de' ? ($this->name_de !== '' ? $this->name_de : $this->name_en) : ($this->name_en !== '' ? $this->name_en : $this->name_de);
    }

    public function description(string $lang): string
    {
        return $lang === 'de' ? ($this->description_de ?? '') : ($this->description_en ?? '');
    }
}
