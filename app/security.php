<?php
declare(strict_types=1);

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_input(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function check_csrf(): void
{
    if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals(csrf_token(), $_POST['csrf_token'])) {
        http_response_code(419);
        exit('会话已过期，请刷新页面重新提交。');
    }
}

function redirect_to(string $path): never
{
    header('Location: ' . $path, true, 303);
    exit;
}

function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = ['text' => $message, 'type' => $type];
}

function logged_in(): bool
{
    return isset($_SESSION['user_id']) && is_int($_SESSION['user_id']);
}

function require_admin(): void
{
    if (!logged_in()) {
        redirect_to('/admin/login');
    }
}

function safe_image(string $url): string
{
    $url = trim($url);
    if (str_starts_with($url, '/uploads/') && !str_contains($url, '..') && !str_contains($url, '\\')) {
        return $url;
    }
    if (preg_match('~^https?://[^\s<>"\x00-\x1F]+$~iu', $url)) {
        return $url;
    }
    return '';
}

function safe_link(string $url): string
{
    $url = trim($url);
    if (str_starts_with($url, '/') && !str_starts_with($url, '//') && !str_contains($url, '\\')) {
        return $url;
    }
    return preg_match('~^https?://[^\s<>"\x00-\x1F]+$~iu', $url) ? $url : '';
}

function sanitize_content(string $html): string
{
    if (!class_exists(DOMDocument::class)) {
        throw new RuntimeException('PHP 未启用 dom 扩展，无法安全保存富文本。');
    }
    $html = substr($html, 0, 300000);
    $doc = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><html><body><div id="hh-root">' . $html . '</div></body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $root = $doc->getElementById('hh-root');
    if (!$root) {
        return '';
    }
    $allowed = array_fill_keys(['p', 'h2', 'h3', 'h4', 'strong', 'b', 'em', 'i', 'u', 's', 'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'a', 'img', 'figure', 'figcaption', 'hr', 'br', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'div', 'span'], true);
    $blocked = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'svg', 'math', 'video', 'audio', 'input', 'button', 'textarea', 'select', 'template'];
    $walk = function (DOMNode $node) use (&$walk, $allowed, $blocked): void {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (!$child instanceof DOMElement) {
                if ($child instanceof DOMComment) {
                    $node->removeChild($child);
                }
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, $blocked, true)) {
                $node->removeChild($child);
                continue;
            }
            $walk($child);
            if (!isset($allowed[$tag])) {
                while ($child->firstChild !== null) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }
            $href = $tag === 'a' ? safe_link($child->getAttribute('href')) : '';
            $src = $tag === 'img' ? safe_image($child->getAttribute('src')) : '';
            $alt = $tag === 'img' ? mb_substr($child->getAttribute('alt'), 0, 200) : '';
            while ($child->attributes->length > 0) {
                $child->removeAttributeNode($child->attributes->item(0));
            }
            if ($tag === 'a' && $href !== '') {
                $child->setAttribute('href', $href);
                $child->setAttribute('rel', 'noopener noreferrer nofollow');
                $child->setAttribute('target', '_blank');
            }
            if ($tag === 'img') {
                if ($src === '') {
                    $node->removeChild($child);
                    continue;
                }
                $child->setAttribute('src', $src);
                $child->setAttribute('alt', $alt);
                $child->setAttribute('loading', 'lazy');
            }
        }
    };
    $walk($root);
    $out = '';
    foreach ($root->childNodes as $child) {
        $out .= $doc->saveHTML($child);
    }
    return $out;
}

function render_with_toc(string $html): array
{
    $html = sanitize_content($html);
    $doc = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><html><body><div id="reader">' . $html . '</div></body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $container = $doc->getElementById('reader');
    $toc = [];
    if (!$container) {
        return [$html, $toc];
    }
    $xp = new DOMXPath($doc);
    foreach ($xp->query('.//h2 | .//h3', $container) as $heading) {
        $id = 'section-' . (count($toc) + 1);
        $heading->setAttribute('id', $id);
        $toc[] = ['id' => $id, 'label' => trim($heading->textContent), 'depth' => strtolower($heading->tagName)];
    }
    $out = '';
    foreach ($container->childNodes as $child) {
        $out .= $doc->saveHTML($child);
    }
    return [$out, $toc];
}
