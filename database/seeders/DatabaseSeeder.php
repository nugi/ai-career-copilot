<?php

namespace Database\Seeders;

use App\Enums\SkillProficiency;
use App\Enums\SkillSource;
use App\Models\JobPosting;
use App\Models\Profile;
use App\Models\Resume;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(SkillSeeder::class);

        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        Profile::factory()->for($user)->create([
            'headline' => 'Backend engineer focused on Laravel and PostgreSQL',
            'target_roles' => ['Backend Engineer', 'Full Stack Developer'],
            'preferred_locations' => ['Jakarta', 'Bandung'],
        ]);

        Resume::factory()->for($user)->primary()->extracted()->create(['label' => 'Backend CV']);

        $proficiencies = [
            'PHP' => SkillProficiency::Expert,
            'Laravel' => SkillProficiency::Expert,
            'PostgreSQL' => SkillProficiency::Advanced,
            'Redis' => SkillProficiency::Intermediate,
            'Docker' => SkillProficiency::Intermediate,
            'React' => SkillProficiency::Beginner,
        ];

        Skill::query()
            ->whereIn('normalized_name', array_map(Skill::normalize(...), array_keys($proficiencies)))
            ->get()
            ->each(fn (Skill $skill) => $user->skills()->attach($skill, ['proficiency' => $proficiencies[$skill->name], 'source' => SkillSource::Profile]));

        JobPosting::factory()->count(30)->withSkills()->create();
        JobPosting::factory()->count(15)->remote()->withSkills()->create();
        JobPosting::factory()->count(5)->withoutSalary()->withSkills()->create();
    }
}
