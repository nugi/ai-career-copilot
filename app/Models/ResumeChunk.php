<?php

namespace App\Models;

use App\Enums\ResumeSection;
use Carbon\CarbonImmutable;
use Database\Factories\ResumeChunkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $resume_id
 * @property int $position
 * @property ResumeSection $section
 * @property string|null $period_start
 * @property string|null $period_end
 * @property string $content
 * @property int $token_count
 * @property string|null $embedding pgvector text form, not cast.
 * @property string|null $embedding_model
 * @property CarbonImmutable|null $embedded_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Resume $resume
 */
#[Fillable(['position', 'section', 'period_start', 'period_end', 'content', 'token_count', 'embedding', 'embedding_model', 'embedded_at'])]
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
            'embedded_at' => 'datetime',
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
