<?php

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

    'sources' => [],

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
