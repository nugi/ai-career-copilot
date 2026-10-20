<?php

namespace App\Models;

use App\Actions\Resume\CountExperienceYears;
use Carbon\CarbonImmutable;
use Database\Factories\ResumeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property string $label
 * @property string $file_path
 * @property string $file_name
 * @property string $mime_type
 * @property int $file_size
 * @property string|null $content
 * @property bool $is_primary
 * @property CarbonImmutable|null $parsed_at When the structured parse succeeded.
 * @property array<array-key, mixed>|null $parsed_data
 * @property string|null $prompt_version
 * @property CarbonImmutable|null $parse_failed_at
 * @property array<array-key, mixed>|null $parse_errors
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 * @property-read Collection<int, ResumeChunk> $chunks
 */
#[Fillable(['label', 'file_path', 'file_name', 'mime_type', 'file_size', 'content', 'is_primary', 'parsed_at', 'parsed_data', 'prompt_version', 'parse_failed_at', 'parse_errors'])]
class Resume extends Model
{
    /** @use HasFactory<ResumeFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'is_primary' => 'boolean',
            'parsed_at' => 'datetime',
            'parsed_data' => 'array',
            'parse_failed_at' => 'datetime',
            'parse_errors' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<ResumeChunk, $this>
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(ResumeChunk::class)->orderBy('position');
    }

    /**
     * The years of experience that the parsed experiences cover.
     *
     * The value is computed when it is read, not when the resume is parsed, so a current job is always counted
     * up to $asOf. Returns null when the resume has no parsed experiences.
     */
    public function yearsOfExperience(?CarbonImmutable $asOf = null): ?float
    {
        if ($this->parsed_data === null) {
            return null;
        }

        return app(CountExperienceYears::class)->handle($this->parsed_data['experiences'] ?? [], $asOf);
    }
}
