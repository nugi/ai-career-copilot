<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Task Tiers
    |--------------------------------------------------------------------------
    |
    | Each AI task (an App\Enums\AiFeature case) runs on one tier. A tier is a
    | named set of models, one for each provider. An agent reads the tier of
    | its task here instead of pinning a model of its own, so a task moves to
    | a cheaper or a stronger model in one place. The strong tier is kept for
    | evaluation runs. No task uses it by default.
    |
    */

    'tasks' => [
        'resume_parsing' => 'cheap',
        'skill_extraction' => 'cheap',
        'chat' => 'cheap',
    ],

    /*
    |--------------------------------------------------------------------------
    | Model Tiers
    |--------------------------------------------------------------------------
    |
    | Each tier lists its providers in failover order, mapped to their model.
    | The first provider answers, and the next one is tried only when the
    | previous one fails.
    |
    */

    'tiers' => [
        'cheap' => ['openai' => 'gpt-6-luna', 'anthropic' => 'claude-haiku-5-5'],
        'strong' => ['openai' => 'gpt-6.1-sol', 'anthropic' => 'claude-sonnet-5-5'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Model Prices and Options
    |--------------------------------------------------------------------------
    |
    | The prices are USD per million tokens, at the standard rates for prompts
    | under 100K tokens, checked on 2026-10-21 (embedding models 2026-10-29). Cached-input prices are not
    | used: a call is billed here as if every input token were uncached.
    |
    | The options are the provider-specific generation options that the model
    | accepts. Some models reject an option that another model of the same
    | provider takes, such as reasoning effort "none" on gpt-6.1-sol.
    |
    */

    'models' => [
        'text-embedding-3-small' => [
            'input_per_million' => 0.02,
            'output_per_million' => 0,
            'options' => [],
        ],
        'text-embedding-3-large' => [
            'input_per_million' => 0.13,
            'output_per_million' => 0,
            'options' => [],
        ],
        'gpt-6-luna' => [
            'input_per_million' => 0.10,
            'output_per_million' => 0.50,
            'options' => ['reasoning' => ['effort' => 'none']],
        ],
        'claude-haiku-5-5' => [
            'input_per_million' => 0.10,
            'output_per_million' => 0.50,
            'options' => ['thinking' => ['type' => 'disabled']],
        ],
        'gpt-6.1-sol' => [
            'input_per_million' => 2.00,
            'output_per_million' => 10.00,
            'options' => ['reasoning' => ['effort' => 'low']],
        ],
        'claude-sonnet-5-5' => [
            'input_per_million' => 2.00,
            'output_per_million' => 10.00,
            'options' => ['thinking' => ['type' => 'between_tools']],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Embeddings
    |--------------------------------------------------------------------------
    |
    | Live posting vectors use this provider, model, size and request batch size.
    |
    */

    'embeddings' => [
        'provider' => 'openai',
        'model' => 'text-embedding-3-small',
        'dimensions' => 1536,
        'batch_size' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Daily Budget
    |--------------------------------------------------------------------------
    |
    | daily_budget_usd is the most that one user may spend on advisor calls
    | each UTC day, in USD. It is a fairness limit.
    |
    | global_daily_budget_usd is the most that all users together may spend
    | each UTC day, in USD. When the spend reaches it, the advisor stops for
    | every user until midnight UTC.
    |
    | Resume parsing never stops, but its cost counts in both sums.
    |
    | batch_daily_budget_usd is the most that batch features (skill
    | extraction, embeddings) may spend each UTC day, in USD. Batch work
    | stops at this budget or at the global budget, so it cannot use up
    | the share that the advisor needs.
    |
    | Null turns a check off.
    |
    */

    'daily_budget_usd' => env('AI_DAILY_BUDGET_USD') === null ? null : (float) env('AI_DAILY_BUDGET_USD'),

    'global_daily_budget_usd' => env('AI_GLOBAL_DAILY_BUDGET_USD') === null ? null : (float) env('AI_GLOBAL_DAILY_BUDGET_USD'),

    'batch_daily_budget_usd' => env('AI_BATCH_DAILY_BUDGET_USD') === null ? null : (float) env('AI_BATCH_DAILY_BUDGET_USD'),

];
