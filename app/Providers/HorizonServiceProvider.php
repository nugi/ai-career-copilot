<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();
    }

    /**
     * Register the Horizon gate.
     *
     * The dashboard is limited to the listed admins who have verified their email address.
     * The comparison is case insensitive, because an admin list may be configured directly.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function (?User $user = null): bool {
            if ($user === null || ! $user->hasVerifiedEmail()) {
                return false;
            }

            return in_array(strtolower($user->email), array_map(strtolower(...), config('horizon.admins')), true);
        });
    }
}
