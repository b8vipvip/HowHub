<?php
declare(strict_types=1);

function import_add_image(array &$state, string $src, string $alt = '', string $href = ''): void
{
    if (count($state['images']) >= 60) throw new InvalidArgumentException('一次最多导入 60 张图片，请分段操作');
    $marker = '[[IMAGE_' . (count($state['images']) + 1) . ']]';
    $state['images'][] = ['marker'=>$marker,'src'=>$src,'alt'=>mb_substr($alt, 0, 160),'href'=>$href];
    $state['output'] .= "\n" . $marker . "\n";
}

/** Walk unknown editor tags recursively; protect original URL identity with markers. */
function import_walk(DOMNode $node, array &$state, string $imageLink = ''): void
{
    if ($node instanceof DOMText) {
        $state['output'] .= str_replace('[[', '［［', preg_replace('/\s+/u', ' ', $node->textContent) ?? '');
        return;
    }
    if (!$node instanceof DOMElement) {
        foreach ($node->childNodes as $child) import_walk($child, $state, $imageLink);
        return;
    }
    $tag = strtolower($node->tagName);
    if (in_array($tag, ['iframe','canvas','video','audio','svg','object','embed','noscript','template'], true)) {
        $state['unsupported'][$tag] = ($state['unsupported'][$tag] ?? 0) + 1;
        return;
    }
    if (in_array($tag, ['script','style','noscript','template','head','form','button','textarea',
                        'select','nav','footer','aside','header','meta','link'], true)) return;
    if ($node->hasAttribute('hidden') || strtolower($node->getAttribute('aria-hidden')) === 'true' ||
        preg_match('/(?:^|;)\s*display\s*:\s*none\b/i', $node->getAttribute('style'))) return;
    if ($tag === 'a') {
        $raw = $node->getAttribute('href');
        $url = import_resolve_url($raw, $state['base']);
        if ($node->getElementsByTagName('img')->length > 0) {
            if ($raw !== '' && $url === '' && !str_starts_with($raw, '#') &&
                !preg_match('~^(?:javascript|data|mailto|tel):~i', $raw)) $state['missing_links']++;
            foreach ($node->childNodes as $child) import_walk($child, $state, $url);
            return;
        }
        $label = mb_substr(trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? ''), 0, 240);
        if ($url !== '' && $label !== '') {
            if (count($state['links']) >= 100) throw new InvalidArgumentException('一次最多导入 100 个链接，请分段操作');
            $marker = '[[LINK_' . (count($state['links']) + 1) . ']]';
            $state['links'][] = ['marker'=>$marker,'href'=>$url,'text'=>$label];
            $state['output'] .= $marker;
        } else {
            if ($raw !== '' && $url === '' && !str_starts_with($raw, '#') &&
                !preg_match('~^(?:javascript|data|mailto|tel):~i', $raw)) $state['missing_links']++;
            foreach ($node->childNodes as $child) import_walk($child, $state);
        }
        return;
    }
    if ($tag === 'picture') {
        $img = $node->getElementsByTagName('img')->item(0);
        if ($img instanceof DOMElement) import_walk($img, $state, $imageLink);
        else {
            $src = '';
            foreach ($node->getElementsByTagName('source') as $source) {
                $src = import_first_image(import_image_candidates($source), $state['base']);
                if ($src !== '') break;
            }
            if ($src === '') $state['missing_images']++;
            else import_add_image($state, $src, $node->getAttribute('aria-label'), $imageLink);
        }
        return;
    }
    if ($tag === 'source') return;
    if ($tag === 'img') {
        $src = import_first_image(import_image_candidates($node), $state['base']);
        if ($src === '') $state['missing_images']++;
        else import_add_image($state, $src, $node->getAttribute('alt'), $imageLink);
        return;
    }
    $blocks = ['p','div','section','article','h1','h2','h3','h4','h5','h6',
               'li','ol','ul','blockquote','figure','figcaption','tr','table','br',
               'ne-p','pre','hr','dl','dt','dd'];
    if (in_array($tag, ['h1','h2','h3','h4','h5','h6'], true)) $state['output'] .= "\n### ";
    elseif (in_array($tag, $blocks, true)) $state['output'] .= "\n";
    if ($tag === 'li') $state['output'] .= '- ';
    $backgrounds = import_background_candidates($node);
    if ($backgrounds) {
        $src = import_first_image($backgrounds, $state['base']);
        if ($src === '') $state['missing_images']++;
        else import_add_image($state, $src, $node->getAttribute('aria-label'), $imageLink);
    }
    foreach ($node->childNodes as $child) import_walk($child, $state, $imageLink);
    if (in_array($tag, $blocks, true)) $state['output'] .= "\n";
}
