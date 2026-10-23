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
    */

    'himalayas' => [
        'base_url' => 'https://himalayas.app',
        'max_pages' => (int) env('HIMALAYAS_MAX_PAGES', 5),
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
