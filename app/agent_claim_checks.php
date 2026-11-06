<?php
declare(strict_types=1);

/**
 * An internal claim-to-search-snippet audit register.
 * The word "corroborating" means ONLY that index snippets appeared to agree;
 * it is never presented as independently verified truth.
 */
function agent_topic_claim_checks(array $raw,array $evidence): array
{
    $input=$raw['claims']??[];
    if (!is_array($input)) return [];
    $claims=[];
    foreach(array_slice($input,0,8) as $item) {
        if (!is_array($item)) continue;
        $claim=trim((string)($item['claim']??''));
        if (mb_strlen($claim)<8) continue;
        $claim=mb_substr(strip_tags($claim),0,300);
        $why=mb_substr(strip_tags((string)($item['reason']??'')),0,240);
        $finding=(string)($item['finding']??'unverified');
        if (!in_array($finding,['corroborating_snippets','conflicting_snippets','unverified'],true)) $finding='unverified';
        $indexes=[];
        $domains=[];
        foreach(array_slice(is_array($item['source_indexes']??null)?$item['source_indexes']:[],0,5) as $index) {
            if (!is_int($index) || $index<1 || $index>count($evidence)) continue;
            if (!in_array($index,$indexes,true)) {
                $indexes[]=$index;
                $domains[]=(string)($evidence[$index-1]['domain']??'');
            }
        }
        $distinct=count(array_unique(array_filter($domains)));
        // Never let a single unverifiable or repeated-domain snippet be
        // presented as multi-source corroboration.
        if ($finding==='corroborating_snippets' && $distinct<2) $finding='unverified';
        if ($finding==='conflicting_snippets' && !$indexes) $finding='unverified';
        if ($finding==='unverified') $indexes=[];
        $claims[]=[
            'claim'=>$claim,
            'finding'=>$finding,
            'source_indexes'=>$indexes,
            'reason'=>$why,
        ];
    }
    return $claims;
}

function agent_topic_claims_need_review(array $claims): bool
{
    foreach($claims as $claim) {
        if (($claim['finding']??'unverified')!=='corroborating_snippets') return true;
    }
    return false;
}
