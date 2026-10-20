<?php

use App\Actions\Resume\CheckResumeGrounding;
use App\Models\Skill;
use App\Models\SkillAlias;

test('common words in ordinary prose do not ground ambiguous skills', function () {
    $content = "I was the go-to person for releases.\nLed R&D for the payments team.\nWon the Spring 2021 hackathon.";

    expect(app(CheckResumeGrounding::class)->skills($content, ['Go', 'R', 'Spring']))
        ->toBe([[], ['Go', 'R', 'Spring']]);
});

test('ambiguous skills are grounded when a technical neighbour is on the same line', function () {
    $content = 'Skills: Go, R, Spring, PostgreSQL';

    expect(app(CheckResumeGrounding::class)->skills($content, ['Go', 'R', 'Spring', 'PostgreSQL']))
        ->toBe([['Go', 'R', 'Spring', 'PostgreSQL'], []]);
});

test('an ambiguous skill without a technical neighbour on its line is ungrounded', function () {
    expect(app(CheckResumeGrounding::class)->skills('Built services in Go', ['Go']))
        ->toBe([[], ['Go']]);
});

test('a longer name that contains a single letter does not ground it', function (string $content) {
    expect(app(CheckResumeGrounding::class)->skills($content, ['C', 'Python']))
        ->toBe([['Python'], ['C']]);
})->with([
    'C++' => ['Built tools in C++ and Python'],
    'Objective-C' => ['Built apps in Objective-C and Python'],
    'C-level' => ['Reported to C-level staff on Python adoption'],
]);

test('a single letter on a line with a technical neighbour is grounded', function () {
    expect(app(CheckResumeGrounding::class)->skills('Languages: C, Python', ['C', 'Python']))
        ->toBe([['C', 'Python'], []]);
});

test('an unambiguous alias grounds an ambiguous skill without a technical neighbour', function () {
    $go = Skill::factory()->create(['name' => 'Go']);
    SkillAlias::factory()->for($go)->create(['alias' => 'golang']);

    expect(app(CheckResumeGrounding::class)->skills('Built services in golang.', ['Go']))
        ->toBe([['Go'], []]);
});

test('a bullet that appears word for word is grounded', function () {
    $content = "Designed a PostgreSQL schema for 2M orders.\nMoved queues to Redis.";
    $experiences = [['bullets' => ['Designed a PostgreSQL schema for 2M orders.', 'Moved queues to Redis.']]];

    expect(app(CheckResumeGrounding::class)->bullets($content, $experiences))->toBe([]);
});

test('a bullet split over two lines, with a bullet character and curly quotes, is grounded', function () {
    $content = "• Led the team’s\n  migration to Redis.";
    $experiences = [['bullets' => ["Led the team's migration to Redis."]]];

    expect(app(CheckResumeGrounding::class)->bullets($content, $experiences))->toBe([]);
});

test('a reworded bullet is ungrounded and its path is returned', function () {
    $content = "Designed a PostgreSQL schema for 2M orders.\nMoved queues to Redis.";
    $experiences = [
        ['bullets' => ['Designed a PostgreSQL schema for 2M orders.']],
        ['bullets' => ['Moved the queues to Redis.']],
    ];

    expect(app(CheckResumeGrounding::class)->bullets($content, $experiences))->toBe(['experiences.1.bullets.0']);
});
