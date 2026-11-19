<?php

namespace App\Ai\Tools;

use App\Actions\Skills\FindSkillGaps;
use App\Actions\Skills\SkillGap;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * List the signed-in user's skill gaps in their top job matches.
 */
class SkillGaps implements Tool
{
    public function __construct(public User $user, private FindSkillGaps $findSkillGaps) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'List the skills that the user\'s top job matches ask for and the user does not have, best first. Required skills count more than nice-to-have skills. Use this when the user asks what they are missing or what to learn. Say "required in N of your top M matches" and do not invent skills.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        /** @var array{limit?: int} $input */
        $input = $request->validate(['limit' => ['sometimes', 'integer', 'min:1', 'max:10']]);
        $gaps = $this->findSkillGaps->handle($this->user, (int) ($input['limit'] ?? 5));
        $result = ['gaps' => array_map(fn (SkillGap $gap): array => [
            'skill' => $gap->skill,
            'required_in' => $gap->requiredJobs,
            'nice_to_have_in' => $gap->niceJobs,
            'of' => $gap->of,
        ], $gaps)];

        if ($gaps === []) {
            $result['note'] = 'No skill gaps were found in the top matches.';
        }

        return json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['limit' => $schema->integer()->min(1)->max(10)];
    }
}
