<?php

namespace App\Actions\Resume;

use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\PdfToText\Exceptions\CouldNotExtractText;
use Spatie\PdfToText\Pdf;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;

class ExtractResumeText
{
    /**
     * Extract the cleaned text of the PDF at the path.
     *
     * @throws ValidationException when the PDF cannot be read, is password-protected, or takes longer than the configured timeout
     * @throws RuntimeException when the pdftotext binary cannot be found
     */
    public function handle(string $path): string
    {
        $binary = $this->pdftotextBinary();

        return $this->cleanText($this->extractText($path, $binary));
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
     * Only a failed extraction or a timeout becomes a validation error. The exception message is not
     * passed on, because it can contain the file path or the text. The timeout comes from
     * services.pdftotext.timeout, so a hostile PDF cannot hold a web worker for long. Other errors,
     * such as a missing binary, are configuration errors and reach the exception handler.
     *
     * @throws ValidationException when the extraction fails or times out
     */
    private function extractText(string $path, string $binary): string
    {
        try {
            return (new Pdf($binary))
                ->setOptions(['enc UTF-8', 'raw'])
                ->setTimeout(config('services.pdftotext.timeout'))
                ->setPdf($path)
                ->text();
        } catch (CouldNotExtractText) {
            throw ValidationException::withMessages(['file' => __('We can\'t read this file. Upload a PDF that is not password-protected.')]);
        } catch (ProcessTimedOutException) {
            throw ValidationException::withMessages(['file' => __('This file took too long to read. Upload a simpler PDF, for example one exported from Word or Google Docs.')]);
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
}
