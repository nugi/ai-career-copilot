<?php

namespace App\Http\Controllers;

use App\Actions\Skills\FindSkillGaps;
use App\Actions\Skills\PlanLearning;
use App\Actions\Skills\SkillGap;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GrowController extends Controller
{
    /**
     * Show the stored plan immediately and load skill gaps after the page draws.
     */
    public function show(Request $request, FindSkillGaps $findSkillGaps): Response
    {
        /** @var User $user */
        $user = $request->user();
        $plan = $user->learningPlan()->first();

        return Inertia::render('grow', [
            'plan' => $plan === null ? null : [
                'plan' => $plan->plan,
                'generated_at' => $plan->generated_at->toIso8601String(),
                'available_at' => $plan->generated_at->addHours(config('skill-gaps.plan_every_hours'))->toIso8601String(),
            ],
            'gaps' => Inertia::defer(fn (): array => array_map(
                fn (SkillGap $gap): array => $gap->toArray(),
                $findSkillGaps->handle($user, 10),
            )),
        ]);
    }

    /**
     * Generate a plan from the current gaps and report the outcome.
     */
    public function plan(Request $request, FindSkillGaps $findSkillGaps, PlanLearning $planLearning): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $result = $planLearning->handle($user, $findSkillGaps->handle($user));
        $message = match ($result->status) {
            'ready' => __('Your learning plan is ready.'),
            'too_soon' => __('You can make a new plan after :time.', ['time' => $result->availableAt->utc()->format('j M H:i').' UTC']),
            'no_gaps' => __('No skill gaps were found in your top matches.'),
            'budget' => __("You have used today's AI budget. It resets at midnight UTC."),
            'busy' => __('A plan is already being made.'),
            'failed' => __('The plan could not be made. Try again.'),
        };

        Inertia::flash('toast', ['type' => $result->status === 'ready' ? 'success' : 'error', 'message' => $message]);

        return to_route('grow.show');
    }
}
