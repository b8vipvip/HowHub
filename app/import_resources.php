<?php
declare(strict_types=1);

/** Find image URLs in generic CMS, lazy-loaded and responsive HTML. */
function import_image_candidates(DOMElement $node): array
{
    $urls = [];
    foreach (['data-original','data-original-src','data-full-src','data-actualsrc',
              'data-src','data-lazy-src','data-lazy','data-image-src','data-url',
              'data-image','data-fallback-src'] as $key) {
        if ($node->hasAttribute($key)) $urls[] = $node->getAttribute($key);
    }
    foreach (['data-srcset','srcset'] as $key) {
        if ($node->hasAttribute($key)) array_push($urls, ...import_srcset($node->getAttribute($key)));
    }
    if ($node->hasAttribute('src')) $urls[] = $node->getAttribute('src');
    if (strtolower($node->tagName) === 'img' && $node->parentNode instanceof DOMElement &&
        strtolower($node->parentNode->tagName) === 'picture') {
        foreach ($node->parentNode->childNodes as $child) {
            if (!$child instanceof DOMElement || strtolower($child->tagName) !== 'source') continue;
            foreach (['srcset','data-srcset'] as $key) {
                if ($child->hasAttribute($key)) array_push($urls, ...import_srcset($child->getAttribute($key)));
            }
        }
    }
    return $urls;
}

function import_background_candidates(DOMElement $node): array
{
    $urls = [];
    foreach (['data-background-image','data-background','data-bg','data-bg-src','background'] as $key) {
        if ($node->hasAttribute($key)) $urls[] = $node->getAttribute($key);
    }
    if (preg_match('~(?:^|;)\s*background(?:-image)?\s*:\s*[^;]*?url\(\s*([\'"]?)(.*?)\1\s*\)~i',
        $node->getAttribute('style'), $matches)) $urls[] = $matches[2];
    return $urls;
}

function import_first_image(array $values, string $base): string
{
    foreach ($values as $value) {
        $url = import_resolve_url((string)$value, $base, true);
        if ($url !== '') return $url;
    }
    return '';
}

function import_select_body(DOMDocument $doc): DOMNode
{
    $body = $doc->getElementsByTagName('body')->item(0);
    if (!$body) throw new InvalidArgumentException('网页 HTML 没有有效正文');
    $xpath = new DOMXPath($doc);
    foreach (['//article','//*[@itemprop="articleBody"]','//*[@role="main"]',
              '//*[contains(concat(" ", normalize-space(@class), " "), " entry-content ")]',
              '//*[contains(concat(" ", normalize-space(@class), " "), " markdown-body ")]',
              '//*[contains(concat(" ", normalize-space(@class), " "), " post-content ")]',
              '//main'] as $selector) {
        $matches = $xpath->query($selector);
        if ($matches === false) continue;
        foreach ($matches as $candidate) {
            if ($candidate instanceof DOMElement && mb_strlen(trim($candidate->textContent)) >= 16) return $candidate;
        }
    }
    return $body;
}
