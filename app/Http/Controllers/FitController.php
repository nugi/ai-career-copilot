<?php

namespace App\Http\Controllers;

use App\Actions\Fit\ExplainFit;
use App\Models\JobPosting;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FitController extends Controller
{
    /**
     * Show one live posting at once and its fit explanation as a deferred prop, so the page draws before the model answers.
     */
    public function show(Request $request, ExplainFit $explainFit, int $posting): Response
    {
        $model = JobPosting::query()->live()->with('skills')->findOrFail($posting);
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('fit', [
            'posting' => [
                'id' => $model->id,
                'title' => $model->title,
                'company' => $model->company_name,
                'apply_url' => $model->apply_url,
                'skills' => $model->skills->map(fn (Skill $skill): array => [
                    'name' => $skill->name,
                    'required' => (bool) $skill->pivot->is_required,
                ])->values()->all(),
            ],
            'explanation' => Inertia::defer(fn (): array => $explainFit->handle($user, $model)->toArray()),
        ]);
    }
}
