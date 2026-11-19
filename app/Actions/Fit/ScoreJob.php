<?php

namespace App\Actions\Fit;

use App\Models\JobMatch;
use App\Models\JobPosting;
use App\Models\User;

/**
 * Score one posting for one user in PHP and store the score with the inputs and the rules that made it (ADR 0036).
 */
final class ScoreJob
{
    public function __construct(
        private FitScorer $scorer,
        private ExplainFit $explainFit,
        private BuildFitInput $buildFitInput,
    ) {}

    /**
     * Gather the inputs, score them, and upsert the user's match for the posting.
     *
     * The reasons come from ExplainFit and never change the score. When they are unavailable, the score is stored with no reasons.
     */
    public function handle(User $user, JobPosting $posting, bool $withReasons = true): JobMatch
    {
        $user->load(['profile', 'skills', 'primaryResume']);
        $posting->load('skills');
        $input = $this->buildFitInput->handle($user, $posting);
        $profile = $user->profile;
        $resume = $user->primaryResume;
        $score = $this->scorer->fromConfig($input);
        $reasons = [];

        if ($withReasons) {
            $explanation = $this->explainFit->handle($user, $posting);

            if ($explanation->status === 'ready') {
                $reasons = array_column(array_slice($explanation->reasons, 0, 5), 'claim');
            }
        }

        return $user->jobMatches()->updateOrCreate(['job_posting_id' => $posting->id], [
            'score' => $score->score,
            'breakdown' => $score->breakdown,
            'inputs' => $input->toArray(),
            'matched_skills' => $score->matchedSkills,
            'missing_skills' => $score->missingSkills,
            'scoring_version' => $score->version,
            'profile_version' => $profile?->version,
            'resume_id' => $resume?->id,
            'reasons' => $reasons,
            'scored_at' => now(),
        ]);
    }
}
