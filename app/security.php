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

/** Release session lock on read-only requests after saving CSRF and flash. */
function howhub_release_read_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) return;
    csrf_token();
    if (isset($_SESSION['flash'])) {
        $GLOBALS['howhub_read_flash'] = $_SESSION['flash'];
        unset($_SESSION['flash']);
    }
    session_write_close();
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

// Only deliberately supported display properties survive HTML editing or model output.
// Never trust arbitrary style/class strings from pasted HTML or AI completions.
function safe_editor_style(string $style, string $tag): string
{
    if (strlen($style) > 1500) return '';
    $styles = [];
    $tags = ['p','h2','h3','h4','strong','b','em','i','span','div','blockquote','li','figcaption','td','th','figure','img'];
    if (!in_array($tag, $tags, true)) return '';
    foreach (explode(';', $style) as $piece) {
        if (!str_contains($piece, ':')) continue;
        [$property, $value] = array_map('trim', explode(':', $piece, 2));
        $property = strtolower($property);
        $value = strtolower($value);
        if (str_contains($value, '!') || str_contains($value, '(') && !preg_match('/^rgb\(\d{1,3},\s*\d{1,3},\s*\d{1,3}\)$/', $value)) continue;
        if (in_array($property, ['color', 'background-color', 'border-left-color'], true)) {
            if (preg_match('/^#[0-9a-f]{3}(?:[0-9a-f]{3})?$/', $value) || preg_match('/^rgb\((\d{1,3}),\s*(\d{1,3}),\s*(\d{1,3})\)$/', $value, $m) && max((int)$m[1], (int)$m[2], (int)$m[3]) <= 255) {
                $styles[$property] = $value;
            }
        } elseif ($property === 'font-size' && preg_match('/^(1[2-9]|2\d|3[0-6])px$/', $value)) {
            $styles[$property] = $value;
        } elseif ($property === 'font-weight' && in_array($value, ['400','500','600','700','800','bold'], true)) {
            $styles[$property] = $value;
        } elseif ($property === 'line-height' && preg_match('/^(1(?:\.[0-9])?|2(?:\.[0-2])?)$/', $value) && (float)$value >= 1) {
            $styles[$property] = $value;
        } elseif ($property === 'text-align' && in_array($value, ['left','right','center','justify'], true)) {
            $styles[$property] = $value;
        } elseif ($property === 'width' && $tag === 'img' && preg_match('/^(?:[3-9]\d|100)%$/', $value)) {
            $styles[$property] = $value;
        }
    }
    return implode(';', array_map(static fn($key, $value) => "$key:$value", array_keys($styles), $styles));
}

function safe_editor_class(string $class, string $tag): string
{
    $allowed = $tag === 'img' ? ['guide-img-small', 'guide-img-medium'] :
        ['hh-note', 'hh-tip', 'hh-warning', 'hh-step', 'hh-lead', 'hh-accent', 'hh-highlight', 'hh-muted', 'hh-center'];
    $classes = preg_split('/\s+/', trim($class)) ?: [];
    return implode(' ', array_slice(array_values(array_unique(array_intersect($classes, $allowed))), 0, 3));
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
            $safeClass = safe_editor_class($child->getAttribute('class'), $tag);
            $safeStyle = safe_editor_style($child->getAttribute('style'), $tag);
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
            if ($safeClass !== '') $child->setAttribute('class', $safeClass);
            if ($safeStyle !== '') $child->setAttribute('style', $safeStyle);
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
