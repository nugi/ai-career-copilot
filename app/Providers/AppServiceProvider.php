<?php

namespace App\Providers;

use App\Ai\AiUsageRecorder;
use App\Jobs\IngestJobSource;
use App\Jobs\ParseUploadedResume;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(AiUsageRecorder::class);
        $this->registerJobSources();
    }

    /**
     * Tag every configured job source class, so the registry receives them.
     */
    protected function registerJobSources(): void
    {
        $this->app->tag(config()->array('job-sources.sources'), 'job-sources');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureQueues();
        $this->configureRateLimiting();
    }

    /**
     * Route long running AI jobs to the batch queue connection.
     */
    protected function configureQueues(): void
    {
        Queue::route(ParseUploadedResume::class, queue: 'ai-batch', connection: config('queue.ai.batch_connection'));
        Queue::route(IngestJobSource::class, queue: 'ai-batch', connection: config('queue.ai.batch_connection'));
    }

    /**
     * Configure the rate limiters used by the application.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('advisor', fn (Request $request) => Limit::perMinute(10)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
