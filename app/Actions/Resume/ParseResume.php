<?php

namespace App\Actions\Resume;

use App\Ai\Agents\ResumeParser;
use App\Enums\SkillSource;
use App\Models\Resume;
use App\Models\Skill;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidatorContract;
use Laravel\Ai\Responses\StructuredAgentResponse;

/**
 * Turn the text of a resume into validated structured data, check it against the text, and save it.
 *
 * The agent can answer with invalid data, so the action makes at most 2 attempts. A failed check does not
 * cause a retry: it is recorded in the saved data. A provider failure is not caught here, because it is not
 * invalid output. The caller handles it. A resume without text is recorded as a failure without a call to
 * the provider, because no retry can fix it.
 * An empty output, which is what the SDK returns for malformed JSON, is recorded as MalformedOutput, not as missing fields.
 */
class ParseResume
{
    private const DATE_REGEX = '/'.ResumeParser::DATE_PATTERN.'/';

    public function __construct(
        private CountExperienceYears $countExperienceYears,
        private CheckResumeGrounding $checkResumeGrounding,
    ) {}

    /**
     * Parse the resume and save the result, or record the failure when the resume has no text or both attempts are invalid.
     */
    public function handle(Resume $resume): Resume
    {
        $content = (string) $resume->content;
        $input = Validator::make(['content' => $content], ['content' => ['required']]);

        if ($input->fails()) {
            return $this->recordFailure($resume, $this->failureErrors($input));
        }

        $parser = new ResumeParser;
        $output = $this->ask($parser, $parser->message($content));
        $validator = $this->validate($output);

        if ($validator->fails()) {
            $output = $this->ask($parser, $parser->message($content, $this->errorsWithPaths($validator)));
            $validator = $this->validate($output);
        }

        if ($validator->fails()) {
            $errors = $output === []
                ? [['path' => '*', 'rule' => 'MalformedOutput']]
                : $this->failureErrors($validator);

            return $this->recordFailure($resume, $errors);
        }

        return $this->recordSuccess($resume, $content, $validator->validated());
    }

    /**
     * Send one message to the parser agent and return its structured output.
     *
     * @return array<mixed>
     */
    private function ask(ResumeParser $parser, string $message): array
    {
        /** @var StructuredAgentResponse $response */
        $response = $parser->prompt($message);

        return $response->toArray();
    }

    /**
     * Validate one output of the parser agent. The after() checks run the date rules that the rules cannot express.
     *
     * @param  array<mixed>  $output
     */
    private function validate(array $output): ValidatorContract
    {
        $validator = Validator::make($output, [
            'summary' => ['present', 'nullable', 'string', 'max:5000'],
            'skills' => ['present', 'array', 'max:100'],
            'skills.*' => ['required', 'string', 'max:100'],
            'years_of_experience' => ['present', 'nullable', 'numeric', 'min:0', 'max:60'],
            'experiences' => ['present', 'array', 'max:50'],
            'experiences.*.title' => ['required', 'string', 'max:255'],
            'experiences.*.company' => ['required', 'string', 'max:255'],
            'experiences.*.start' => ['required', 'string', 'regex:'.self::DATE_REGEX],
            'experiences.*.end' => ['present', 'nullable', 'string', 'regex:'.self::DATE_REGEX],
            'experiences.*.bullets' => ['present', 'array'],
            'experiences.*.bullets.*' => ['string', 'max:2000'],
            'education' => ['present', 'array', 'max:20'],
            'education.*.institution' => ['required', 'string', 'max:255'],
            'education.*.degree' => ['present', 'nullable', 'string', 'max:255'],
            'education.*.field' => ['present', 'nullable', 'string', 'max:255'],
            'education.*.start' => ['present', 'nullable', 'string', 'regex:'.self::DATE_REGEX],
            'education.*.end' => ['present', 'nullable', 'string', 'regex:'.self::DATE_REGEX],
        ]);

        $validator->after(function (ValidatorContract $validator) use ($output): void {
            $this->checkDates($validator, $output);
        });

        return $validator;
    }

    /**
     * Check the dates of every experience and education entry whose dates have the right format.
     *
     * @param  array<mixed>  $output
     */
    private function checkDates(ValidatorContract $validator, array $output): void
    {
        foreach (['experiences', 'education'] as $section) {
            $entries = $output[$section] ?? [];

            if (! is_array($entries)) {
                continue;
            }

            foreach ($entries as $index => $entry) {
                if (is_array($entry)) {
                    $this->checkEntryDates($validator, "{$section}.{$index}", $entry['start'] ?? null, $entry['end'] ?? null);
                }
            }
        }
    }

    /**
     * Check that the start and the end are not in the future, and that the end is not before the start.
     */
    private function checkEntryDates(ValidatorContract $validator, string $path, mixed $start, mixed $end): void
    {
        $currentMonth = CarbonImmutable::now()->startOfMonth();
        $startMonth = is_string($start) && $this->isDate($start) ? $this->monthOf($start, false) : null;
        $endMonth = is_string($end) && $this->isDate($end) ? $this->monthOf($end, true) : null;

        if ($startMonth?->greaterThan($currentMonth) === true) {
            $validator->errors()->add("{$path}.start", 'The start date is in the future.');
        }

        if ($endMonth?->greaterThan($currentMonth) === true) {
            $validator->errors()->add("{$path}.end", 'The end date is in the future.');
        } elseif ($startMonth !== null && $endMonth?->lessThan($startMonth) === true) {
            $validator->errors()->add("{$path}.end", 'The end date is before the start date.');
        }
    }

    /**
     * Determine whether a value has the format of a year or of a year and a month.
     */
    private function isDate(string $value): bool
    {
        return preg_match(self::DATE_REGEX, $value) === 1;
    }

    /**
     * Read a date as a month for the validation. A year alone is January when it is a start, and December when it is an end.
     *
     * This is a loose reading on purpose. CountExperienceYears counts a year alone as July, so the two readings differ.
     */
    private function monthOf(string $date, bool $isEnd): CarbonImmutable
    {
        $parts = explode('-', $date);
        $month = isset($parts[1]) ? (int) $parts[1] : ($isEnd ? 12 : 1);

        return CarbonImmutable::createFromDate((int) $parts[0], $month, 1)->startOfMonth();
    }

    /**
     * List the validation errors for the next attempt. An error without its field path gets the path in front.
     *
     * The after() messages do not name their field, so the model could not tell which entry to fix.
     *
     * @return list<string>
     */
    private function errorsWithPaths(ValidatorContract $validator): array
    {
        $lines = [];

        foreach ($validator->errors()->messages() as $path => $messages) {
            foreach ($messages as $message) {
                $lines[] = str_contains($message, $path) ? $message : "{$path}: {$message}";
            }
        }

        return $lines;
    }

    /**
     * List the paths and rule names of the validation errors, keeping their original order.
     *
     * @return list<array{path: string, rule: string}>
     */
    private function failureErrors(ValidatorContract $validator): array
    {
        $errors = [];

        foreach ($validator->failed() as $path => $rules) {
            foreach (array_keys($rules) as $rule) {
                $errors[] = ['path' => (string) $path, 'rule' => (string) $rule];
            }
        }

        foreach (array_diff($validator->errors()->keys(), array_keys($validator->failed())) as $path) {
            $errors[] = ['path' => $path, 'rule' => 'After'];
        }

        return $errors;
    }

    /**
     * Save the failure: the paths and the rule names of the errors of the last attempt. The parsed data stays as it was.
     *
     * @param  list<array{path: string, rule: string}>  $errors
     */
    private function recordFailure(Resume $resume, array $errors): Resume
    {
        $resume->update([
            'parse_failed_at' => now(),
            'parse_errors' => $errors,
        ]);

        Log::warning('ai.resume.parse_failed', [
            'resume_id' => $resume->id,
            'prompt_version' => ResumeParser::PROMPT_VERSION,
            'error_paths' => array_values(array_unique(array_column($errors, 'path'))),
        ]);

        return $resume;
    }

    /**
     * Run the checks on a valid output and save it in one transaction.
     *
     * @param  array<mixed>  $output  The validated output, so keys outside the schema are not saved.
     */
    private function recordSuccess(Resume $resume, string $content, array $output): Resume
    {
        $computedYears = $this->countExperienceYears->handle($output['experiences']);
        $modelYears = $output['years_of_experience'];
        $yearsMismatch = is_numeric($modelYears) && $computedYears !== null && abs((float) $modelYears - $computedYears) > 1.0;
        [$grounded, $ungrounded] = $this->checkResumeGrounding->skills($content, $output['skills']);
        $ungroundedBullets = $this->checkResumeGrounding->bullets($content, $output['experiences']);

        $parsedData = [
            'summary' => $output['summary'],
            'skills' => $grounded,
            'experiences' => $output['experiences'],
            'education' => $output['education'],
            'checks' => [
                'model_years_of_experience' => $modelYears,
                'years_mismatch' => $yearsMismatch,
                'ungrounded_skills' => $ungrounded,
                'ungrounded_bullets' => $ungroundedBullets,
            ],
        ];

        $saved = $this->saveParse($resume, $parsedData, $grounded, ResumeParser::PROMPT_VERSION);

        if ($saved === null) {
            return $resume;
        }

        if ($yearsMismatch || $ungrounded !== [] || $ungroundedBullets !== []) {
            Log::warning('ai.resume.parse_flagged', [
                'resume_id' => $resume->id,
                'prompt_version' => ResumeParser::PROMPT_VERSION,
                'years_mismatch' => $yearsMismatch,
                'ungrounded_skill_count' => count($ungrounded),
                'ungrounded_bullet_count' => count($ungroundedBullets),
            ]);
        }

        return $saved;
    }

    /**
     * Save the parsed data of a duplicate resume on a resume that holds the same text. There is no provider call.
     *
     * A primary target also gets the resume skills of the copied parse, and no parse_flagged log is written.
     */
    public function copyParse(Resume $source, Resume $target): Resume
    {
        $parsedData = $source->parsed_data;

        if ($parsedData === null || $source->prompt_version === null) {
            return $target;
        }

        $skillNames = is_array($parsedData['skills'] ?? null)
            ? array_values(array_filter($parsedData['skills'], is_string(...)))
            : [];

        return $this->saveParse($target, $parsedData, $skillNames, $source->prompt_version) ?? $target;
    }

    /**
     * Save the failure of a duplicate resume on a resume that holds the same text. There is no provider call.
     */
    public function copyFailure(Resume $source, Resume $target): Resume
    {
        $target->update([
            'parse_failed_at' => now(),
            'parse_errors' => $source->parse_errors,
        ]);

        return $target;
    }

    /**
     * Save the parsed data on the resume in one transaction, and sync the resume skills of a primary resume.
     *
     * The skills are matched to the skill catalogue again, so a copied parse keeps the canonical skill names.
     *
     * @param  array<mixed>  $parsedData
     * @param  list<string>  $skillNames  The skills to match to the catalogue and to save on the resume.
     * @return Resume|null Null when the resume was deleted while the parse ran.
     */
    private function saveParse(Resume $resume, array $parsedData, array $skillNames, string $promptVersion): ?Resume
    {
        return DB::transaction(function () use ($resume, $parsedData, $skillNames, $promptVersion): ?Resume {
            $user = User::query()->whereKey($resume->user_id)->lockForUpdate()->sole();
            $locked = Resume::query()->whereKey($resume->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                return null;
            }

            $skills = collect($skillNames)
                ->map(fn (string $name): Skill => Skill::findOrCreateByName($name))
                ->unique('id')
                ->values();

            $locked->update([
                'parsed_data' => [
                    ...$parsedData,
                    'skills' => $skills->pluck('name')->sort()->values()->all(),
                ],
                'prompt_version' => $promptVersion,
                'parsed_at' => now(),
                'parse_failed_at' => null,
                'parse_errors' => null,
            ]);

            if ($locked->is_primary) {
                $this->syncResumeSkills($user, $skills->map(fn (Skill $skill): int => $skill->id)->values()->all());
            }

            return $locked;
        });
    }

    /**
     * Make the user's resume skills match the grounded skills of the primary resume.
     *
     * A profile skill is never changed. The profile version increases only when a resume skill is added or removed.
     *
     * @param  array<int, int>  $skillIds
     */
    private function syncResumeSkills(User $user, array $skillIds): void
    {
        $resumeSkillIds = $user->skills()->wherePivot('source', SkillSource::Resume)->pluck('skills.id')->all();
        $profileSkillIds = $user->skills()->wherePivot('source', SkillSource::Profile)->pluck('skills.id')->all();

        $newResumeSkillIds = array_values(array_diff($skillIds, $profileSkillIds));
        $toDetach = array_values(array_diff($resumeSkillIds, $newResumeSkillIds));
        $toAttach = array_values(array_diff($newResumeSkillIds, $resumeSkillIds));

        if ($toDetach !== []) {
            $user->skills()->detach($toDetach);
        }

        if ($toAttach !== []) {
            $user->skills()->attach(array_fill_keys($toAttach, ['source' => SkillSource::Resume, 'proficiency' => null]));
        }

        if ($toDetach === [] && $toAttach === []) {
            return;
        }

        $profile = $user->profile()->first();

        if ($profile !== null) {
            $profile->version++;
            $profile->save();
        }
    }
}
