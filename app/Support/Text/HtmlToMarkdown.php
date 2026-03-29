<?php

namespace App\Support\Text;

class HtmlToMarkdown
{
    public static function convert(?string $html): string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }

        if (! str_contains($html, '<')) {
            return self::normalizeWhitespace(self::decodeEntities($html));
        }

        $markdown = $html;

        // Remove non-content blocks early.
        $markdown = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $markdown) ?? $markdown;
        $markdown = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $markdown) ?? $markdown;

        // Convert links before stripping tags.
        $markdown = preg_replace_callback(
            '/<a\b[^>]*href\s*=\s*[\"\']?([^\"\'>\s]+)[^>]*>(.*?)<\/a>/is',
            function (array $matches): string {
                $href = trim((string) ($matches[1] ?? ''));
                $text = self::inlineText((string) ($matches[2] ?? ''));

                if ($href === '') {
                    return $text;
                }

                return $text === '' ? $href : sprintf('[%s](%s)', $text, $href);
            },
            $markdown
        ) ?? $markdown;

        // Headings.
        for ($level = 1; $level <= 6; $level++) {
            $pattern = sprintf('/<h%d\b[^>]*>(.*?)<\/h%d>/is', $level, $level);
            $prefix = str_repeat('#', $level).' ';
            $markdown = preg_replace_callback(
                $pattern,
                fn (array $matches): string => $prefix.self::inlineText((string) ($matches[1] ?? ''))."\n\n",
                $markdown
            ) ?? $markdown;
        }

        // Lists and paragraphs.
        $markdown = preg_replace_callback(
            '/<li\b[^>]*>(.*?)<\/li>/is',
            fn (array $matches): string => '- '.self::inlineText((string) ($matches[1] ?? ''))."\n",
            $markdown
        ) ?? $markdown;
        $markdown = preg_replace('/<\/(ul|ol)>/i', "\n", $markdown) ?? $markdown;
        $markdown = preg_replace('/<(ul|ol)\b[^>]*>/i', "\n", $markdown) ?? $markdown;
        $markdown = preg_replace('/<(p|div|section|article|blockquote)\b[^>]*>/i', '', $markdown) ?? $markdown;
        $markdown = preg_replace('/<\/(p|div|section|article|blockquote)>/i', "\n\n", $markdown) ?? $markdown;

        // Inline formatting.
        $markdown = preg_replace('/<(strong|b)\b[^>]*>/i', '**', $markdown) ?? $markdown;
        $markdown = preg_replace('/<\/(strong|b)>/i', '**', $markdown) ?? $markdown;
        $markdown = preg_replace('/<(em|i)\b[^>]*>/i', '*', $markdown) ?? $markdown;
        $markdown = preg_replace('/<\/(em|i)>/i', '*', $markdown) ?? $markdown;
        $markdown = preg_replace('/<code\b[^>]*>/i', '`', $markdown) ?? $markdown;
        $markdown = preg_replace('/<\/code>/i', '`', $markdown) ?? $markdown;
        $markdown = preg_replace('/<br\s*\/?>/i', "\n", $markdown) ?? $markdown;
        $markdown = preg_replace('/<hr\s*\/?>/i', "\n---\n", $markdown) ?? $markdown;

        // Strip any remaining HTML tags.
        $markdown = strip_tags($markdown);
        $markdown = self::decodeEntities($markdown);

        return self::normalizeWhitespace($markdown);
    }

    protected static function inlineText(string $value): string
    {
        return self::normalizeWhitespace(self::decodeEntities(strip_tags($value)));
    }

    protected static function decodeEntities(string $value): string
    {
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return str_replace("\xc2\xa0", ' ', $decoded);
    }

    protected static function normalizeWhitespace(string $value): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $value);
        $normalized = preg_replace("/[ \t]+/u", ' ', $normalized) ?? $normalized;
        $normalized = preg_replace("/\n{3,}/u", "\n\n", $normalized) ?? $normalized;
        $normalized = preg_replace("/[ \t]+\n/u", "\n", $normalized) ?? $normalized;

        return trim($normalized);
    }
}
