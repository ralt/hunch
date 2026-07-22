<?php

namespace App\Service;

/**
 * Extracts readable text from an email for indexing. The HTML path exists
 * because strip_tags() alone keeps the *contents* of every element — including
 * <style> and <script>, whose CSS rules and code would leak into the indexed
 * body, the search snippets, and the embedder's 2KB documentTemplate budget.
 */
final class MailText
{
    /** Prefer the text part; fall back to text extracted from the HTML part. */
    public static function extract(object $message): string
    {
        $body = (string) $message->getTextBody();
        if ('' === $body) {
            $body = self::fromHtml((string) $message->getHTMLBody());
        }

        return $body;
    }

    public static function fromHtml(string $html): string
    {
        $text = self::onePass($html);
        // Some senders embed HTML-escaped HTML (&lt;div …&gt;) in the body;
        // entity decoding re-materializes it as tags *after* strip_tags ran.
        // If the output still looks like markup, run it through again.
        for ($i = 0; $i < 2 && preg_match('~<[a-z!/][^>]*>~i', $text); ++$i) {
            $text = self::onePass($text);
        }

        return $text;
    }

    private static function onePass(string $html): string
    {
        if ('' === trim($html)) {
            return '';
        }

        // Drop elements whose inner text is not prose (CSS, JS, <title>), and
        // comments — Outlook's <!--[if mso]> blocks carry markup and CSS too.
        $html = preg_replace('~<(style|script|head|title)\b[^>]*>.*?</\1\s*>~is', '', $html) ?? $html;
        $html = preg_replace('~<!--.*?-->~s', '', $html) ?? $html;
        // Keep some line structure: <br> and block-element ends become newlines.
        $html = preg_replace('~<br\s*/?>|</(?:p|div|tr|li|h[1-6]|table|blockquote)\s*>~i', "\n", $html) ?? $html;

        $text = html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5, 'UTF-8');
        // Collapse the whitespace floods table layouts leave behind. The /u
        // regexes no-op (return null) on invalid UTF-8; the caller forces
        // UTF-8 afterwards, so worst case is uncollapsed whitespace.
        $text = preg_replace('~[ \t\x{A0}]+~u', ' ', $text) ?? $text;
        $text = preg_replace('~ *\n *~', "\n", $text) ?? $text;
        $text = preg_replace('~\n{3,}~', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
