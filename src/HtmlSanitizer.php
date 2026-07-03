<?php

declare(strict_types=1);

namespace App;

class HtmlSanitizer
{
    private const ALLOWED_TAGS = [
        'p', 'br', 'div', 'span', 'a', 'img', 'table', 'thead', 'tbody', 'tr', 'th', 'td',
        'ul', 'ol', 'li', 'strong', 'em', 'b', 'i', 'u', 's', 'strike', 'del', 'sub', 'sup',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'blockquote', 'pre', 'hr', 'font',
    ];

    /**
     * Inline CSS properties allowed in a style="" attribute. Restricted to text
     * formatting only — nothing here can load an external resource or reposition
     * content, so it is safe even for untrusted (received) mail.
     */
    private const ALLOWED_STYLE_PROPS = [
        'color', 'background-color', 'font-family', 'font-size', 'font-weight',
        'font-style', 'font-variant', 'text-decoration', 'text-decoration-line',
        'text-align', 'text-transform', 'line-height', 'letter-spacing', 'white-space',
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

        // Filter inline styles down to the safe text-formatting whitelist rather
        // than dropping them wholesale, so colour/font/size/alignment/decoration
        // survive. Anything that can fetch a resource or reposition content
        // (url(), expression, position, ...) is discarded by sanitizeStyle().
        $html = preg_replace_callback(
            '/\s+style\s*=\s*("([^"]*)"|\'([^\']*)\')/i',
            static function (array $m): string {
                $css = self::sanitizeStyle($m[2] !== '' ? $m[2] : ($m[3] ?? ''));

                return $css === '' ? '' : ' style="' . $css . '"';
            },
            $html
        ) ?? $html;
        // Any remaining unquoted style="..." form is dropped outright.
        $html = preg_replace('/\s+style\s*=\s*[^\s"\'>]+/i', '', $html) ?? $html;

        // Strip dangerous URL schemes in href/src (javascript:, data:, vbscript:, file:).
        $scheme = '(?:javascript|data|vbscript|file)';
        $html = preg_replace(
            '/\s(href|src)\s*=\s*("\s*' . $scheme . ':[^"]*"|\'\s*' . $scheme . ':[^\']*\'|\s*' . $scheme . ':[^\s>]*)/i',
            '',
            $html
        ) ?? $html;

        return strip_tags($html, '<' . implode('><', self::ALLOWED_TAGS) . '>');
    }

    /**
     * Reduce a CSS declaration list to the whitelisted text-formatting
     * properties, dropping any declaration whose value could load a resource,
     * execute code, or break out of the attribute.
     */
    private static function sanitizeStyle(string $css): string
    {
        $safe = [];

        foreach (explode(';', $css) as $declaration) {
            $declaration = trim($declaration);
            if ($declaration === '' || !str_contains($declaration, ':')) {
                continue;
            }

            [$property, $value] = explode(':', $declaration, 2);
            $property = strtolower(trim($property));
            $value = trim($value);

            if ($value === '' || !in_array($property, self::ALLOWED_STYLE_PROPS, true)) {
                continue;
            }

            // Reject values that can fetch resources, run script, or escape the
            // attribute/style context. Single quotes are allowed (needed for
            // multi-word font-family names like 'Segoe UI'); the value is always
            // re-emitted inside a double-quoted attribute, so only a double quote
            // could break out.
            if (preg_match('/url\s*\(|expression|javascript:|@import|[<>{}"]|\/\*|\\\\/i', $value)) {
                continue;
            }

            $safe[] = $property . ': ' . $value;
        }

        return implode('; ', $safe);
    }
}
