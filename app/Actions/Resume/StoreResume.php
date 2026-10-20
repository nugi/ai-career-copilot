<?php

namespace App\Actions\Resume;

use App\Ai\PiiRedactor;
use App\Models\Resume;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class StoreResume
{
    /**
     * The fewest non-whitespace characters a resume must have to be read as text.
     */
    private const MINIMUM_TEXT_LENGTH = 50;

    public function __construct(
        private readonly PiiRedactor $redactor,
        private readonly ExtractResumeText $extractResumeText,
    ) {}

    /**
     * Extract the text of an uploaded PDF, redact personal contact data, and save the resume.
     *
     * Only the redacted text is saved on the resume row. The original PDF stays on the
     * default (private) disk, so it can be read again later without exposing the raw text.
     *
     * @throws ValidationException when the PDF cannot be read or has no selectable text
     */
    public function handle(User $user, UploadedFile $file): Resume
    {
        $text = $this->extractResumeText->handle($file->getRealPath());

        if (mb_strlen(preg_replace('/\s+/u', '', $text)) < self::MINIMUM_TEXT_LENGTH) {
            throw ValidationException::withMessages(['file' => __('We can\'t read this file. It looks like a scanned image. Upload a PDF with selectable text, for example one exported from Word or Google Docs.')]);
        }

        $content = $this->redactor->redact($text, $user->name);

        $path = $file->store("resumes/{$user->id}");

        if ($path === false) {
            throw new RuntimeException('The resume file could not be stored.');
        }

        try {
            return DB::transaction(function () use ($user, $file, $path, $content): Resume {
                User::query()->whereKey($user->getKey())->lockForUpdate()->sole();

                return $user->resumes()->create([
                    'label' => $this->label($file),
                    'file_path' => $path,
                    'file_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'mime_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                    'content' => $content,
                    'is_primary' => ! $user->resumes()->exists(),
                ]);
            });
        } catch (Throwable $exception) {
            Storage::delete($path);

            throw $exception;
        }
    }

    /**
     * The client file name without its extension, cut to the column length.
     */
    private function label(UploadedFile $file): string
    {
        return mb_substr(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), 0, 255);
    }
}
