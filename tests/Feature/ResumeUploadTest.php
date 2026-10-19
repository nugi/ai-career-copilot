<?php

use App\Models\Profile;
use App\Models\Resume;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake();
});

/**
 * A text-layer resume fixture with fake personal data, uploaded under a client name.
 */
function textResumeUpload(string $clientName = 'Rina Wijaya CV.pdf'): UploadedFile
{
    return new UploadedFile(base_path('tests/Fixtures/resumes/text-resume.pdf'), $clientName, 'application/pdf', null, true);
}

test('guests are redirected to the login page', function () {
    $this->get(route('resumes.index'))->assertRedirect(route('login'));

    $this->post(route('resumes.store'), ['file' => textResumeUpload()])->assertRedirect(route('login'));
});

test('users who have not finished onboarding are sent to it from the resumes page', function (User $user) {
    $this->actingAs($user)->get(route('resumes.index'))->assertRedirect(route('onboarding.edit'));
})->with([
    'no profile' => fn () => User::factory()->create(),
    'incomplete profile' => fn () => User::factory()->has(Profile::factory()->incomplete())->create(),
]);

test('uploading a text PDF saves only the redacted text and stores the file privately', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)
        ->post(route('resumes.store'), ['file' => textResumeUpload()])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('resumes.index'));

    $resume = $user->resumes()->sole();

    expect($resume->label)->toBe('Rina Wijaya CV')
        ->and($resume->file_name)->toBe('Rina Wijaya CV.pdf')
        ->and($resume->is_primary)->toBeTrue()
        ->and($resume->mime_type)->toBe('application/pdf')
        ->and($resume->parsed_at)->not->toBeNull()
        ->and($resume->content)->toContain('Backend engineer with 6 years')
        ->and($resume->content)->toContain('[EMAIL]', '[PHONE]', '[URL]', '[ADDRESS]', '[NIK]')
        ->and($resume->content)->not->toContain('rina.wijaya@example.com')
        ->and($resume->content)->not->toContain('3456-7890')
        ->and($resume->content)->not->toContain('linkedin.com')
        ->and($resume->content)->not->toContain('Kenanga')
        ->and($resume->content)->not->toContain('3273012345670001');

    expect($resume->file_path)->toStartWith("resumes/{$user->id}/");
    Storage::assertExists($resume->file_path);
});

test('a later upload is not primary and the first resume stays primary', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)->post(route('resumes.store'), ['file' => textResumeUpload('First CV.pdf')]);
    $this->actingAs($user)->post(route('resumes.store'), ['file' => textResumeUpload('Second CV.pdf')]);

    expect($user->resumes()->oldest('id')->get()->pluck('is_primary')->all())->toBe([true, false]);
});

test('a scanned PDF with no text layer is rejected and nothing is stored', function () {
    $user = User::factory()->onboarded()->create();
    $scan = new UploadedFile(base_path('tests/Fixtures/resumes/scanned-resume.pdf'), 'Scanned CV.pdf', 'application/pdf', null, true);

    $this->actingAs($user)
        ->from(route('resumes.index'))
        ->post(route('resumes.store'), ['file' => $scan])
        ->assertRedirect(route('resumes.index'))
        ->assertSessionHasErrors('file');

    expect(session('errors')->first('file'))->toContain('scanned image');
    expect($user->resumes()->count())->toBe(0);
    Storage::assertDirectoryEmpty('resumes');
});

test('a corrupt PDF is rejected with a readable message and nothing is stored', function () {
    $user = User::factory()->onboarded()->create();
    $corrupt = UploadedFile::fake()->createWithContent('cv.pdf', "%PDF-1.4\nnot a real pdf");

    $this->actingAs($user)
        ->from(route('resumes.index'))
        ->post(route('resumes.store'), ['file' => $corrupt])
        ->assertRedirect(route('resumes.index'))
        ->assertSessionHasErrors('file');

    expect(session('errors')->first('file'))->toContain("can't read this file");
    expect($user->resumes()->count())->toBe(0);
    Storage::assertDirectoryEmpty('resumes');
});

test('a file that is not a PDF is rejected by validation', function () {
    $user = User::factory()->onboarded()->create();
    $document = UploadedFile::fake()->create('cv.docx', 100, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

    $this->actingAs($user)
        ->from(route('resumes.index'))
        ->post(route('resumes.store'), ['file' => $document])
        ->assertRedirect(route('resumes.index'))
        ->assertSessionHasErrors('file');

    expect($user->resumes()->count())->toBe(0);
});

test('a PDF over 5 MB is rejected by validation', function () {
    $user = User::factory()->onboarded()->create();
    $oversized = UploadedFile::fake()->create('cv.pdf', 5121, 'application/pdf');

    $this->actingAs($user)
        ->from(route('resumes.index'))
        ->post(route('resumes.store'), ['file' => $oversized])
        ->assertRedirect(route('resumes.index'))
        ->assertSessionHasErrors('file');

    expect($user->resumes()->count())->toBe(0);
});

test('a missing pdftotext binary is a server error, not an unreadable file', function () {
    config(['services.pdftotext.path' => base_path('tests/Fixtures/resumes/missing-pdftotext')]);
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)
        ->from(route('resumes.index'))
        ->post(route('resumes.store'), ['file' => textResumeUpload()])
        ->assertServerError()
        ->assertSessionHasNoErrors();

    expect($user->resumes()->count())->toBe(0);
});

test('the resumes page lists only the current user\'s resumes', function () {
    $user = User::factory()->onboarded()->create();
    $mine = Resume::factory()->for($user)->create();
    Resume::factory()->create();

    $this->actingAs($user)
        ->get(route('resumes.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('resumes')
            ->has('resumes', 1)
            ->where('resumes.0.id', $mine->id)
        );
});
