<?php

use App\Actions\Resume\CheckResumeGrounding;

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
