<?php
declare(strict_types=1);

/**
 * Optional safe PNG renderer: convert a strictly parsed, bounded subset of
 * Mermaid *into freshly constructed* Graphviz DOT. The original Mermaid
 * source is NEVER passed to the Graphviz executable.
 */
function agent_diagram_tables(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS agent_diagram_images (
 idea_id INTEGER PRIMARY KEY REFERENCES agent_topic_ideas(id) ON DELETE CASCADE,
 source_sha256 TEXT NOT NULL,
 image_url TEXT NOT NULL,
 status TEXT NOT NULL DEFAULT 'complete' CHECK(status IN ('complete','skipped','failed')),
 generated_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS agent_diagram_runs (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 idea_id INTEGER NOT NULL,
 requested_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS agent_diagram_runs_day ON agent_diagram_runs(requested_at);
SQL);
}

function agent_diagram_dot_escape(string $label): string
{
    if(mb_strlen($label)>90 || !preg_match('//u',$label)) throw new RuntimeException('图节点标题无效');
    return (string)json_encode($label,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}

function agent_diagram_graph_dot(string $code): string
{
    if(strlen($code)>7000 || !preg_match('//u',$code)) return '';
    $lines=preg_split('/[;\r\n]+/u',trim($code))?:[];
    $header=trim(array_shift($lines)??'');
    if(!preg_match('/^graph\s+(TD|TB|LR|RL)$/i',$header,$type)) return '';
    $nodes=[];$edges=[];$seen=[];
    $pattern='/^([A-Za-z][A-Za-z0-9_]{0,23})(?:\[([^\[\]{}()<>]{1,90})\]|\{([^\[\]{}()<>]{1,90})\}|\(([^\[\]{}()<>]{1,90})\))?$/u';
    foreach($lines as $line) {
        $line=trim($line);if($line==='') continue;
        $terms=preg_split('/\s*-->\s*/u',$line);
        if(!$terms || count($terms)<2 || count($terms)>6) return '';
        $chain=[];
        foreach($terms as $term) {
            if(!preg_match($pattern,trim($term),$m)) return '';
            $id=$m[1];
            $label=trim((string)($m[2]??'') ?: (string)($m[3]??'') ?: (string)($m[4]??'') ?: $id);
            if($label==='') return '';
            $shape=($m[3]??'')!==''?'diamond':'box';
            if(!isset($nodes[$id])) $nodes[$id]=['label'=>$label,'shape'=>$shape];
            elseif($label!==$id && $nodes[$id]['label']!==$id && $nodes[$id]['label']!==$label) return '';
            elseif($nodes[$id]['label']===$id && $label!==$id) $nodes[$id]=['label'=>$label,'shape'=>$shape];
            $chain[]=$id;
        }
        for($i=1;$i<count($chain);$i++) {
            if($chain[$i-1]===$chain[$i]) return '';
            $key=$chain[$i-1].':'.$chain[$i];
            if(!isset($seen[$key])) {$edges[]=[$chain[$i-1],$chain[$i]];$seen[$key]=true;}
        }
        if(count($nodes)>30 || count($edges)>40) return '';
    }
    if(count($nodes)<2 || !$edges) return '';
    $rank=match(strtoupper($type[1])) {'LR'=>'LR','RL'=>'RL',default=>'TB'};
    $dot='digraph original { graph [rankdir='.$rank.',bgcolor="transparent",pad="0.25",nodesep="0.35",ranksep="0.55"];'.
        ' node [fontname="Noto Sans CJK SC",fontsize=15,shape=box,style="rounded,filled",fillcolor="#f5f9ff",color="#819ec8",fontcolor="#243b60"];'.
        ' edge [color="#5577a7",arrowsize=0.75,penwidth=1.5];';
    foreach($nodes as $id=>$node) {
        $dot.=$id.' [label='.agent_diagram_dot_escape($node['label']).
            ',shape='.$node['shape'].'];';
    }
    foreach($edges as [$from,$to]) $dot.=$from.' -> '.$to.';';
    return $dot.'}';
}

function agent_diagram_mindmap_dot(string $code): string
{
    if(strlen($code)>7000 || !preg_match('//u',$code)) return '';
    $lines=preg_split('/\r?\n/',trim($code))?:[];
    if(strtolower(trim(array_shift($lines)??''))!=='mindmap') return '';
    $items=[];$stack=[];$root=0;
    foreach($lines as $line) {
        if(trim($line)==='') continue;
        if(!preg_match('/^( {1,16})(.{1,120})$/u',$line,$m)) return '';
        $indent=strlen($m[1]);$label=trim($m[2]);
        if(preg_match('/[<>\x60]|:::|^(?:click|class|style|script|end)\b/i',$label)) return '';
        if(preg_match('/^root\(\((.+)\)\)$/u',$label,$extract)) $label=trim($extract[1]);
        elseif(preg_match('/^\(\((.+)\)\)$/u',$label,$extract)) $label=trim($extract[1]);
        if(mb_strlen($label)>90 || $label==='') return '';
        while($stack && end($stack)['indent']>=$indent) array_pop($stack);
        $depth=count($stack);
        if($depth===0 && $items) return '';
        if($depth>5) return '';
        $index=count($items);
        $parent=$stack ? end($stack)['id'] : null;
        $items[]=['id'=>'N'.$index,'label'=>$label,'parent'=>$parent,'depth'=>$depth];
        $stack[]=['indent'=>$indent,'id'=>'N'.$index];
        if(count($items)>35) return '';
    }
    if(count($items)<2) return '';
    $dot='digraph original {graph [rankdir=LR,bgcolor="transparent",pad="0.25",nodesep="0.3",ranksep="0.5"];'.
         'node [fontname="Noto Sans CJK SC",shape=box,style="rounded,filled",fillcolor="#f5f9ff",color="#819ec8"];'.
         'edge [color="#5577a7",arrowsize=0.65];';
    foreach($items as $item) {
        $dot.=$item['id'].' [label='.agent_diagram_dot_escape($item['label']).
            ($item['depth']===0?',fillcolor="#dceaff"':'').'];';
        if($item['parent']!==null) $dot.=$item['parent'].' -> '.$item['id'].';';
    }
    return $dot.'}';
}

function agent_diagram_dot(string $definition): string
{
    $graph=agent_diagram_graph_dot($definition);
    return $graph!==''?$graph:agent_diagram_mindmap_dot($definition);
}

function agent_diagram_png(string $definition,?callable $renderer=null): string
{
    $dot=agent_diagram_dot($definition);
    if($dot==='') throw new RuntimeException('Graphviz 仅支持白名单内的分支流程与缩进思维导图');
    if($renderer!==null) {
        $bytes=$renderer($dot);
        if(!is_string($bytes)) throw new RuntimeException('图像工具未返回图片字节');
        return $bytes;
    }
    $binary=url_import_bin('dot');
    if($binary==='') throw new RuntimeException('需要服务器安装 Graphviz（dot），图形仍可用现有 HTML 展示');
    $directory=sys_get_temp_dir().'/hh-graphviz-'.bin2hex(random_bytes(12));
    if(!mkdir($directory,0700)) throw new RuntimeException('无法创建受限图形工作目录');
    $path=$directory.'/diagram.dot';
    try {
        if(file_put_contents($path,$dot,LOCK_EX)!==strlen($dot)) throw new RuntimeException('无法保存安全的 DOT 输入');
        $bytes=url_import_process([$binary,'-Tpng','-Gdpi=120',$path],18,3*1024*1024);
        if(!str_starts_with($bytes,"\x89PNG\r\n\x1a\n") || !@getimagesizefromstring($bytes)) {
            throw new RuntimeException('Graphviz 没有返回有效的 PNG 图像');
        }
        return $bytes;
    } finally {
        @unlink($path);
        @rmdir($directory);
    }
}

/** Extract from stored, already sanitized original article HTML. */
function agent_diagram_source_from_html(string $html): string
{
    $doc=new DOMDocument('1.0','UTF-8');
    $old=libxml_use_internal_errors(true);
    try {
        $doc->loadHTML('<?xml encoding="UTF-8"><html><body>'.substr($html,0,75000).'</body></html>',
            LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);
    } finally {libxml_clear_errors();libxml_use_internal_errors($old);}
    $xp=new DOMXPath($doc);
    foreach($xp->query('//pre/code')?:[] as $code) {
        $text=trim($code->textContent);
        if(agent_diagram_dot($text)!=='') return $text;
    }
    return '';
}

function agent_diagram_process(PDO $pdo,int $ideaId,?callable $renderer=null): array
{
    $q=$pdo->prepare("SELECT id,status,html FROM agent_topic_ideas WHERE id=? AND status='review'");
    $q->execute([$ideaId]);$idea=$q->fetch();
    if(!$idea) return ['status'=>'idle'];
    $definition=agent_diagram_source_from_html((string)$idea['html']);
    if($definition==='') return ['status'=>'not_supported'];
    $hash=hash('sha256',$definition);
    $q=$pdo->prepare('SELECT image_url,source_sha256,status FROM agent_diagram_images WHERE idea_id=?');
    $q->execute([$ideaId]);$existing=$q->fetch();
    if($existing && $existing['source_sha256']===$hash && $existing['status']==='complete') {
        return ['status'=>'complete','url'=>$existing['image_url'],'reused'=>true];
    }
    $bytes=agent_diagram_png($definition,$renderer);
    $url=media_store_import_bytes($pdo,$bytes);
    $figure='<figure class="hh-diagram-raster"><img src="'.h($url).'" alt="原创教程结构化流程图 PNG">'.
        '<figcaption>由本站安全 Graphviz 渲染的原创流程关系图；编辑器仍保留原始 Mermaid 源码</figcaption></figure>';
    $html=sanitize_content((string)$idea['html'].$figure);
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE agent_topic_ideas SET html=? WHERE id=? AND status=?')
            ->execute([$html,$ideaId,'review']);
        $pdo->prepare("INSERT INTO agent_diagram_images (idea_id,source_sha256,image_url,status,generated_at)
            VALUES(?,?,?,'complete',?) ON CONFLICT(idea_id) DO UPDATE SET
            source_sha256=excluded.source_sha256,image_url=excluded.image_url,status='complete',
            generated_at=excluded.generated_at")
            ->execute([$ideaId,$hash,$url,time()]);
        $pdo->commit();
        return ['status'=>'complete','url'=>$url,'reused'=>false];
    } catch(Throwable $e) {
        if($pdo->inTransaction()) $pdo->rollBack();
        $find=$pdo->prepare('SELECT id FROM media_assets WHERE url=?');$find->execute([$url]);
        $assetId=$find->fetchColumn();
        if($assetId) media_delete($pdo,(int)$assetId);
        throw $e;
    }
}

function agent_diagram_daily_reserve(PDO $pdo,int $ideaId,?int $now=null): bool
{
    $now??=time();
    $limit=min(8,max(0,(int)setting('agent_diagram_daily','4')));
    if(!$limit) return false;
    $open=false;
    try {
        $pdo->exec('BEGIN IMMEDIATE');$open=true;
        $q=$pdo->prepare('SELECT COUNT(*) FROM agent_diagram_runs WHERE requested_at>?');
        $q->execute([$now-86400]);
        if((int)$q->fetchColumn()>=$limit){$pdo->exec('COMMIT');$open=false;return false;}
        $pdo->prepare('INSERT INTO agent_diagram_runs(idea_id,requested_at) VALUES(?,?)')
            ->execute([$ideaId,$now]);
        $pdo->exec('COMMIT');$open=false;return true;
    } catch(Throwable $e) {
        if($open && $pdo->inTransaction()) $pdo->exec('ROLLBACK');
        throw $e;
    }
}

/** Persist negative result to avoid charging CPU repeatedly for the same source. */
function agent_diagram_record_failure(PDO $pdo,int $id,string $status): void
{
    if(!in_array($status,['skipped','failed'],true)) return;
    $pdo->prepare("INSERT INTO agent_diagram_images(idea_id,source_sha256,image_url,status,generated_at)
        VALUES(?,'','',?,?) ON CONFLICT(idea_id) DO UPDATE SET
        image_url='',status=excluded.status,generated_at=excluded.generated_at")
        ->execute([$id,$status,time()]);
}

function agent_diagram_next(PDO $pdo): ?array
{
    if(setting('agent_diagram_auto_enabled','0')!=='1' || url_import_bin('dot')==='') return null;
    $q=$pdo->query("SELECT i.id FROM agent_topic_ideas i
        LEFT JOIN agent_diagram_images d ON d.idea_id=i.id
        LEFT JOIN agent_topic_quality qa ON qa.idea_id=i.id
        WHERE i.status='review' AND d.idea_id IS NULL
          AND (qa.status IS NULL OR qa.status IN ('reviewed','waived'))
          AND (i.html LIKE '%graph TD%' OR i.html LIKE '%graph LR%'
               OR i.html LIKE '%mindmap%')
        ORDER BY i.id LIMIT 1");
    $id=$q->fetchColumn();
    if(!$id || !agent_diagram_daily_reserve($pdo,(int)$id)) return null;
    try {
        $out=agent_diagram_process($pdo,(int)$id);
        if(($out['status']??'')==='not_supported') {
            agent_diagram_record_failure($pdo,(int)$id,'skipped');
        }
        return ['action'=>'diagram_png','topic_id'=>(int)$id]+$out;
    } catch(Throwable $e) {
        agent_diagram_record_failure($pdo,(int)$id,'failed');
        agent_event($pdo,'diagram_png','error',get_class($e).' 图形渲染失败');
        return ['action'=>'diagram_png','topic_id'=>(int)$id,'status'=>'failed'];
    }
}
