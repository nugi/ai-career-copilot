<?php

namespace App\JobSources\Normalization;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;

/**
 * Turns the untrusted HTML of a job description into plain text with paragraphs and list items kept, so no
 * source HTML is ever stored or shown.
 */
final class HtmlText
{
    /**
     * Turn untrusted HTML into readable text without executable or hidden content.
     */
    public static function from(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');
        $text = self::text($document);
        $text = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $lines = array_map(trim(...), explode("\n", $text));
        $text = implode("\n", $lines);

        return trim(preg_replace('/\n{3,}/', "\n\n", $text) ?? $text);
    }

    /**
     * Walk text nodes, preserving the layout boundaries of supported elements.
     */
    private static function text(Node $node): string
    {
        if ($node instanceof Text) {
            return $node->textContent;
        }

        $tag = $node instanceof Element ? strtolower($node->localName) : '';

        if (in_array($tag, ['script', 'style', 'template', 'noscript'], true)) {
            return '';
        }

        if ($tag === 'br') {
            return "\n";
        }

        $text = '';

        foreach ($node->childNodes as $child) {
            $text .= self::text($child);
        }

        return match ($tag) {
            'p', 'div', 'section', 'article', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'ul', 'ol', 'table', 'tr', 'blockquote', 'pre' => $text."\n\n",
            'li' => "\n- ".trim($text),
            'td', 'th' => $text.' ',
            default => $text,
        };
    }
}
