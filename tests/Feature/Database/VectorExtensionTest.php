<?php

use Illuminate\Support\Facades\DB;

test('migrations enable vector distance queries', function () {
    $distance = DB::scalar("select '[1,0]'::vector <=> '[0,1]'::vector");

    expect((float) $distance)->toBe(1.0);
});
