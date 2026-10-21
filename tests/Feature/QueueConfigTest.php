<?php

use App\Jobs\ParseUploadedResume;
use App\Models\Resume;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

test('every production supervisor times out before its connection retries the job', function () {
    $supervisors = collect(config('horizon.defaults'))
        ->map(fn (array $supervisor, string $name): array => array_merge(
            $supervisor,
            config("horizon.environments.production.{$name}", []),
        ));

    expect($supervisors)->toHaveCount(3);

    foreach ($supervisors as $name => $supervisor) {
        $retryAfter = config("queue.connections.{$supervisor['connection']}.retry_after");

        expect($supervisor['timeout'])->toBeLessThanOrEqual($retryAfter - 5, "The [{$name}] supervisor timeout is too close to or longer than retry_after.");
    }
});

test('the parse job runs on the batch connection and queue within the batch supervisor timeout', function () {
    $job = new ParseUploadedResume(new Resume);
    $supervisor = config('horizon.defaults.supervisor-ai-batch');

    expect($job->timeout)->toBeLessThan($supervisor['timeout'])
        ->and($supervisor['connection'])->toBe('redis-batch')
        ->and($supervisor['queue'])->toBe(['ai-batch']);
});

test('an upload pushes the parse job onto the ai-batch queue', function () {
    Queue::fake([ParseUploadedResume::class]);
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)
        ->post(route('resumes.store'), ['file' => textResumeUpload()])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('resumes.index'));

    Queue::assertPushedOn('ai-batch', ParseUploadedResume::class);
});
