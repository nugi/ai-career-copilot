<?php

namespace App\Http\Controllers;

use App\Actions\Resume\StoreResume;
use App\Http\Requests\StoreResumeRequest;
use App\Jobs\ParseUploadedResume;
use App\Models\Resume;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ResumeController extends Controller
{
    /**
     * Show the resume upload form and the user's resumes, newest first.
     */
    public function index(Request $request): Response
    {
        $resumes = $request->user()->resumes()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Resume $resume) => [
                'id' => $resume->id,
                'label' => $resume->label,
                'file_name' => $resume->file_name,
                'file_size' => $resume->file_size,
                'is_primary' => $resume->is_primary,
                'created_at' => $resume->created_at->toIso8601String(),
                'content' => $resume->content,
            ])
            ->values();

        return Inertia::render('resumes', [
            'resumes' => $resumes,
        ]);
    }

    /**
     * Store an uploaded resume after extracting and redacting its text.
     */
    public function store(StoreResumeRequest $request, StoreResume $storeResume): RedirectResponse
    {
        $resume = $storeResume->handle($request->user(), $request->file('file'));

        ParseUploadedResume::dispatch($resume);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Resume uploaded.')]);

        return to_route('resumes.index');
    }
}
