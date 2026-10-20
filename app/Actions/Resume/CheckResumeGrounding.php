<?php

namespace App\Actions\Resume;

use App\Models\Skill;
use App\Models\SkillAlias;

class CheckResumeGrounding
{
    /**
     * Skill names that are also common English words or the names of common tools and frameworks.
     * A whole-word match of such a name in ordinary prose proves nothing, so these names need a stronger check.
     *
     * @var list<string>
     */
    private const COMMON_WORD_SKILLS = [
        'Go', 'R', 'C', 'D', 'Spring', 'Express', 'Swift', 'Rust', 'Ruby', 'Java', 'Dart', 'Elm', 'Julia', 'Crystal',
        'Flutter', 'Rails', 'Chef', 'Puppet', 'Salt', 'Spark', 'Hive', 'Pig', 'Storm', 'Jest', 'Mocha', 'Next', 'Nest',
        'Ember', 'Meteor', 'Backbone',
    ];

    /**
     * Split the skill names into those the resume text names and those it does not.
     *
     * A name is grounded when the name, the canonical name or an alias of its skill appears in the text as a whole word.
     * An ambiguous name needs more evidence. See ambiguousNameIsGrounded().
     *
     * @param  list<string>  $names
     * @return array{0: list<string>, 1: list<string>}
     */
    public function skills(string $content, array $names): array
    {
        $entries = [];

        foreach ($names as $index => $name) {
            $skill = Skill::findByName($name);
            $canonical = $skill === null ? $name : $skill->name;

            $entries[$index] = [
                'name' => $name,
                'canonical' => $canonical,
                'aliases' => $skill === null ? [] : array_values(array_map(fn (SkillAlias $alias): string => $alias->alias, $skill->aliases->all())),
                'ambiguous' => $this->isAmbiguous($name) || $this->isAmbiguous($canonical),
            ];
        }

        $grounded = [];
        $neighbourTerms = [];

        // The skills that are not ambiguous go first, so their terms are known when the ambiguous skills are checked.
        foreach ($entries as $index => $entry) {
            if ($entry['ambiguous']) {
                continue;
            }

            $terms = [$entry['name'], $entry['canonical'], ...$entry['aliases']];
            $grounded[$index] = array_any($terms, fn (string $term): bool => $this->appearsIn($content, $term));

            if ($grounded[$index]) {
                array_push($neighbourTerms, ...$terms);
            }
        }

        $lines = explode("\n", $content);

        foreach ($entries as $index => $entry) {
            if ($entry['ambiguous']) {
                $grounded[$index] = $this->ambiguousNameIsGrounded($lines, $entry, $neighbourTerms);
            }
        }

        $groundedNames = [];
        $ungroundedNames = [];

        foreach ($entries as $index => $entry) {
            if ($grounded[$index]) {
                $groundedNames[] = $entry['name'];
            } else {
                $ungroundedNames[] = $entry['name'];
            }
        }

        return [$groundedNames, array_values(array_unique($ungroundedNames))];
    }

    /**
     * List the paths of the bullets that the resume text does not contain word for word.
     *
     * Both the text and each bullet are normalized first, so curly quotes, dashes, bullet characters and line breaks do not
     * cause a false mismatch. A bullet is grounded when its normalized text is part of the normalized text.
     *
     * @param  array<mixed>  $experiences
     * @return list<string> The paths, such as experiences.0.bullets.2.
     */
    public function bullets(string $content, array $experiences): array
    {
        $normalizedContent = $this->normalizeText($content);
        $ungrounded = [];

        foreach ($experiences as $experienceIndex => $experience) {
            foreach ($experience['bullets'] as $bulletIndex => $bullet) {
                $normalizedBullet = $this->normalizeText($bullet);

                if ($normalizedBullet !== '' && ! str_contains($normalizedContent, $normalizedBullet)) {
                    $ungrounded[] = "experiences.{$experienceIndex}.bullets.{$bulletIndex}";
                }
            }
        }

        return $ungrounded;
    }

    /**
     * Decide whether an ambiguous name is grounded. It is grounded when one line has an unambiguous alias of its skill,
     * or the canonical name as a whole word, case-sensitive, with a technical neighbour on the same line.
     *
     * @param  list<string>  $lines
     * @param  array{name: string, canonical: string, aliases: list<string>, ambiguous: bool}  $entry
     * @param  list<string>  $neighbourTerms  The terms of the grounded skills that are not ambiguous.
     */
    private function ambiguousNameIsGrounded(array $lines, array $entry, array $neighbourTerms): bool
    {
        $safeAliases = array_values(array_filter($entry['aliases'], fn (string $alias): bool => ! $this->isAmbiguous($alias)));

        foreach ($lines as $line) {
            if (array_any($safeAliases, fn (string $alias): bool => $this->appearsIn($line, $alias))) {
                return true;
            }

            if ($this->appearsExactly($line, $entry['canonical'])
                && array_any($neighbourTerms, fn (string $term): bool => $this->appearsIn($line, $term))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether a name is ambiguous: it has 2 characters or fewer, or it is a common word.
     */
    private function isAmbiguous(string $name): bool
    {
        return mb_strlen(trim($name)) <= 2
            || in_array(mb_strtolower(trim($name)), array_map(mb_strtolower(...), self::COMMON_WORD_SKILLS), true);
    }

    /**
     * Determine whether a term appears in the text as a whole word, ignoring case. Letters and digits must not touch it.
     */
    private function appearsIn(string $content, string $term): bool
    {
        return $term !== ''
            && preg_match('/(?<![\p{L}\p{N}])'.preg_quote($term, '/').'(?![\p{L}\p{N}])/iu', $content) === 1;
    }

    /**
     * Determine whether a term appears in the text as a whole word, with the case that it has.
     *
     * A name of one character must not be followed by &, an apostrophe, +, #, a dash or a dot, and must not follow a dash or a dot.
     * So R&D, C's, C++, C#, C-level and Objective-C do not count for R or C.
     */
    private function appearsExactly(string $content, string $term): bool
    {
        $isLetter = mb_strlen($term) === 1;
        $leader = $isLetter ? '(?<![\p{L}\p{N}\-.])' : '(?<![\p{L}\p{N}])';
        $follower = $isLetter ? '(?![\p{L}\p{N}&\'’+#\-.])' : '(?![\p{L}\p{N}])';

        return $term !== ''
            && preg_match('/'.$leader.preg_quote($term, '/').$follower.'/u', $content) === 1;
    }

    /**
     * Normalize a text for the bullet check: plain quotes and dashes, no bullet characters at the start of a line,
     * one space for each run of whitespace, lowercase, and trimmed.
     */
    private function normalizeText(string $text): string
    {
        $text = strtr($text, ['‘' => "'", '’' => "'", '“' => '"', '”' => '"', '–' => '-', '—' => '-']);
        $text = preg_replace('/^[ \t]*[•·▪●◦-][ \t]*/mu', '', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim(mb_strtolower($text));
    }
}
