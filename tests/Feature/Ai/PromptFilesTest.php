<?php

use Illuminate\Support\Facades\File;

/**
 * A merged prompt file must not change. A prompt change needs a new version file.
 *
 * @var array<string, string>
 */
const PUBLISHED_PROMPT_HASHES = [
    'relevance-grader/v1.md' => '0c687b27153d1a71e7b4e2cb7ca7b3ca3f293bf480f67c878fac916ed918f628',
    'relevance-grader/v2.md' => '7e0d021df62da6a934ba9fa5d2699a20db24cf93ebbf7dd4ae5dca52d2040082',
    'relevance-grader/v3.md' => 'd18892e8a90164a5d65ca06e5582f8d00882884a0026687719e6feb12b76d0cd',
    'relevance-grader/v4.md' => 'dea517767ed7735c0d7585e7ad5946ccd18fa1a10aabd3ca1dc8607ed1e9f531',
    'career-advisor/v1.md' => 'b564fbf74884561cf51762627e7002bb228bc483595c560a5f5a046b016fc143',
    'career-advisor/v2.md' => 'f83610ceae85a1c0f31e659ee87b16bac91db1e64b2ae368d7d3839e0696ff81',
    'resume-parser/v1.md' => 'b808ce53957921398795497ebfcb3836bc512bb60795b4d25d50ee173d5faae7',
    'job-skill-extractor/v1.md' => '6ad48d3ee721f1c246e7648a18a39e4195f29bf0ba8ab416f5cdabea66640603',
    'job-skill-extractor/v2.md' => 'a3ea6c1559b456905b23b493a6adfbcff69d4094e304923d1e85cadeb1cce1eb',
    'job-skill-extractor/v3.md' => 'c83e4abd652b3b17670254fd1e0c139f6bdb0649de83c8a3623fac6186c85eaf',
    'fit-explainer/v1.md' => '8e7678111b663b05840f3e44ae6ae435e163e286b7ad08158b315487e6378026',
    'faithfulness-judge/v1.md' => '92f1643ac696ed0ab61e15c96a4fc42dc2f2d14e24c85408e4525c2b42a98e0a',
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
