<?php
declare(strict_types=1);

/**
 * Summarize independently retrieved primary documents by distinct host.
 * This is editorial evidence status, never certification of truth.
 * Search-index snippets and AI-only opinions cannot satisfy corroboration.
 */
function agent_fact_consensus_for_idea(PDO $pdo,int $ideaId,?int $now=null): array
{
    $now??=time();
    $days=min(180,max(7,(int)setting('agent_fact_evidence_days','30')));
    $cutoff=$now-$days*86400;
    $review=$pdo->prepare('SELECT issues_json,checked_at,status FROM agent_topic_quality WHERE idea_id=?');
    $review->execute([$ideaId]);$record=$review->fetch();
    if(!$record) return [];
    $claims=json_decode((string)$record['issues_json'],true)['claims']??[];
    if(!is_array($claims)) return [];
    $q=$pdo->prepare('SELECT d.source_host,d.source_url,d.source_id,d.claim_text,
        d.verdict,d.retrieved_at,d.id,s.enabled,s.origin
        FROM agent_fact_documents d
        LEFT JOIN agent_sources s ON s.id=d.source_id
        WHERE d.idea_id=? ORDER BY d.retrieved_at DESC,d.id DESC LIMIT 100');
    $q->execute([$ideaId]);$docs=$q->fetchAll();
    $summary=[];
    foreach(array_slice($claims,0,8) as $index=>$claim) {
        if(!is_array($claim) || !is_string($claim['claim']??null)) continue;
        $text=trim(mb_substr($claim['claim'],0,300));
        if($text==='') continue;
        $perHost=[];$historical=[];$stale=0;$untrusted=0;
        foreach($docs as $doc) {
            if($doc['claim_text']!==$text) continue;
            $host=strtolower((string)$doc['source_host']);
            $current=(int)$doc['retrieved_at']>=$cutoff &&
                (int)$doc['retrieved_at']<=$now+300;
            $approved=$doc['enabled']!==null && (int)$doc['enabled']===1
                && is_string($doc['origin']) && $doc['origin']!==''
                && agent_same_origin((string)$doc['source_url'],(string)$doc['origin']);
            if(!$current || !$approved) {
                $historical[]=['id'=>(int)$doc['id'],'url'=>$doc['source_url'],
                    'host'=>$host,'verdict'=>$doc['verdict'],
                    'reason'=>!$current?'outdated':'source_disabled'];
                if(!$current) $stale++;else $untrusted++;
                continue;
            }
            if($host==='') continue;
            // Only the most recent reviewed document for a host counts.
            if(!isset($perHost[$host])) {
                $perHost[$host]=['host'=>$host,'url'=>(string)$doc['source_url'],
                    'id'=>(int)$doc['id'],'verdict'=>(string)$doc['verdict'],
                    'retrieved_at'=>(int)$doc['retrieved_at']];
            }
        }
        $supports=[];$conflicts=[];$unclear=[];
        foreach($perHost as $host=>$item) {
            switch($item['verdict']) {
                case 'supports':$supports[]=$item;break;
                case 'conflicts':$conflicts[]=$item;break;
                default:$unclear[]=$item;break;
            }
        }
        $status=match(true) {
            count($conflicts)>0=>'conflicting_evidence',
            count($supports)>=2=>'cross_source_support',
            count($supports)===1=>'single_source_support',
            !$perHost && $stale>0=>'expired_evidence',
            default=>'insufficient_evidence',
        };
        $summary[]=['claim_index'=>(int)$index,'claim'=>$text,'status'=>$status,
            'support_count'=>count($supports),'conflict_count'=>count($conflicts),
            'unclear_count'=>count($unclear),'expired_count'=>$stale,
            'disabled_count'=>$untrusted,'valid_days'=>$days,
            'sources'=>array_values($perHost),'historical'=>$historical];
    }
    return $summary;
}
