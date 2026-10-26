<?php

namespace App\Actions\Jobs;

use App\Actions\Resume\CheckResumeGrounding;
use App\Ai\Agents\JobSkillExtractor;
use App\Models\JobPosting;
use App\Models\Skill;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidatorContract;
use Laravel\Ai\Responses\StructuredAgentResponse;

/**
 * Read the skills and the level that the text of a job posting asks for, check them against the text, and save them (ADR 0024).
 *
 * The model can answer with invalid data, so the action makes at most 2 attempts. A failed check does not cause a
 * retry: it is recorded, so the text is not sent again until it or the prompt changes. A provider failure is not
 * caught here, because it is not invalid output. The caller handles it.
 * A posting whose text was already read pays nothing: the saved result is copied from another posting with the same
 * text and prompt version, and after a call the result is copied to every other pending posting with the same text.
 * An empty output, which is what the SDK returns for malformed JSON, is recorded as MalformedOutput, not as missing fields.
 */
class ExtractPostingSkills
{
    public function __construct(private CheckResumeGrounding $checkResumeGrounding) {}

    /**
     * Extract the skills of the posting and save them, or copy them from a posting with the same text, or record the failure.
     */
    public function handle(JobPosting $posting): JobPosting
    {
        if ($this->hasCurrentExtraction($posting)) {
            return $posting;
        }

        $source = $this->findCopySource($posting);
        $saved = $source === null
            ? $this->extract($posting)
            : $this->saveCopy($posting, $source);

        if ($saved->skills_extracted_at !== null) {
            $this->copyToSiblings($saved);
        }

        return $saved;
    }

    /**
     * Determine whether the posting holds an extraction or a failure for its current text and prompt version.
     */
    private function hasCurrentExtraction(JobPosting $posting): bool
    {
        return $posting->text_hash !== null
            && $posting->skills_text_hash === $posting->text_hash
            && $posting->skills_prompt_version === JobSkillExtractor::PROMPT_VERSION;
    }

    /**
     * Find a posting in the same database that already holds the extraction of this text, so the answer is copied instead of asked.
     */
    private function findCopySource(JobPosting $posting): ?JobPosting
    {
        if ($posting->text_hash === null) {
            return null;
        }

        return JobPosting::query()
            ->whereKeyNot($posting->getKey())
            ->whereNotNull('extracted_skills')
            ->where('skills_text_hash', $posting->text_hash)
            ->where('skills_prompt_version', JobSkillExtractor::PROMPT_VERSION)
            ->orderBy('id')
            ->first();
    }

    /**
     * Ask the agent, retry once with the validation errors, and save the output or record the failure.
     *
     * A provider exception is not caught here: it is a failed job, not an invalid answer.
     */
    private function extract(JobPosting $posting): JobPosting
    {
        $extractor = new JobSkillExtractor;
        $message = $extractor->message($posting->title, $posting->description);
        $output = $this->ask($extractor, $message);
        $validator = $this->validate($output);

        if ($validator->fails()) {
            $errors = $this->errorsWithPaths($validator);
            $output = $this->ask($extractor, $extractor->message($posting->title, $posting->description, $errors));
            $validator = $this->validate($output);
        }

        if ($validator->fails()) {
            $errors = $output === []
                ? [['path' => '*', 'rule' => 'MalformedOutput']]
                : $this->failureErrors($validator);

            return $this->recordFailure($posting, $errors);
        }

        return $this->recordSuccess($posting, $validator->validated());
    }

    /**
     * Send one message to the extractor agent and return its structured output.
     *
     * @return array<mixed>
     */
    private function ask(JobSkillExtractor $extractor, string $message): array
    {
        /** @var StructuredAgentResponse $response */
        $response = $extractor->prompt($message);

        return $response->toArray();
    }

    /**
     * Validate one output of the extractor agent.
     *
     * @param  array<mixed>  $output
     */
    private function validate(array $output): ValidatorContract
    {
        return Validator::make($output, [
            'required_skills' => ['present', 'array', 'max:60'],
            'required_skills.*' => ['required', 'string', 'max:100'],
            'nice_to_have_skills' => ['present', 'array', 'max:60'],
            'nice_to_have_skills.*' => ['required', 'string', 'max:100'],
            'seniority' => ['present', 'nullable', 'in:junior,mid,senior,lead'],
            'years_required' => ['present', 'nullable', 'integer', 'min:0', 'max:40'],
        ]);
    }

    /**
     * List the validation errors for the next attempt. An error without its field path gets the path in front.
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

        return $errors;
    }

    /**
     * Record the failure for the current text and prompt version, so the text is not sent again. The saved extraction stays as it was.
     *
     * @param  list<array{path: string, rule: string}>  $errors
     */
    private function recordFailure(JobPosting $posting, array $errors): JobPosting
    {
        $posting->update([
            'skills_text_hash' => $posting->text_hash,
            'skills_prompt_version' => JobSkillExtractor::PROMPT_VERSION,
            'skills_failed_at' => now(),
        ]);

        Log::warning('jobs.skills.extract_failed', [
            'job_posting_id' => $posting->id,
            'prompt_version' => JobSkillExtractor::PROMPT_VERSION,
            'error_paths' => array_values(array_unique(array_column($errors, 'path'))),
        ]);

        return $posting;
    }

    /**
     * Run the checks on a valid output and save it in one transaction.
     *
     * A name that the posting text does not contain is dropped and kept as ungrounded. A name whose catalogue skill is
     * missing or unverified is kept as unknown, and no skill is created from a posting. A skill that both lists name is required.
     *
     * @param  array<mixed>  $output  The validated output, so keys outside the schema are not saved.
     */
    private function recordSuccess(JobPosting $posting, array $output): JobPosting
    {
        $text = "{$posting->title}\n{$posting->description}";
        [$requiredNames, $ungroundedRequired] = $this->checkResumeGrounding->skills($text, $output['required_skills']);
        [$niceNames, $ungroundedNice] = $this->checkResumeGrounding->skills($text, $output['nice_to_have_skills']);

        $requiredNormalized = array_map(Skill::normalize(...), $requiredNames);
        $niceNames = array_values(array_filter(
            $niceNames,
            fn (string $name): bool => ! in_array(Skill::normalize($name), $requiredNormalized, true),
        ));

        $unknown = [];
        $pivot = [];

        foreach (['required' => $requiredNames, 'nice' => $niceNames] as $group => $names) {
            $isRequired = $group === 'required';

            foreach ($names as $name) {
                $skill = Skill::findByName($name);

                if ($skill === null || ! $skill->is_verified) {
                    $unknown[] = $name;

                    continue;
                }

                $pivot[$skill->id] = ['is_required' => $isRequired || ($pivot[$skill->id]['is_required'] ?? false)];
            }
        }

        $ungrounded = array_values(array_unique([...$ungroundedRequired, ...$ungroundedNice]));
        sort($ungrounded);
        sort($requiredNames);
        sort($niceNames);
        $unknown = array_values(array_unique($unknown));
        sort($unknown);

        $extractedSkills = [
            'required' => $requiredNames,
            'nice_to_have' => $niceNames,
            'unknown' => $unknown,
            'ungrounded' => $ungrounded,
        ];

        $saved = $this->save(
            $posting,
            $extractedSkills,
            $output['seniority'] === null ? null : (string) $output['seniority'],
            $output['years_required'] === null ? null : (int) $output['years_required'],
            $pivot,
        );

        if ($unknown !== []) {
            Log::info('jobs.skills.unknown', [
                'job_posting_id' => $saved->id,
                'names' => $unknown,
            ]);
        }

        return $saved;
    }

    /**
     * Save the result of another posting on this posting, with the pivot rows of that posting and without a provider call.
     */
    private function saveCopy(JobPosting $posting, JobPosting $source): JobPosting
    {
        $pivot = [];

        $rows = DB::table('job_posting_skill')
            ->where('job_posting_id', $source->getKey())
            ->get(['skill_id', 'is_required']);

        foreach ($rows as $row) {
            $pivot[(int) $row->skill_id] = ['is_required' => (bool) $row->is_required];
        }

        return $this->save(
            $posting,
            $source->extracted_skills ?? [],
            $source->extracted_seniority?->value,
            $source->years_required,
            $pivot,
        );
    }

    /**
     * Save the extraction and its pivot rows on the posting in one transaction.
     *
     * @param  array<string, mixed>  $extractedSkills
     * @param  array<int, array{is_required: bool}>  $pivot
     */
    private function save(JobPosting $posting, array $extractedSkills, ?string $seniority, ?int $yearsRequired, array $pivot): JobPosting
    {
        return DB::transaction(function () use ($posting, $extractedSkills, $seniority, $yearsRequired, $pivot): JobPosting {
            $locked = JobPosting::query()->whereKey($posting->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                return $posting;
            }

            $locked->skills()->sync($pivot);

            $locked->update([
                'extracted_skills' => $extractedSkills,
                'extracted_seniority' => $seniority,
                'years_required' => $yearsRequired,
                'skills_text_hash' => $locked->text_hash,
                'skills_prompt_version' => JobSkillExtractor::PROMPT_VERSION,
                'skills_extracted_at' => now(),
                'skills_failed_at' => null,
            ]);

            return $locked;
        });
    }

    /**
     * Save the result of the posting on every other pending posting with the same text, so one call serves all of them.
     */
    private function copyToSiblings(JobPosting $source): void
    {
        if ($source->text_hash === null) {
            return;
        }

        $pending = JobPosting::query()
            ->needsSkills()
            ->where('text_hash', $source->text_hash)
            ->whereKeyNot($source->getKey())
            ->orderBy('id')
            ->get();

        foreach ($pending as $posting) {
            $this->saveCopy($posting, $source);
        }
    }
}
