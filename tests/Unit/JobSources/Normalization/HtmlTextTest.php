<?php

use App\JobSources\Normalization\HtmlText;

test('HTML becomes readable plain text', function (string $html, string $expected) {
    expect(HtmlText::from($html))->toBe($expected);
})->with([
    'paragraphs and a list' => ['<p>A</p><ul><li>B &amp; C</li><li>D</li></ul>', "A\n\n- B & C\n- D"],
    'line breaks' => ['A<br>B', "A\nB"],
    'script content is dropped' => ['<p>A</p><script>alert("unsafe")</script><p>B</p>', "A\n\nB"],
    'plain text' => ['  Plain text  ', 'Plain text'],
]);
