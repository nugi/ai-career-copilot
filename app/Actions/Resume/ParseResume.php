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
use RuntimeException;

/**
 * Turn the text of a resume into validated structured data, check it against the text, and save it.
 *
 * The agent can answer with invalid data, so the action makes at most 2 attempts. A failed check does not
 * cause a retry: it is recorded in the saved data. A provider failure is not caught here, because it is not
 * invalid output. The caller handles it.
 */
class ParseResume
{
    private const DATE_REGEX = '/'.ResumeParser::DATE_PATTERN.'/';

    /**
     * Parse the resume and save the result, or record the failure when both attempts are invalid.
     *
     * @throws RuntimeException when the resume has no text to parse
     */
    public function handle(Resume $resume): Resume
    {
        $content = $resume->content;

        if ($content === null || $content === '') {
            throw new RuntimeException('The resume has no text to parse.');
        }

        $parser = new ResumeParser;
        $output = $this->ask($parser, $parser->message($content));
        $validator = $this->validate($output);

        if ($validator->fails()) {
            $output = $this->ask($parser, $parser->message($content, $this->errorsWithPaths($validator)));
            $validator = $this->validate($output);
        }

        if ($validator->fails()) {
            return $this->recordFailure($resume, $validator);
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
     * Read a date as a month. A year alone is January when it is a start, and December when it is an end.
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
     * Save the failure: the paths and the rule names of the errors of the last attempt. The parsed data stays as it was.
     */
    private function recordFailure(Resume $resume, ValidatorContract $validator): Resume
    {
        $errors = [];

        foreach ($validator->failed() as $path => $rules) {
            foreach (array_keys($rules) as $rule) {
                $errors[] = ['path' => $path, 'rule' => $rule];
            }
        }

        foreach (array_diff($validator->errors()->keys(), array_keys($validator->failed())) as $path) {
            $errors[] = ['path' => $path, 'rule' => 'After'];
        }

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
        $computedYears = $this->computedYears($output['experiences']);
        $modelYears = $output['years_of_experience'];
        $yearsMismatch = is_numeric($modelYears) && $computedYears !== null && abs((float) $modelYears - $computedYears) > 1.0;
        [$grounded, $ungrounded] = $this->groundSkills($content, $output['skills']);

        $saved = DB::transaction(function () use ($resume, $output, $grounded, $ungrounded, $computedYears, $modelYears, $yearsMismatch): ?Resume {
            $user = User::query()->whereKey($resume->user_id)->lockForUpdate()->sole();
            $locked = Resume::query()->whereKey($resume->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                return null;
            }

            $skills = collect($grounded)
                ->map(fn (string $name): Skill => Skill::findOrCreateByName($name))
                ->unique('id')
                ->values();

            $locked->update([
                'parsed_data' => [
                    'summary' => $output['summary'],
                    'skills' => $skills->pluck('name')->sort()->values()->all(),
                    'experiences' => $output['experiences'],
                    'education' => $output['education'],
                    'years_of_experience' => $computedYears,
                    'checks' => [
                        'model_years_of_experience' => $modelYears,
                        'years_mismatch' => $yearsMismatch,
                        'ungrounded_skills' => $ungrounded,
                    ],
                ],
                'prompt_version' => ResumeParser::PROMPT_VERSION,
                'parsed_at' => now(),
                'parse_failed_at' => null,
                'parse_errors' => null,
            ]);

            if ($locked->is_primary) {
                $this->syncResumeSkills($user, $skills->map(fn (Skill $skill): int => $skill->id)->values()->all());
            }

            return $locked;
        });

        if ($saved === null) {
            return $resume;
        }

        if ($yearsMismatch || $ungrounded !== []) {
            Log::warning('ai.resume.parse_flagged', [
                'resume_id' => $resume->id,
                'prompt_version' => ResumeParser::PROMPT_VERSION,
                'years_mismatch' => $yearsMismatch,
                'ungrounded_skill_count' => count($ungrounded),
            ]);
        }

        return $saved;
    }

    /**
     * Count the months that the experiences cover, so overlapping jobs count once, and express them in years.
     *
     * A null end means the current month. Both the start month and the end month count.
     *
     * @param  array<mixed>  $experiences
     */
    private function computedYears(array $experiences): ?float
    {
        if ($experiences === []) {
            return null;
        }

        $currentMonth = CarbonImmutable::now()->startOfMonth();

        $intervals = array_map(function (array $experience) use ($currentMonth): array {
            $start = $this->monthNumber($this->monthOf($experience['start'], false));
            $end = $experience['end'] === null
                ? $this->monthNumber($currentMonth)
                : $this->monthNumber($this->monthOf($experience['end'], true));

            return [$start, $end];
        }, $experiences);

        usort($intervals, fn (array $first, array $second): int => $first[0] <=> $second[0]);

        $merged = [];

        foreach ($intervals as [$start, $end]) {
            $last = array_key_last($merged);

            if ($last !== null && $start <= $merged[$last][1] + 1) {
                $merged[$last][1] = max($merged[$last][1], $end);
            } else {
                $merged[] = [$start, $end];
            }
        }

        $months = array_sum(array_map(fn (array $interval): int => $interval[1] - $interval[0] + 1, $merged));

        return round($months / 12, 1);
    }

    /**
     * Give each month one number, so that months can be compared and counted.
     */
    private function monthNumber(CarbonImmutable $month): int
    {
        return $month->year * 12 + $month->month;
    }

    /**
     * Split the skill names of the output into those the resume text names and those it does not.
     *
     * A name is grounded when the name or the canonical name or an alias of its skill appears in the text as a whole word.
     *
     * @param  list<string>  $names
     * @return array{0: list<string>, 1: list<string>}
     */
    private function groundSkills(string $content, array $names): array
    {
        $grounded = [];
        $ungrounded = [];

        foreach ($names as $name) {
            $terms = [$name];
            $skill = Skill::findByName($name);

            if ($skill !== null) {
                $terms[] = $skill->name;
                array_push($terms, ...$skill->aliases->pluck('alias')->all());
            }

            if (array_any($terms, fn (string $term): bool => $this->appearsIn($content, $term))) {
                $grounded[] = $name;
            } else {
                $ungrounded[] = $name;
            }
        }

        return [$grounded, array_values(array_unique($ungrounded))];
    }

    /**
     * Determine whether a term appears in the text as a whole word, ignoring case. Letters and digits must not touch it.
     */
    private function appearsIn(string $content, string $term): bool
    {
        return $term !== ''
            && preg_match('/(?<![\p{L}\p{N}])'.preg_quote($term, '/').'(?![\p{L}\p{N}])/iu', $content) === 1;
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
