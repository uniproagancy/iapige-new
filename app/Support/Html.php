<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

class Html
{
    protected const ALLOWED = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'span', 'div',
        'ul', 'ol', 'li', 'h2', 'h3', 'h4', 'h5', 'h6',
        'table', 'thead', 'tbody', 'tr', 'th', 'td',
        'a', 'img', 'blockquote', 'hr', 'small', 'sup', 'sub',
    ];

    protected const ALLOWED_ATTRIBUTES = [
        'a' => ['href', 'title'],
        'img' => ['src', 'alt', 'title', 'width', 'height'],
    ];

    protected const SCHEMES = ['http', 'https', 'mailto', 'tel'];

    public static function safe(?string $html): string
    {
        $html = trim((string) $html);

        if ($html === '') {
            return '';
        }

        $document = new DOMDocument('1.0', 'UTF-8');

        $previous = libxml_use_internal_errors(true);

        $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="iapi-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);
        $root = $document->getElementById('iapi-root');

        if (! $root) {
            return '';
        }

        foreach (iterator_to_array($xpath->query('//comment()')) as $comment) {
            $comment->parentNode?->removeChild($comment);
        }

        self::scrub($root);

        $out = '';

        foreach ($root->childNodes as $child) {
            $out .= $document->saveHTML($child);
        }

        return trim($out);
    }

    protected static function scrub(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->nodeName);

                if (! in_array($tag, self::ALLOWED, true)) {
                    self::unwrap($child, in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'svg', 'math'], true));

                    continue;
                }

                self::scrubAttributes($child, $tag);
            }

            if ($child->hasChildNodes()) {
                self::scrub($child);
            }
        }
    }

    protected static function scrubAttributes(DOMElement $element, string $tag): void
    {
        $allowed = self::ALLOWED_ATTRIBUTES[$tag] ?? [];

        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->nodeName);

            if (! in_array($name, $allowed, true)) {
                $element->removeAttribute($attribute->nodeName);

                continue;
            }

            if (in_array($name, ['href', 'src'], true) && ! self::safeUrl($attribute->nodeValue)) {
                $element->removeAttribute($attribute->nodeName);
            }
        }

        if ($tag === 'a' && $element->hasAttribute('href')) {
            $element->setAttribute('rel', 'nofollow noopener');
        }
    }

    protected static function safeUrl(?string $url): bool
    {
        $url = trim((string) $url);

        if ($url === '' || str_starts_with($url, '/') || str_starts_with($url, '#')) {
            return $url !== '';
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, self::SCHEMES, true);
    }

    protected static function unwrap(DOMElement $element, bool $dropContent): void
    {
        $parent = $element->parentNode;

        if (! $parent) {
            return;
        }

        if (! $dropContent) {
            while ($element->firstChild) {
                $parent->insertBefore($element->firstChild, $element);
            }
        }

        $parent->removeChild($element);
    }
}
