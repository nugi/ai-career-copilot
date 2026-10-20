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
use Spatie\PdfToText\Exceptions\CouldNotExtractText;
use Spatie\PdfToText\Pdf;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

class StoreResume
{
    /**
     * The fewest non-whitespace characters a resume must have to be read as text.
     */
    private const MINIMUM_TEXT_LENGTH = 50;

    public function __construct(private readonly PiiRedactor $redactor) {}

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
        $binary = $this->pdftotextBinary();
        $text = $this->cleanText($this->extractText($file, $binary));

        if (mb_strlen(preg_replace('/\s+/u', '', $text)) < self::MINIMUM_TEXT_LENGTH) {
            throw ValidationException::withMessages(['file' => __('We can\'t read this file. It looks like a scanned image. Upload a PDF with selectable text, for example one exported from Word or Google Docs.')]);
        }

        $content = $this->redactor->redact($text);

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
     * Find the pdftotext binary from the configuration, or on the PATH when none is configured.
     *
     * A configured path that does not exist is a configuration error. It is checked here, because
     * spatie reports a missing binary as a failed extraction, which would show the user a file error.
     *
     * @throws RuntimeException when the binary cannot be found
     */
    private function pdftotextBinary(): string
    {
        $configured = config('services.pdftotext.path');

        if (is_string($configured) && $configured !== '') {
            if (! is_file($configured)) {
                throw new RuntimeException('The pdftotext binary set in PDFTOTEXT_PATH does not exist.');
            }

            return $configured;
        }

        $found = (new ExecutableFinder)->find('pdftotext');

        if ($found === null) {
            throw new RuntimeException('The pdftotext binary was not found. Set PDFTOTEXT_PATH.');
        }

        return $found;
    }

    /**
     * Read the text layer of the PDF in content-stream order (the raw option). The default
     * reading-order mode splits hanging bullets and right-aligned dates from their lines, and
     * the layout mode mixes the lines of two-column resumes.
     *
     * Only a failed extraction becomes a validation error. The exception message is not passed on,
     * because it can contain the file path or the text. Other errors, such as a missing binary,
     * are configuration errors and reach the exception handler.
     */
    private function extractText(UploadedFile $file, string $binary): string
    {
        try {
            return (new Pdf($binary))
                ->setOptions(['enc UTF-8', 'raw'])
                ->setPdf($file->getRealPath())
                ->text();
        } catch (CouldNotExtractText) {
            throw ValidationException::withMessages(['file' => __('We can\'t read this file. Upload a PDF that is not password-protected.')]);
        }
    }

    /**
     * Normalize line breaks to \n, turn form feeds into line breaks, drop NUL bytes, and make the text valid UTF-8.
     *
     * Xpdf on Windows writes \r\n line breaks, so \r\n and a lone \r both become \n first.
     */
    private function cleanText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = str_replace("\f", "\n", $text);
        $text = str_replace("\0", '', $text);

        return trim(mb_scrub($text, 'UTF-8'));
    }

    /**
     * The client file name without its extension, cut to the column length.
     */
    private function label(UploadedFile $file): string
    {
        return mb_substr(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), 0, 255);
    }
}
