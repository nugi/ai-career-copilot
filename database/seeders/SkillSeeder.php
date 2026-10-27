<?php

namespace Database\Seeders;

use App\Models\Skill;
use Illuminate\Database\Seeder;

class SkillSeeder extends Seeder
{
    /**
     * Canonical skills and the other names job postings and resumes use for them.
     *
     * @var array<string, list<string>>
     */
    public const SKILLS = [
        'PHP' => [],
        'Laravel' => ['laravel framework'],
        'JavaScript' => ['js', 'ecmascript'],
        'TypeScript' => ['ts'],
        'React' => ['react.js', 'reactjs'],
        'React Native' => ['react-native'],
        'Vue.js' => ['vue', 'vuejs'],
        'Next.js' => ['nextjs'],
        'Node.js' => ['node', 'nodejs'],
        'Tailwind CSS' => ['tailwind', 'tailwindcss'],
        'Python' => [],
        'Go' => ['golang'],
        'Java' => [],
        'Kotlin' => [],
        'Ruby on Rails' => ['rails', 'ror'],
        '.NET Core' => ['dotnet core'],
        'Swift' => [],
        'Flutter' => [],
        'Dart' => [],
        'SQL' => [],
        'PostgreSQL' => ['postgres', 'psql'],
        'MySQL' => [],
        'Redis' => [],
        'MongoDB' => ['mongo'],
        'NoSQL' => ['nosql databases'],
        'Elasticsearch' => ['elastic search'],
        'Kafka' => ['apache kafka'],
        'GraphQL' => [],
        'REST APIs' => ['rest', 'restful api', 'restful apis', 'rest api'],
        'Microservices' => ['microservice architecture'],
        'Docker' => [],
        'Kubernetes' => ['k8s'],
        'AWS' => ['amazon web services'],
        'Google Cloud' => ['gcp', 'google cloud platform'],
        'Terraform' => [],
        'Linux' => [],
        'Bash' => ['bash scripting'],
        'Git' => [],
        'CI/CD' => ['continuous integration', 'continuous delivery'],
        'Playwright' => [],
        'Jest' => [],
        'Selenium' => [],
        'Machine Learning' => ['ml'],
        'PyTorch' => [],
    ];

    /**
     * Seed the canonical skills. Safe to run more than once.
     */
    public function run(): void
    {
        foreach (self::SKILLS as $name => $aliases) {
            $skill = Skill::query()->firstOrNew(['normalized_name' => Skill::normalize($name)], ['name' => $name]);
            $skill->is_verified = true;
            $skill->save();

            foreach ($aliases as $alias) {
                $skill->aliases()->firstOrCreate(['alias' => Skill::normalize($alias)]);
            }
        }
    }
}
