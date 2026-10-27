<?php

use App\Actions\Skills\SkillGrounding;
use App\Models\Skill;
use App\Models\SkillAlias;

test('common words in ordinary prose do not ground ambiguous skills', function () {
    $content = "I was the go-to person for releases.\nLed R&D for the payments team.\nWon the Spring 2021 hackathon.";

    expect(app(SkillGrounding::class)->skills($content, ['Go', 'R', 'Spring']))
        ->toBe([[], ['Go', 'R', 'Spring']]);
});

test('ambiguous skills are grounded when a technical neighbour is on the same line', function () {
    $content = 'Skills: Go, R, Spring, PostgreSQL';

    expect(app(SkillGrounding::class)->skills($content, ['Go', 'R', 'Spring', 'PostgreSQL']))
        ->toBe([['Go', 'R', 'Spring', 'PostgreSQL'], []]);
});

test('an ambiguous skill without a technical neighbour on its line is ungrounded', function () {
    expect(app(SkillGrounding::class)->skills('Built services in Go', ['Go']))
        ->toBe([[], ['Go']]);
});

test('a longer name that contains a single letter does not ground it', function (string $content) {
    expect(app(SkillGrounding::class)->skills($content, ['C', 'Python']))
        ->toBe([['Python'], ['C']]);
})->with([
    'C++' => ['Built tools in C++ and Python'],
    'Objective-C' => ['Built apps in Objective-C and Python'],
    'C-level' => ['Reported to C-level staff on Python adoption'],
]);

test('a single letter on a line with a technical neighbour is grounded', function () {
    expect(app(SkillGrounding::class)->skills('Languages: C, Python', ['C', 'Python']))
        ->toBe([['C', 'Python'], []]);
});

test('an unambiguous alias grounds an ambiguous skill without a technical neighbour', function () {
    $go = Skill::factory()->create(['name' => 'Go']);
    SkillAlias::factory()->for($go)->create(['alias' => 'golang']);

    expect(app(SkillGrounding::class)->skills('Built services in golang.', ['Go']))
        ->toBe([['Go'], []]);
});
