<?php

namespace App\Models;

use App\Enums\AiFeature;
use App\Enums\AiUsageStatus;
use App\Enums\ModelTier;
use Carbon\CarbonImmutable;
use Database\Factories\AiUsageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string|null $invocation_id
 * @property AiFeature $feature
 * @property string $agent
 * @property ModelTier|null $tier
 * @property string|null $prompt_version
 * @property string $provider
 * @property string $model
 * @property AiUsageStatus $status
 * @property int $input_tokens
 * @property int $output_tokens
 * @property bool $tokens_estimated
 * @property string $cost_usd
 * @property int|null $latency_ms
 * @property CarbonImmutable $created_at
 * @property-read User|null $user
 */
#[Fillable(['user_id', 'invocation_id', 'feature', 'agent', 'tier', 'prompt_version', 'provider', 'model', 'status', 'input_tokens', 'output_tokens', 'tokens_estimated', 'cost_usd', 'latency_ms'])]
class AiUsage extends Model
{
    /** @use HasFactory<AiUsageFactory> */
    use HasFactory;

    /**
     * Usage rows are append-only, so they have no update timestamp.
     */
    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'feature' => AiFeature::class,
            'tier' => ModelTier::class,
            'status' => AiUsageStatus::class,
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'tokens_estimated' => 'boolean',
            'cost_usd' => 'decimal:6',
            'latency_ms' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
