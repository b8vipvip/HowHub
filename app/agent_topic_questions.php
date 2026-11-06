<?php
declare(strict_types=1);

/**
 * Only short, standalone questions from search snippets / feed descriptions.
 * The full excerpt is discarded, never stored or sent to the writing model.
 */
function agent_topic_questions(string $excerpt): array
{
    $excerpt=mb_substr($excerpt,0,2000);
    $excerpt=html_entity_decode(strip_tags($excerpt),ENT_QUOTES|ENT_HTML5,'UTF-8');
    $excerpt=preg_replace('/[\x00-\x1F\x7F]+/u',' ',$excerpt) ?? '';
    $excerpt=trim(preg_replace('/\s+/u',' ',$excerpt) ?? '');
    if ($excerpt==='') return [];
    $candidates=[];
    preg_match_all('/(?:^|[。.!！;；])\s*([^。.!！?？;；]{6,170}[?？])/u',$excerpt,$matches);
    foreach(($matches[1]??[]) as $question) {
        $question=trim((string)$question);
        if (mb_strlen($question)<8||mb_strlen($question)>165) continue;
        if (!preg_match('/^(?:怎么|如何|怎样|为什么|能否|是否|哪些|什么|何时|怎样才能|如何才能|How\b|Why\b|What\b|When\b|Where\b|Can\b|Should\b|Is\b|Do\b)/iu',$question)) continue;
        $candidates[]=agent_topic_clean_title($question);
        if (count($candidates)>=2) break;
    }
    return array_values(array_unique($candidates));
}
