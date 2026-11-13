<?php

namespace App\Twig;

use Twig\Attribute\AsTwigFilter;

/** Marks the searched words inside a result, so customers see why it matched. */
final class SearchHighlightExtension
{
    /**
     * The text, HTML-escaped, with every search term wrapped in <mark>. Matching runs on the raw text before
     * escaping, so a term like "amp" never breaks an entity such as &amp;.
     *
     * @param list<string> $terms
     */
    #[AsTwigFilter('highlight', isSafe: ['html'])]
    public function highlight(?string $text, array $terms): string
    {
        $text = (string) $text;
        $terms = array_values(array_filter($terms, static fn (string $term): bool => '' !== $term));
        if ([] === $terms) {
            return $this->escape($text);
        }
        usort($terms, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $pattern = '/('.implode('|', array_map(static fn (string $term): string => preg_quote($term, '/'), $terms)).')/iu';
        $parts = preg_split($pattern, $text, -1, \PREG_SPLIT_DELIM_CAPTURE);
        if (false === $parts) {
            return $this->escape($text);
        }

        $html = '';
        foreach ($parts as $index => $part) {
            $html .= 1 === $index % 2 ? '<mark>'.$this->escape($part).'</mark>' : $this->escape($part);
        }

        return $html;
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
