<?php

namespace App\Ai\Tools;

use App\Actions\Jobs\ProfileEmbeddingText;
use App\Enums\WorkArrangement;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Retrieve the signed-in user's job search profile.
 */
class GetUserProfile implements Tool
{
    public function __construct(public User $user, private ProfileEmbeddingText $profileEmbeddingText) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return "Get the signed-in user's job search profile: target roles, top skills, country, work arrangements and salary floor. Use it when the user asks about their own profile, or before you suggest roles or skills. Do not call it before SearchJobs: SearchJobs already reads the profile.";
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        $profile = $this->user->profile()->firstOrFail();
        $floor = $profile->min_salary !== null && $profile->salary_currency !== null && $profile->salary_period !== null
            ? ['amount' => $profile->min_salary, 'currency' => $profile->salary_currency->value, 'period' => $profile->salary_period->value]
            : null;

        return json_encode([
            'target_roles' => $profile->target_roles,
            'skills' => $this->profileEmbeddingText->topSkills($profile),
            'country' => $profile->country_code,
            'work_arrangements' => $profile->work_arrangements->map(fn (WorkArrangement $arrangement): string => $arrangement->value)->values()->all(),
            'salary_floor' => $floor,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
