<?php

namespace App\Ai\Tools;

use App\Actions\Fit\ScoreJob;
use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Score one live posting for the signed-in user, at most 3 times in one answer.
 *
 * The SDK resolves the agent's tools once per prompt, so the call count is per answer.
 */
class ScoreFit implements Tool
{
    private int $calls = 0;

    public function __construct(
        public User $user,
        private ScoreJob $scoreJob,
    ) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Score how well one job fits the signed-in user, 0-100, computed in code from skills, seniority, salary and location, with cited reasons. Use it when the user asks why a job fits, or which of some jobs fits best. Pass an id from SearchJobs. Call it at most 3 times in one answer.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        $this->calls++;

        if ($this->calls > 3) {
            return json_encode(['error' => 'The limit is 3 fit scores in one answer.'], JSON_THROW_ON_ERROR);
        }

        /** @var array{job_id: int} $input */
        $input = $request->validate(['job_id' => ['required', 'integer', 'min:1']]);
        $posting = JobPosting::query()->live()->find($input['job_id']);

        if ($posting === null) {
            return json_encode(['error' => 'No open job has this id.'], JSON_THROW_ON_ERROR);
        }

        $match = $this->scoreJob->handle($this->user, $posting);
        $breakdown = [];

        foreach ($match->breakdown as $name => $factor) {
            $breakdown[$name] = $factor['value'] === null ? null : (int) round(100 * $factor['value']);
        }

        return json_encode([
            'job_id' => $posting->id,
            'score' => $match->score,
            'breakdown' => (object) $breakdown,
            'matched_skills' => $match->matched_skills,
            'missing_skills' => $match->missing_skills,
            'reasons' => $match->reasons,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['job_id' => $schema->integer()->min(1)->required()];
    }
}
