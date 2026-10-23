<?php

use App\JobSources\HimalayasJobSource;

return [

    /*
    |--------------------------------------------------------------------------
    | Job Sources
    |--------------------------------------------------------------------------
    |
    | List every job source class here. A class implements
    | App\JobSources\Contracts\JobSource. The app tags the listed classes with
    | "job-sources", so a new source is one class and one line here.
    |
    */

    'sources' => [
        HimalayasJobSource::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Run Deadline
    |--------------------------------------------------------------------------
    |
    | Stop starting requests after this many seconds. A retry must fit its wait
    | and one more attempt before the deadline.
    |
    */

    'max_run_seconds' => (int) env('JOB_SOURCES_MAX_RUN_SECONDS', 60),

    /*
    |--------------------------------------------------------------------------
    | Search Queries
    |--------------------------------------------------------------------------
    |
    | A run searches each source for the target roles on user profiles, most
    | common first. Role titles are not personal data. The default list is
    | used when no profile has a role.
    |
    */

    'max_queries' => (int) env('JOB_SOURCES_MAX_QUERIES', 10),

    'default_queries' => [
        'software engineer',
        'backend engineer',
        'frontend engineer',
        'full stack developer',
        'mobile developer',
        'devops engineer',
        'data engineer',
        'machine learning engineer',
        'qa engineer',
        'product manager',
    ],

    /*
    |--------------------------------------------------------------------------
    | Daily Check
    |--------------------------------------------------------------------------
    |
    | The daily check reads this many pages, one page for each of the first
    | queries.
    |
    */

    'check_pages' => (int) env('JOB_SOURCES_CHECK_PAGES', 3),

    /*
    |--------------------------------------------------------------------------
    | Himalayas
    |--------------------------------------------------------------------------
    |
    | Search returns 20 jobs a page, using page numbers, with at most 5,000
    | results per query. pages_per_query caps the pages read for each role,
    | newest first, under the shared run deadline.
    |
    | expected_fields holds the smallest share of the sampled postings that
    | must have each field. jobs:check-source warns on a first low share and
    | reports a problem when the same field is low on the next run.
    |
    */

    'himalayas' => [
        'base_url' => 'https://himalayas.app',
        'pages_per_query' => (int) env('HIMALAYAS_PAGES_PER_QUERY', 1),
        'expected_fields' => [
            'salaryMin' => 0.05,
            'seniority' => 0.5,
            'employmentType' => 0.5,
            'location' => 0.3,
            'descriptionHtml' => 0.9,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Agent
    |--------------------------------------------------------------------------
    |
    | Sources see this User-Agent, so it names the app and a contact URL. Change
    | it only to a value that still tells a source who is calling.
    |
    */

    'user_agent' => env('JOB_SOURCES_USER_AGENT', 'AICareerCopilot/1.0 (+https://github.com/nugi/ai-career-copilot)'),

];
