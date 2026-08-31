<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

class EditorialHtml
{
    /** @var list<string> */
    private const ALLOWED_TAGS = [
        'p', 'br', 'h2', 'h3', 'h4', 'strong', 'em', 'b', 'i', 'ul', 'ol', 'li',
        'blockquote', 'a', 'img', 'table', 'thead', 'tbody', 'tr', 'th', 'td',
        'figure', 'figcaption', 'hr', 'span', 'iframe',
    ];

    public function sanitize(?string $html): string
    {
        $html = trim((string) $html);

        if ($html === '') {
            return '';
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><div id="editorial-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('editorial-root');

        if (! $root instanceof DOMElement) {
            return '';
        }

        $this->clean($root);

        $output = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $output .= $document->saveHTML($child);
        }

        return trim($output);
    }

    private function clean(DOMNode $node): void
    {
        $remove = [];

        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);

                if ($tag === 'script' || $tag === 'style') {
                    $remove[] = $child;
                    continue;
                }

                if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                    $this->unwrap($child);
                    continue;
                }

                $this->cleanAttributes($child, $tag);
                $this->clean($child);
            }
        }

        foreach ($remove as $dead) {
            $dead->parentNode?->removeChild($dead);
        }
    }

    private function cleanAttributes(DOMElement $element, string $tag): void
    {
        $allowed = match ($tag) {
            'a' => ['href', 'title', 'rel', 'target'],
            'img' => ['src', 'alt', 'width', 'height', 'loading'],
            'iframe' => ['src', 'title', 'width', 'height', 'allow', 'allowfullscreen', 'loading'],
            'td', 'th' => ['colspan', 'rowspan'],
            default => [],
        };

        $names = [];
        foreach ($element->attributes ?? [] as $attribute) {
            $names[] = $attribute->name;
        }

        foreach ($names as $name) {
            if (! in_array(strtolower($name), $allowed, true)) {
                $element->removeAttribute($name);
            }
        }

        if ($tag === 'a') {
            $href = trim($element->getAttribute('href'));
            if ($href === '' || preg_match('/^\s*javascript:/i', $href)) {
                $element->removeAttribute('href');
            }
            $element->setAttribute('rel', 'noopener noreferrer');
        }

        if ($tag === 'img') {
            $src = trim($element->getAttribute('src'));
            if ($src === '' || preg_match('/^\s*javascript:/i', $src)) {
                $element->parentNode?->removeChild($element);
            }
        }

        if ($tag === 'iframe') {
            $src = trim($element->getAttribute('src'));
            $allowed = preg_match('#^https://(www\.)?(youtube\.com|youtube-nocookie\.com)/embed/#i', $src) === 1
                || preg_match('#^https://player\.vimeo\.com/video/#i', $src) === 1;

            if (! $allowed) {
                $element->parentNode?->removeChild($element);

                return;
            }

            $element->setAttribute('loading', 'lazy');
        }
    }

    private function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;
        if ($parent === null) {
            return;
        }

        $this->clean($element);

        while ($element->firstChild) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }
}
