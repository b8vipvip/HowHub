<?php
declare(strict_types=1);

/**
 * One provider request per scheduler tick. Search API results are indexed
 * discovery evidence; AI-only results are marked unverified speculation.
 */
function agent_research_tick(PDO $pdo, ?callable $searchFixture = null,
    ?callable $aiFixture = null, ?int $now = null): array
{
    $now??=time();
    if (setting('agent_enabled','0')!=='1' || setting('agent_auto_source_enabled','0')!=='1') {
        return ['status'=>'disabled'];
    }
    $method=agent_research_api_configured() || $searchFixture!==null ? 'search_api' : 'ai_suggestion';
    if ($method==='ai_suggestion' && !ai_config()['configured'] && $aiFixture===null) {
        return ['status'=>'unconfigured','message'=>'请先配置 AI 供应商或 Brave Search API'];
    }
    $begun=false;
    try {
        $pdo->exec('BEGIN IMMEDIATE');$begun=true;
        $count=$pdo->prepare('SELECT COUNT(*) FROM agent_research_runs WHERE created_at>?');
        $count->execute([$now-86400]);
        if ((int)$count->fetchColumn()>=4) {
            $pdo->exec('COMMIT');$begun=false;
            return ['status'=>'budget','message'=>'已达到每日 4 次搜索/推荐预算'];
        }
        $stmt=$pdo->prepare('SELECT * FROM agent_research_topics
            WHERE enabled=1 AND next_run<=? ORDER BY last_run ASC,id ASC LIMIT 1');
        $stmt->execute([$now]);
        $topic=$stmt->fetch();
        if (!$topic) {
            $pdo->exec('COMMIT');$begun=false;
            return ['status'=>'idle','message'=>'搜索主题均在冷却期或已暂停'];
        }
        // Reserve even on failure: never retry a failed upstream call in a loop.
        $pdo->prepare('UPDATE agent_research_topics SET last_run=?,next_run=? WHERE id=?')
            ->execute([$now,$now+259200,$topic['id']]);
        $run=$pdo->prepare("INSERT INTO agent_research_runs(topic_id,method,created_at,result)
            VALUES (?,?,?,'started')");
        $run->execute([$topic['id'],$method,$now]);
        $runId=(int)$pdo->lastInsertId();
        $pdo->exec('COMMIT');$begun=false;
    } catch (Throwable $e) {
        if ($begun) $pdo->exec('ROLLBACK');
        throw $e;
    }
    $added=0;
    try {
        if ($method==='search_api') {
            $query=agent_research_search_query($topic,$aiFixture);
            $data=agent_research_brave_search($query,$searchFixture);
            $results=$data['web']['results'] ?? null;
            if (!is_array($results)) throw new RuntimeException('搜索 API 缺少 web.results 列表');
            foreach (array_slice($results,0,12) as $entry) {
                if (!is_array($entry)) continue;
                if (setting('agent_creation_mode','topic_only')==='topic_only') {
                    agent_topic_enqueue($pdo,(string)($entry['title']??''),(string)($entry['url']??''),
                        'search_api',(string)$topic['topic'],(string)$topic['language']);
                    foreach(agent_topic_questions((string)($entry['description']??'')) as $question) {
                        agent_topic_enqueue($pdo,$question,(string)($entry['url']??''),
                            'search_question',(string)$topic['topic'],(string)$topic['language']);
                    }
                }
                if (agent_research_propose($pdo,(string)($entry['url']??''),
                    (string)($entry['title']??''),(string)($entry['description']??''),
                    $topic['topic'],$topic['language'],'search_api',
                    '搜索 API 返回：尚未验证来源使用条款及转载许可')) $added++;
            }
        } else {
            $query='AI 推荐候选域名（未联网核实）';
            $sites=agent_research_ai_suggestions($topic,$aiFixture);
            foreach ($sites as $entry) {
                if (!is_array($entry)) continue;
                if (agent_research_propose($pdo,(string)($entry['url']??''),
                    (string)($entry['name']??''),(string)($entry['reason']??''),
                    $topic['topic'],$topic['language'],'ai_suggestion',
                    '纯 AI 推荐，可能不存在；管理员须人工核实')) $added++;
            }
        }
        $pdo->prepare("UPDATE agent_research_runs SET query=?,results=?,result='success' WHERE id=?")
            ->execute([mb_substr($query,0,260),$added,$runId]);
        agent_event($pdo,'source_research','success',$method.'，新增 '.$added.' 个待审核站点');
        return ['status'=>'proposed','method'=>$method,'topic'=>$topic['topic'],'added'=>$added];
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE agent_research_runs SET result='error' WHERE id=?")->execute([$runId]);
        agent_event($pdo,'source_research','error',get_class($e).' 查询未完成');
        return ['status'=>'error','message'=>$e->getMessage()];
    }
}
