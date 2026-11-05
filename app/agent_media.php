<?php
declare(strict_types=1);

/** A single licensed media request per tick, never an unlimited image fan-out. */
function agent_process_media(PDO $pdo, array $source, array $media, ?callable $fixture = null): array
{
    $url=$media['url'];
    $id=(int)$media['id'];
    if ($source['rights_mode']!=='licensed' || trim($source['rights_evidence'])==='' ||
        !agent_same_origin($url,$source['origin'])) {
        $pdo->prepare("UPDATE agent_media SET status='skipped',last_message='未获许可或媒体跨域' WHERE id=?")
            ->execute([$id]);
        return ['status'=>'skipped'];
    }
    $gate=agent_robots_gate($pdo,$source,$url,$fixture);
    if (!$gate['ready']) return ['status'=>'robots','detail'=>$gate['message']];
    if (!$gate['allowed']) {
        $pdo->prepare("UPDATE agent_media SET status='skipped',last_message='robots.txt 禁止' WHERE id=?")
            ->execute([$id]);
        return ['status'=>'blocked'];
    }
    $budget=agent_rate_take($pdo,$source,$url,'media',(int)($gate['delay']??0));
    if (!$budget['ok']) return ['status'=>'cooldown','detail'=>$budget['reason']];
    try {
        $downloader=$fixture ? static function (string $item) use ($fixture): string {
            $r=$fixture($item);
            if (!is_array($r) || (int)($r['status']??0)!==200) throw new RuntimeException('媒体下载失败');
            return (string)$r['body'];
        } : static fn(string $item):string=>media_remote_bytes($item,AGENT_UA);
        $local=media_import_remote_image($pdo,$url,$downloader);
        $pdo->beginTransaction();
        try {
            $stmt=$pdo->prepare('SELECT body_html FROM agent_candidates WHERE id=?');
            $stmt->execute([$media['candidate_id']]);
            $html=(string)$stmt->fetchColumn();
            // Replace original, already-escaped HTML attribute URL safely.
            $html=str_replace(h($url),h($local),$html);
            $pdo->prepare('UPDATE agent_candidates SET body_html=? WHERE id=?')
                ->execute([sanitize_content($html),$media['candidate_id']]);
            $pdo->prepare("UPDATE agent_media SET status='done',local_url=?,attempts=attempts+1 WHERE id=?")
                ->execute([$local,$id]);
            $pdo->commit();
        } catch (Throwable $e) {$pdo->rollBack();throw $e;}
        agent_event($pdo,'media','success','已转存原图到素材库',$source['id'],$media['candidate_id']);
        return ['status'=>'transferred'];
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE agent_media SET status='skipped',attempts=attempts+1,last_message=? WHERE id=?")
            ->execute([mb_substr($e->getMessage(),0,250),$id]);
        agent_backoff($pdo,$source,$e->getMessage());
        return ['status'=>'error','detail'=>$e->getMessage()];
    }
}
