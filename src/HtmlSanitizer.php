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

    public static function sanitize(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        $html = preg_replace('/<(script|iframe|object|embed|form|input|button|link|meta)\b[^>]*>.*?<\/\1>/is', '', $html) ?? $html;
        $html = preg_replace('/<(script|iframe|object|embed|form|input|button|link|meta)\b[^>]*\/?>/is', '', $html) ?? $html;
        $html = preg_replace('/\s+on\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;
        $html = preg_replace('/\s(href|src)\s*=\s*("\s*javascript:[^"]*"|\'\s*javascript:[^\']*\'|javascript:[^\s>]*)/i', '', $html) ?? $html;

        return strip_tags($html, '<' . implode('><', self::ALLOWED_TAGS) . '>');
    }
}
