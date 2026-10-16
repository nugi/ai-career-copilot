<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureProfileIsComplete
{
    /**
     * Send users who have not finished onboarding to the onboarding page.
     *
     * A JSON request cannot follow a redirect to a page, so it gets 409 instead.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->profile?->isComplete()) {
            abort_if($request->expectsJson(), 409, __('Finish onboarding first.'));

            return to_route('onboarding.edit');
        }

        return $next($request);
    }
}
