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
    | Himalayas
    |--------------------------------------------------------------------------
    |
    | The browse feed holds about 123,000 jobs at 20 a page, so a full read is
    | about 6,000 requests. max_pages caps one run, so a run reads only the
    | newest pages of the feed.
    |
    | expected_fields holds the smallest share of the postings in one page that
    | must have each field. A lower share means that the source changed a field,
    | and jobs:check-source reports it.
    |
    */

    'himalayas' => [
        'base_url' => 'https://himalayas.app',
        'max_pages' => (int) env('HIMALAYAS_MAX_PAGES', 5),
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
