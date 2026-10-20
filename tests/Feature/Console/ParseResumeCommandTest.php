<?php

use App\Ai\Agents\ResumeParser;
use App\Models\Resume;
use App\Models\User;

test('a valid parse exits with 0 and prints the counts', function () {
    ResumeParser::fake([parsedResume()])->preventStrayPrompts();
    $resume = Resume::factory()->extracted()->primary()->for(User::factory()->onboarded())->create();

    $this->artisan('resume:parse', ['resume' => $resume->id])
        ->expectsTable(['Item', 'Value'], [
            ['Skills', 3],
            ['Experiences', 1],
            ['Education entries', 1],
            ['Years of experience', 3],
            ['Model years of experience', 3],
            ['Years mismatch', 'no'],
            ['Ungrounded skills', 0],
        ])
        ->assertExitCode(0);
});

test('the show option prints the parsed data as JSON', function () {
    ResumeParser::fake([parsedResume()])->preventStrayPrompts();
    $resume = Resume::factory()->extracted()->primary()->for(User::factory()->onboarded())->create();

    $this->artisan('resume:parse', ['resume' => $resume->id, '--show' => true])
        ->expectsOutputToContain('"summary": "Backend engineer with five years of experience."')
        ->assertExitCode(0);
});

test('an unknown resume ID exits with 1', function () {
    ResumeParser::fake()->preventStrayPrompts();

    $this->artisan('resume:parse', ['resume' => 999999])
        ->expectsOutputToContain('The resume was not found.')
        ->assertExitCode(1);

    ResumeParser::assertNeverPrompted();
});

test('a resume without text exits with 1 and does not call the provider', function () {
    ResumeParser::fake()->preventStrayPrompts();
    $resume = Resume::factory()->primary()->for(User::factory()->onboarded())->create(['content' => null]);

    $this->artisan('resume:parse', ['resume' => $resume->id])
        ->expectsOutputToContain('The resume has no text to parse.')
        ->assertExitCode(1);

    ResumeParser::assertNeverPrompted();
});

test('an invalid output two times exits with 1 and prints the parse failure', function () {
    $invalid = parsedResume(['experiences' => [[
        'title' => 'Backend Engineer',
        'company' => 'Acme Corp',
        'start' => '2021-06',
        'end' => '2020-12',
        'bullets' => [],
    ]]]);
    ResumeParser::fake([$invalid, $invalid])->preventStrayPrompts();
    $resume = Resume::factory()->extracted()->primary()->for(User::factory()->onboarded())->create();

    $this->artisan('resume:parse', ['resume' => $resume->id])
        ->expectsOutputToContain('The parse failed.')
        ->expectsOutputToContain('experiences.0.end')
        ->assertExitCode(1);
});
