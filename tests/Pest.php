<?php

use App\Ai\Agents\CareerAdvisor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

/**
 * Assert that a text holds no email address, no phone number and none of the given personal values.
 *
 * The checks do not use PiiRedactor, so they still fail when a redaction pattern breaks. A phone number is a run of digit groups
 * joined by single spaces, dots, dashes or parentheses, with an optional + in front, that has 9 or more digits.
 * The personal values, such as a name or a street, match in any case.
 */
expect()->extend('toBeRedacted', function (string ...$personalValues) {
    $text = $this->toBeString()->value;

    preg_match_all('~[^\s@]+@[^\s@]+\.[^\s@]+~u', $text, $emails);
    preg_match_all('~\+?\(?\d+\)?(?:[ .\-]\(?\d+\)?)*~u', $text, $digitRuns);
    $phones = array_values(array_filter($digitRuns[0], fn (string $run): bool => preg_match_all('~\d~', $run) >= 9));

    expect($emails[0])->toBe([], 'The text holds an email address.');
    expect($phones)->toBe([], 'The text holds a phone number.');

    foreach ($personalValues as $value) {
        expect(mb_stripos($text, $value))->toBeFalse("The text holds the personal value [{$value}].");
    }

    return $this;
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * A Responses API body from OpenAI that answers with the given text.
 *
 * @return array<string, mixed>
 */
function openAiAnswer(string $text): array
{
    return [
        'id' => 'resp_123',
        'object' => 'response',
        'status' => 'completed',
        'model' => 'gpt-6-luna',
        'output' => [[
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'output_text', 'text' => $text]],
        ]],
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ];
}

/**
 * A Messages API body from Anthropic that answers with the given text.
 *
 * @return array<string, mixed>
 */
function anthropicAnswer(string $text): array
{
    return [
        'id' => 'msg_123',
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-haiku-5-5',
        'content' => [['type' => 'text', 'text' => $text]],
        'stop_reason' => 'end_turn',
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ];
}

/**
 * A career advisor for a user who has finished onboarding.
 */
function advisor(): CareerAdvisor
{
    return new CareerAdvisor(User::factory()->onboarded()->create());
}

/**
 * A valid output of the resume parser, for a resume whose text is the extracted() factory text. The overrides replace its keys.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function parsedResume(array $overrides = []): array
{
    return [
        'summary' => 'Backend engineer with five years of experience.',
        'skills' => ['PHP', 'Laravel', 'PostgreSQL'],
        'years_of_experience' => 3.0,
        'experiences' => [[
            'title' => 'Backend Engineer',
            'company' => 'Acme Corp',
            'start' => '2019-01',
            'end' => '2021-12',
            'bullets' => ['Designed a PostgreSQL schema for 2M orders'],
        ]],
        'education' => [[
            'institution' => 'Universitas Gadjah Mada',
            'degree' => 'S1',
            'field' => 'Teknik Informatika',
            'start' => '2014',
            'end' => '2018',
        ]],
        ...$overrides,
    ];
}
