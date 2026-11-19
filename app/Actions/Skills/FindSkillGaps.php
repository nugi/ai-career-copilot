<?php

namespace App\Actions\Skills;

use App\Actions\Fit\BuildFitInput;
use App\Actions\Fit\FitScorer;
use App\Actions\Jobs\EmbedProfile;
use App\Actions\Jobs\MatchOpenings;
use App\Actions\Jobs\ProfileEmbeddingText;
use App\Models\User;
use Throwable;

/**
 * Find the skills that the user's best matches ask for and the user does not have.
 *
 * The matches are scored in memory and nothing is stored, so the list is read-only. There is no reranking and no ExplainFit call.
 */
final class FindSkillGaps
{
    public function __construct(
        private EmbedProfile $embedProfile,
        private ProfileEmbeddingText $profileEmbeddingText,
        private MatchOpenings $matchOpenings,
        private BuildFitInput $buildFitInput,
        private FitScorer $scorer,
        private RankSkillGaps $rankSkillGaps,
    ) {}

    /**
     * Match the profile as the matches page does without reranking, score the top matches, and rank their missing skills.
     *
     * @return list<SkillGap>
     */
    public function handle(User $user, ?int $limit = null): array
    {
        $profile = $user->loadMissing('profile')->profile;

        if ($profile === null) {
            return [];
        }

        $vector = null;

        try {
            $vector = $this->embedProfile->handle($profile);
        } catch (Throwable $exception) {
            report($exception);
        }

        $terms = $this->profileEmbeddingText->keywordTerms($profile);

        if ($vector === null && $terms === []) {
            return [];
        }

        $postings = $this->matchOpenings->handle($profile, $vector, $profile->country_code, config('skill-gaps.top_matches'), $terms);
        $user->loadMissing(['profile', 'skills', 'primaryResume']);
        $postings->load('skills');
        $scores = [];

        foreach ($postings as $posting) {
            $scores[$posting->id] = $this->scorer->fromConfig($this->buildFitInput->handle($user, $posting))->score;
        }

        return $this->rankSkillGaps->handle($user, $scores, $limit);
    }
}
