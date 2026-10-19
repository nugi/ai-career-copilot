<?php

use Illuminate\Support\Facades\File;

/**
 * A merged prompt file must not change. A prompt change needs a new version file.
 *
 * @var array<string, string>
 */
const PUBLISHED_PROMPT_HASHES = [
    'career-advisor/v1.md' => 'b564fbf74884561cf51762627e7002bb228bc483595c560a5f5a046b016fc143',
    'career-advisor/v2.md' => 'f83610ceae85a1c0f31e659ee87b16bac91db1e64b2ae368d7d3839e0696ff81',
];

test('published prompts have not changed', function () {
    foreach (PUBLISHED_PROMPT_HASHES as $path => $expected) {
        $contents = file_get_contents(resource_path('prompts/'.$path));
        $actual = hash('sha256', str_replace("\r\n", "\n", $contents));

        expect($actual)->toBe($expected, "Published prompt {$path} changed. Add a new version instead of editing it.");
    }
});

test('every prompt file has a recorded hash', function () {
    foreach (File::allFiles(resource_path('prompts')) as $file) {
        if ($file->getExtension() !== 'md') {
            continue;
        }

        $path = str_replace('\\', '/', $file->getRelativePathname());

        expect(array_key_exists($path, PUBLISHED_PROMPT_HASHES))->toBeTrue("Prompt {$path} has no recorded hash. Add it to the list when the version is published.");
    }
});
