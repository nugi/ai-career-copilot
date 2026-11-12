<?php

namespace App\Ai\Tools;

use App\Actions\Jobs\EmbedSearchText;
use App\Actions\Jobs\JobSearch;
use App\Actions\Jobs\MatchOpenings;
use App\Actions\Jobs\ProfileEmbeddingText;
use App\Actions\Jobs\SalaryFloor;
use App\Actions\Money\ExchangeRates;
use App\Enums\SalaryPeriod;
use App\Enums\WorkArrangement;
use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

/**
 * Search open jobs for the signed-in user.
 */
class SearchJobs implements Tool
{
    public function __construct(
        public User $user,
        private EmbedSearchText $embedSearchText,
        private ProfileEmbeddingText $profileEmbeddingText,
        private MatchOpenings $matchOpenings,
        private ExchangeRates $exchangeRates,
    ) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Search the open job listings and return the best matches for the signed-in user, best first. Use it when the user asks to find, list or compare jobs. It already applies the roles, skills, work arrangements and salary floor of the profile, so set a field only for what the user asks in this message. Set role only when the user names a role that is different from their profile. Set remote only when the user asks for remote, or for not remote. Pass a salary exactly as the user said it: amount, currency and period. Do not convert currencies. Each result has an id for other tools, and match is 0-100.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        /** @var array{role?: string, remote?: bool|int|string, min_salary?: int, currency?: string, salary_period?: string, limit?: int} $input */
        $input = $request->validate([
            'role' => ['sometimes', 'string', 'max:100'],
            'remote' => ['sometimes', 'boolean'],
            'min_salary' => ['sometimes', 'integer', 'min:1'],
            'currency' => ['sometimes', 'string', 'regex:/^[A-Z]{3}$/'],
            'salary_period' => ['sometimes', Rule::in(['monthly', 'yearly'])],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ]);
        $profile = $this->user->profile()->firstOrFail();
        $role = $input['role'] ?? null;
        $arrangements = isset($input['remote'])
            ? ((bool) $input['remote'] ? [WorkArrangement::Remote] : [WorkArrangement::Hybrid, WorkArrangement::Onsite])
            : array_values($profile->work_arrangements->all());
        $currency = $input['currency'] ?? $profile->salary_currency->value ?? 'USD';

        if (isset($input['min_salary']) && $this->exchangeRates->latestFrom($currency) === []) {
            return json_encode(['jobs' => [], 'note' => "There is no exchange rate for {$currency}. Ask the user for the salary in another currency."], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $floor = isset($input['min_salary'])
            ? new SalaryFloor(
                $input['min_salary'],
                $currency,
                isset($input['salary_period']) ? SalaryPeriod::from($input['salary_period']) : SalaryPeriod::Yearly,
            )
            : null;
        $search = new JobSearch($arrangements, $profile->country_code, null, true, $floor);

        try {
            $vector = $this->embedSearchText->handle($profile, $role);
        } catch (Throwable $exception) {
            report($exception);
            $vector = null;
        }

        $terms = $this->profileEmbeddingText->keywordTerms($profile, $role);

        if ($vector === null && $terms === []) {
            return json_encode(['jobs' => [], 'note' => 'The search is unavailable right now.'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $results = $this->matchOpenings->handle($profile, $vector, $profile->country_code, $input['limit'] ?? 10, $terms, search: $search);
        $jobs = $results->map(fn (JobPosting $posting): array => [
            'id' => $posting->id,
            'title' => $posting->title,
            'company' => $posting->company_name,
            'location' => $posting->work_arrangement === WorkArrangement::Remote ? null : implode(', ', $posting->country_codes),
            'arrangement' => $posting->work_arrangement->value,
            'salary' => $this->salary($posting),
            'match' => max(0, min(100, (int) round(100 * (float) $posting->getAttribute('score') / (2 / (MatchOpenings::RRF_K + 1))))),
        ])->all();
        $result = ['jobs' => $jobs];

        if ($jobs === []) {
            $result['note'] = 'No jobs pass these filters. Suggest relaxing one filter.';
        }

        return json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Format the pay posting's original amounts without converting currencies or periods.
     */
    private function salary(JobPosting $posting): ?string
    {
        $minimum = $posting->getAttribute('pay_salary_min');
        $maximum = $posting->getAttribute('pay_salary_max');

        if ($minimum === null && $maximum === null) {
            return null;
        }

        $amount = $minimum !== null && $maximum !== null
            ? number_format((int) $minimum).'–'.number_format((int) $maximum)
            : number_format((int) ($minimum ?? $maximum));

        return trim(($posting->getAttribute('pay_salary_currency') ?? '').' '.$amount.' '.($posting->getAttribute('pay_salary_period') ?? ''));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'role' => $schema->string()->max(100)->description("A job title to search for, in place of the profile's target roles."),
            'remote' => $schema->boolean(),
            'min_salary' => $schema->integer()->min(1),
            'currency' => $schema->string()->pattern('^[A-Z]{3}$')->description('ISO 4217 code of the salary the user named, for example EUR.'),
            'salary_period' => $schema->string()->enum(['monthly', 'yearly']),
            'limit' => $schema->integer()->min(1)->max(20)->description('The maximum number of jobs to return. The default is 10.'),
        ];
    }
}
