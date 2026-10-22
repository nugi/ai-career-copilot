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
    | under 100K tokens, checked on 2026-10-21. Cached-input prices are not
    | used: a call is billed here as if every input token were uncached.
    |
    | The options are the provider-specific generation options that the model
    | accepts. Some models reject an option that another model of the same
    | provider takes, such as reasoning effort "none" on gpt-6.1-sol.
    |
    */

    'models' => [
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
    | Daily Budget
    |--------------------------------------------------------------------------
    |
    | The most a user may spend on advisor calls each UTC day, in USD. Null
    | turns the check off. Resume parsing does not count against the check,
    | but its cost is in the daily spend.
    |
    */

    'daily_budget_usd' => env('AI_DAILY_BUDGET_USD') === null ? null : (float) env('AI_DAILY_BUDGET_USD'),

];
