<?php

declare(strict_types=1);

namespace App;

class HtmlSanitizer
{
    private const ALLOWED_TAGS = [
        'p', 'br', 'div', 'span', 'a', 'img', 'table', 'thead', 'tbody', 'tr', 'th', 'td',
        'ul', 'ol', 'li', 'strong', 'em', 'b', 'i', 'u', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'blockquote', 'pre', 'hr', 'font',
    ];

    private const BLOCK_TAGS =
        'script|iframe|object|embed|form|input|button|link|meta|style|base|svg|math|title|template|noscript|frame|frameset|applet';

    public static function sanitize(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        // Drop dangerous elements together with their contents.
        $html = preg_replace('/<(' . self::BLOCK_TAGS . ')\b[^>]*>.*?<\/\1>/is', '', $html) ?? $html;
        // Drop the self-closing / unclosed variants left behind.
        $html = preg_replace('/<(' . self::BLOCK_TAGS . ')\b[^>]*\/?>/is', '', $html) ?? $html;

        // Strip inline event handlers (onclick, onerror, onload, ...).
        $html = preg_replace('/\s+on\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;

        // Strip all inline style attributes (CSS can be used for phishing/exfil).
        $html = preg_replace('/\s+style\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;

        // Strip dangerous URL schemes in href/src (javascript:, data:, vbscript:, file:).
        $scheme = '(?:javascript|data|vbscript|file)';
        $html = preg_replace(
            '/\s(href|src)\s*=\s*("\s*' . $scheme . ':[^"]*"|\'\s*' . $scheme . ':[^\']*\'|\s*' . $scheme . ':[^\s>]*)/i',
            '',
            $html
        ) ?? $html;

        return strip_tags($html, '<' . implode('><', self::ALLOWED_TAGS) . '>');
    }
}
