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
}
