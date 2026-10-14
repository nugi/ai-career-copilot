<?php

namespace App\Models;

use App\Enums\ResumeSection;
use Database\Factories\ResumeChunkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $resume_id
 * @property int $position
 * @property ResumeSection $section
 * @property string $content
 * @property int $token_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Resume $resume
 */
#[Fillable(['position', 'section', 'content', 'token_count'])]
class ResumeChunk extends Model
{
    /** @use HasFactory<ResumeChunkFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'section' => ResumeSection::class,
            'token_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Resume, $this>
     */
    public function resume(): BelongsTo
    {
        return $this->belongsTo(Resume::class);
    }
}
