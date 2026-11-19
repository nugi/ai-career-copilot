<?php

namespace Database\Factories;

use App\Ai\Agents\LearningPlanner;
use App\Models\LearningPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LearningPlan>
 */
class LearningPlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'gaps' => [
                ['skill' => 'Docker', 'weight' => 3.5, 'required_jobs' => 4, 'nice_jobs' => 1, 'jobs' => 5, 'of' => 10],
            ],
            'plan' => [
                'summary' => 'Practice Docker by packaging a backend service.',
                'weeks' => [
                    ['week' => 1, 'goal' => 'Run a container locally.', 'skills' => ['Docker'], 'tasks' => ['Write a container definition.', 'Run it and inspect its logs.']],
                    ['week' => 2, 'goal' => 'Package the service.', 'skills' => ['Docker'], 'tasks' => ['Build a service image.', 'Pass configuration through environment variables.']],
                    ['week' => 3, 'goal' => 'Connect the service to its database.', 'skills' => ['Docker'], 'tasks' => ['Configure a local database container.', 'Check that data survives a restart.']],
                    ['week' => 4, 'goal' => 'Make the project reproducible.', 'skills' => ['Docker'], 'tasks' => ['Test the setup from a clean checkout.', 'Document build and run commands.']],
                ],
                'project' => [
                    'title' => 'Containerized task API',
                    'description' => 'Package a task API and its database with a reproducible local setup.',
                    'skills' => ['Docker'],
                ],
                'showcase' => [
                    'resume_bullet' => 'Built a containerized task API with a documented local setup.',
                    'linkedin' => 'I practiced Docker by packaging a task API. The project includes setup notes.',
                ],
                'dropped_skills' => 0,
            ],
            'prompt_version' => LearningPlanner::PROMPT_VERSION,
            'generated_at' => now(),
        ];
    }
}
