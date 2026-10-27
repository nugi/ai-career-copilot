<?php

namespace App\Enums;

enum AiFeature: string
{
    case FitScoring = 'fit_scoring';
    case SkillGapPlan = 'skill_gap_plan';
    case ResumeSuggestion = 'resume_suggestion';
    case Chat = 'chat';
    case Embedding = 'embedding';
    case ResumeParsing = 'resume_parsing';
    case SkillExtraction = 'skill_extraction';

    /**
     * Determine whether the feature runs as background work that no user waits for, which the batch budget limits.
     */
    public function runsInBatch(): bool
    {
        return match ($this) {
            self::SkillExtraction => true,
            self::Embedding => true,
            self::FitScoring => false,
            self::SkillGapPlan => false,
            self::ResumeSuggestion => false,
            self::Chat => false,
            self::ResumeParsing => false,
        };
    }
}
