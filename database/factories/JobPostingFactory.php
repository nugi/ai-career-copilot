<?php

namespace Database\Factories;

use App\Enums\Currency;
use App\Enums\EmploymentType;
use App\Enums\JobRegion;
use App\Enums\SalaryPeriod;
use App\Enums\SeniorityLevel;
use App\Enums\WorkArrangement;
use App\JobSources\Normalization\DuplicateKey;
use App\Models\JobPosting;
use App\Models\Opening;
use App\Models\Skill;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobPosting>
 */
class JobPostingFactory extends Factory
{
    /**
     * Roles with the skills their postings usually ask for. The first skills are required, the rest are nice to have.
     *
     * @var array<string, array{required: list<string>, preferred: list<string>}>
     */
    public const ROLES = [
        'Backend Engineer (Laravel)' => ['required' => ['PHP', 'Laravel', 'PostgreSQL'], 'preferred' => ['Redis', 'Docker', 'AWS']],
        'PHP Developer' => ['required' => ['PHP', 'Laravel', 'MySQL'], 'preferred' => ['Vue.js', 'Git']],
        'Full Stack Developer' => ['required' => ['TypeScript', 'React', 'Node.js'], 'preferred' => ['PostgreSQL', 'Docker', 'Next.js']],
        'Frontend Engineer' => ['required' => ['TypeScript', 'React', 'Tailwind CSS'], 'preferred' => ['Next.js', 'GraphQL', 'Playwright']],
        'Mobile Developer (Flutter)' => ['required' => ['Flutter', 'Dart'], 'preferred' => ['Kotlin', 'Swift', 'REST APIs']],
        'DevOps Engineer' => ['required' => ['Linux', 'Docker', 'Kubernetes', 'CI/CD'], 'preferred' => ['Terraform', 'AWS']],
        'Platform Engineer' => ['required' => ['Go', 'Kubernetes', 'AWS'], 'preferred' => ['Terraform', 'Kafka']],
        'Backend Engineer (Go)' => ['required' => ['Go', 'PostgreSQL', 'Microservices'], 'preferred' => ['Kafka', 'Redis', 'Google Cloud']],
        'Data Engineer' => ['required' => ['Python', 'SQL', 'Kafka'], 'preferred' => ['Google Cloud', 'Elasticsearch']],
        'Machine Learning Engineer' => ['required' => ['Python', 'Machine Learning', 'PyTorch'], 'preferred' => ['AWS', 'Docker']],
        'QA Automation Engineer' => ['required' => ['Playwright', 'TypeScript'], 'preferred' => ['Selenium', 'CI/CD']],
    ];

    /**
     * Indonesian cities that list most tech roles.
     *
     * @var list<string>
     */
    private const INDONESIAN_CITIES = [
        'Jakarta Selatan', 'Jakarta Barat', 'Tangerang Selatan', 'Bandung', 'Surabaya',
        'Yogyakarta', 'Semarang', 'Malang', 'Denpasar', 'Medan', 'Makassar',
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $role = fake()->randomElement(array_keys(self::ROLES));
        $seniority = fake()->randomElement(SeniorityLevel::cases());
        $company = fake('id_ID')->company();
        $postedAt = fake()->dateTimeBetween('-30 days');

        return [
            'source' => 'manual',
            'external_id' => null,
            'title' => $this->titleFor($role, $seniority),
            'company_name' => $company,
            'description' => $this->descriptionFor($role, $company),
            'location' => fake()->randomElement(self::INDONESIAN_CITIES),
            'country_code' => 'ID',
            'country_codes' => ['ID'],
            'region' => null,
            'normalized_company' => fn (array $attributes): string => DuplicateKey::company($attributes['company_name']),
            'normalized_title' => fn (array $attributes): string => DuplicateKey::title($attributes['title']),
            'location_key' => function (array $attributes): string {
                $countries = $attributes['country_codes'];
                sort($countries);

                return $countries !== [] ? implode('-', $countries) : ($attributes['region']->value ?? 'unknown');
            },
            'duplicate_key' => fn (array $attributes): string => DuplicateKey::hash(
                $attributes['normalized_company'], $attributes['normalized_title'], $attributes['location_key'],
            ),
            'opening_id' => Opening::factory(),
            'work_arrangement' => fake()->randomElement([WorkArrangement::Onsite, WorkArrangement::Hybrid]),
            'employment_type' => fake()->randomElement([EmploymentType::FullTime, EmploymentType::FullTime, EmploymentType::Contract]),
            'seniority' => $seniority,
            ...$this->idrMonthlySalary($seniority),
            'apply_url' => fake()->unique()->url(),
            'posted_at' => $postedAt,
            'expires_at' => fake()->optional(0.7)->dateTimeBetween($postedAt, '+60 days'),
        ];
    }

    /**
     * A remote role at a company hiring worldwide or across APAC, paid yearly in USD.
     */
    public function remote(): static
    {
        return $this->state(function (array $attributes): array {
            $seniority = $attributes['seniority'] ?? SeniorityLevel::Mid;
            $company = fake()->company();

            return [
                'company_name' => $company,
                'description' => $this->descriptionFor($this->roleFor($attributes['title']), $company),
                'location' => fake()->randomElement(['Remote (Worldwide)', 'Remote (APAC)', 'Remote (UTC+7 ± 3 hours)']),
                'country_code' => null,
                'country_codes' => [],
                'region' => JobRegion::Worldwide,
                'work_arrangement' => WorkArrangement::Remote,
                'employment_type' => fake()->randomElement([EmploymentType::FullTime, EmploymentType::Contract, EmploymentType::Freelance]),
                ...$this->usdYearlySalary($seniority),
            ];
        });
    }

    /**
     * A posting that does not disclose its salary.
     */
    public function withoutSalary(): static
    {
        return $this->state(fn (array $attributes): array => [
            'salary_min' => null,
            'salary_max' => null,
            'salary_currency' => null,
            'salary_period' => null,
            'annual_salary_min' => null,
            'annual_salary_max' => null,
            'salary_normalization_version' => null,
        ]);
    }

    /**
     * A posting whose application window has closed.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'posted_at' => now()->subDays(60),
            'expires_at' => now()->subDays(5),
        ]);
    }

    /**
     * Attach the skills the role usually asks for, creating any skill that does not exist yet.
     */
    public function withSkills(): static
    {
        return $this->afterCreating(function (JobPosting $posting): void {
            $skills = self::ROLES[$this->roleFor($posting->title)];

            foreach (['required' => true, 'preferred' => false] as $group => $isRequired) {
                foreach ($skills[$group] as $name) {
                    $skill = Skill::query()->firstOrCreate(['normalized_name' => Skill::normalize($name)], ['name' => $name]);

                    $posting->skills()->attach($skill, ['is_required' => $isRequired]);
                }
            }
        });
    }

    /**
     * A posting with a unit embedding for its title.
     */
    public function withEmbedding(): static
    {
        return $this->state(fn (array $attributes): array => [
            'embedding' => $this->randomUnitVector(),
            'embedding_model' => 'text-embedding-3-small',
            'embedded_at' => now(),
            'embedding_text_hash' => fn (array $attributes): string => hash('sha256', $attributes['title']),
        ]);
    }

    /**
     * Make a non-zero random vector and normalize it to unit length in pgvector text form.
     */
    private function randomUnitVector(): string
    {
        $components = [];

        for ($dimension = 0; $dimension < 1536; $dimension++) {
            $components[] = fake()->randomElement([-1, 1]) * fake()->numberBetween(1, 1_000_000);
        }

        $length = sqrt(array_sum(array_map(fn (int $component): int => $component * $component, $components)));

        return '['.implode(',', array_map(fn (int $component): float => $component / $length, $components)).']';
    }

    private function titleFor(string $role, SeniorityLevel $seniority): string
    {
        return match ($seniority) {
            SeniorityLevel::Junior => "Junior {$role}",
            SeniorityLevel::Mid => $role,
            SeniorityLevel::Senior => "Senior {$role}",
            SeniorityLevel::Lead => "Lead {$role}",
        };
    }

    private function roleFor(string $title): string
    {
        foreach (array_keys(self::ROLES) as $role) {
            if (str_contains($title, $role)) {
                return $role;
            }
        }

        return array_key_first(self::ROLES);
    }

    private function descriptionFor(string $role, string $company): string
    {
        $skills = self::ROLES[$role];

        return implode("\n\n", [
            "{$company} is hiring a {$role} to build and run the products our customers use every day.",
            'You will work with '.implode(', ', $skills['required']).', ship to production often, and review code with a small, senior team.',
            'Experience with '.implode(' or ', $skills['preferred']).' is a plus.',
        ]);
    }

    /**
     * @return array{salary_min: int, salary_max: int, salary_currency: string, salary_period: SalaryPeriod, annual_salary_min: int, annual_salary_max: int, salary_normalization_version: int}
     */
    private function idrMonthlySalary(SeniorityLevel $seniority): array
    {
        $min = $this->scaleForSeniority(fake()->numberBetween(10, 20), $seniority) * 1_000_000;
        $max = $min + fake()->numberBetween(3, 12) * 1_000_000;
        $factor = SalaryPeriod::Monthly->annualFactor('ID');

        return [
            'salary_min' => $min,
            'salary_max' => $max,
            'salary_currency' => Currency::Idr->value,
            'salary_period' => SalaryPeriod::Monthly,
            'annual_salary_min' => $min * $factor,
            'annual_salary_max' => $max * $factor,
            'salary_normalization_version' => 1,
        ];
    }

    /**
     * @return array{salary_min: int, salary_max: int, salary_currency: string, salary_period: SalaryPeriod, annual_salary_min: int, annual_salary_max: int, salary_normalization_version: int}
     */
    private function usdYearlySalary(SeniorityLevel $seniority): array
    {
        $min = $this->scaleForSeniority(fake()->numberBetween(8, 16), $seniority) * 5_000;
        $max = $min + fake()->numberBetween(2, 8) * 5_000;
        $factor = SalaryPeriod::Yearly->annualFactor(null);

        return [
            'salary_min' => $min,
            'salary_max' => $max,
            'salary_currency' => Currency::Usd->value,
            'salary_period' => SalaryPeriod::Yearly,
            'annual_salary_min' => $min * $factor,
            'annual_salary_max' => $max * $factor,
            'salary_normalization_version' => 1,
        ];
    }

    private function scaleForSeniority(int $base, SeniorityLevel $seniority): int
    {
        return (int) round($base * match ($seniority) {
            SeniorityLevel::Junior => 0.6,
            SeniorityLevel::Mid => 1.0,
            SeniorityLevel::Senior => 1.5,
            SeniorityLevel::Lead => 1.9,
        });
    }
}
