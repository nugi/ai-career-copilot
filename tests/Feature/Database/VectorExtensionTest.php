<?php

use Illuminate\Support\Facades\DB;

test('migrations enable vector distance queries', function () {
    $distance = DB::scalar("select '[1,0]'::vector <=> '[0,1]'::vector");

    expect((float) $distance)->toBe(1.0);
});

test('the installed pgvector version supports iterative index scans', function () {
    $version = DB::scalar("select extversion from pg_extension where extname = 'vector'");

    expect(version_compare($version, '0.8.0', '>='))
        ->toBeTrue("pgvector {$version} is installed, but 0.8.0 or newer is required.");
});
