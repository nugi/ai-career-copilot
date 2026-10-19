<?php

test('pcre diagnostic', function () {
    $text = 'Call 021-5551234 today';
    $patterns = [
        'full' => '~(?<!\d)(?:\(0\d{2,3}\)|0\d{2,3}|\+62[ -]?\d{2,3})[ -]?\d{3,4}[ -]?\d{3,4}(?!\d)~u',
        'no_lookbehind' => '~(?:\(0\d{2,3}\)|0\d{2,3}|\+62[ -]?\d{2,3})[ -]?\d{3,4}[ -]?\d{3,4}(?!\d)~u',
        'no_lookahead' => '~(?<!\d)(?:\(0\d{2,3}\)|0\d{2,3}|\+62[ -]?\d{2,3})[ -]?\d{3,4}[ -]?\d{3,4}~u',
        'no_u' => '~(?<!\d)(?:\(0\d{2,3}\)|0\d{2,3}|\+62[ -]?\d{2,3})[ -]?\d{3,4}[ -]?\d{3,4}(?!\d)~',
        'only_0_branch' => '~(?<!\d)0\d{2,3}[ -]?\d{3,4}[ -]?\d{3,4}(?!\d)~u',
        'escaped_dash' => '~(?<!\d)(?:\(0\d{2,3}\)|0\d{2,3}|\+62[ \-]?\d{2,3})[ \-]?\d{3,4}[ \-]?\d{3,4}(?!\d)~u',
    ];
    $out = ['PCRE '.PCRE_VERSION.' jit='.ini_get('pcre.jit')];
    foreach ($patterns as $name => $p) {
        $out[] = $name.': '.var_export(preg_match($p, $text, $m), true).' '.json_encode($m).' err='.preg_last_error_msg();
    }
    $out[] = 'redactor: '.(new App\Ai\PiiRedactor)->redact($text);

    expect(implode(' || ', $out))->toBe('show');
});
