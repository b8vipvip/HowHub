<?php
declare(strict_types=1);

/**
 * Inline data:image sources have no downloadable URL. Import these before
 * calling the LLM so the model only receives numbered media placeholders.
 */
function import_prepare_embedded(PDO $pdo, array $source): array
{
    $known = [];
    $count = 0;
    foreach ($source['images'] as &$image) {
        $src = (string)$image['src'];
        if (!str_starts_with($src, 'data:')) continue;
        if (!isset($known[$src])) $known[$src] = media_import_data_image($pdo, $src);
        $image['src'] = $known[$src];
        $count++;
    }
    unset($image);
    $source['embedded_count'] = $count;
    return $source;
}
