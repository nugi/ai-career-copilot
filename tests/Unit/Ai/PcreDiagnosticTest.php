<?php

test('pcre diagnostic', function () {
    $texts = ['Call 021-5551234 today', 'Call (021) 555-1234 today', 'Call +62 21 5551234 today', 'Call (0274) 512345 today'];
    $patterns = [
        'no_start_opt' => '~(*NO_START_OPT)(?<!\d)(?:\(0\d{2,3}\)|0\d{2,3}|\+62[ -]?\d{2,3})[ -]?\d{3,4}[ -]?\d{3,4}(?!\d)~u',
        'reordered' => '~(?<!\d)(?:0\d{2,3}|\(0\d{2,3}\)|\+62[ -]?\d{2,3})[ -]?\d{3,4}[ -]?\d{3,4}(?!\d)~u',
        'paren_optional' => '~(?<!\d)(?:\(?0\d{2,3}\)?|\+62[ -]?\d{2,3})[ -]?\d{3,4}[ -]?\d{3,4}(?!\d)~u',
        'no_auto_possess' => '~(*NO_AUTO_POSSESS)(?<!\d)(?:\(0\d{2,3}\)|0\d{2,3}|\+62[ -]?\d{2,3})[ -]?\d{3,4}[ -]?\d{3,4}(?!\d)~u',
        'no_jit' => 'NOJIT',
    ];
    $full = '~(?<!\d)(?:\(0\d{2,3}\)|0\d{2,3}|\+62[ -]?\d{2,3})[ -]?\d{3,4}[ -]?\d{3,4}(?!\d)~u';
    $out = ['PCRE '.PCRE_VERSION];
    foreach ($patterns as $name => $p) {
        if ($p === 'NOJIT') {
            ini_set('pcre.jit', '0');
            $p = $full.'S';
        }
        $hits = array_map(fn ($t) => preg_match($p, $t), $texts);
        $out[] = $name.': '.implode('', $hits);
        ini_set('pcre.jit', '1');
    }

    expect(implode(' || ', $out))->toBe('show');
});
