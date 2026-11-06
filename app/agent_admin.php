<?php
declare(strict_types=1);

/** All actions require an authenticated admin and CSRF, enforced by router. */
function agent_admin_action(PDO $pdo, array $post): string
{
    $action=(string)($post['action']??'');
    $id=max(0,(int)($post['id']??0));
    if ($action==='audio_settings') {
        $model=trim((string)($post['model']??'whisper-1'));
        if (!preg_match('/^[a-zA-Z0-9._:+-]{1,100}$/D',$model)) throw new InvalidArgumentException('音频模型 ID 格式错误');
        set_setting('agent_stt_enabled',($post['enabled']??'')==='1'?'1':'0');
        set_setting('agent_stt_model',$model);
        return '视频音频转写选项已保存，仅对有明确许可的直链视频生效';
    }
    if (str_starts_with($action,'research_')) return agent_research_admin_action($pdo,$post);
    if ($action==='global') {
        set_setting('agent_enabled',($post['enabled']??'')==='1'?'1':'0');
        return '全局自动发现开关已更新';
    }
    if ($action==='add') {
        $new=agent_add_source($pdo,(string)($post['site']??''),(string)($post['feed']??''),
            (string)($post['industry']??''),(string)($post['region']??''),'summary');
        return '来源 #'.$new.' 已登记，默认暂停。请确认站点使用条款与 robots.txt 后手动启用';
    }
    $source=null;
    if (in_array($action,['enable','rights','limits','delete'],true)) {
        $query=$pdo->prepare('SELECT * FROM agent_sources WHERE id=?');
        $query->execute([$id]);
        $source=$query->fetch();
        if (!$source) throw new InvalidArgumentException('来源记录不存在');
    }
    if ($action==='enable') {
        $enabled=($post['enabled']??'')==='1'?1:0;
        $pdo->prepare('UPDATE agent_sources SET enabled=?,blocked_until=0 WHERE id=?')
            ->execute([$enabled,$id]);
        agent_event($pdo,'source',$enabled?'success':'blocked',
            $enabled?'管理员手动启用':'管理员手动暂停',$id);
        return $enabled?'已启用来源，后续定时任务才会访问':'来源已暂停，不再安排新访问';
    }
    if ($action==='rights') {
        agent_set_rights($pdo,$id,(string)($post['mode']??'summary'),(string)($post['evidence']??''));
        return '转载许可政策已更新，请确认仍符合适用地区的法律与来源协议';
    }
    if ($action==='limits') {
        $interval=min(86400,max(300,(int)($post['interval']??1800)));
        $daily=min(20,max(1,(int)($post['daily']??8)));
        $pdo->prepare('UPDATE agent_sources SET min_interval=?,daily_limit=? WHERE id=?')
            ->execute([$interval,$daily,$id]);
        agent_event($pdo,'limits','success','每次间隔 '.$interval.'s，每日上限 '.$daily,$id);
        return '访问频率限制已更新';
    }
    if ($action==='delete') {
        $pdo->prepare('DELETE FROM agent_sources WHERE id=?')->execute([$id]);
        return '来源及未归档候选已删除，不影响已建立的教程草稿';
    }
    if ($action==='reject') {
        $pdo->prepare("UPDATE agent_candidates SET status='rejected' WHERE id=? AND status='review'")
            ->execute([$id]);
        agent_event($pdo,'review','blocked','管理员拒绝候选',0,$id);
        return '已拒绝候选';
    }
    if ($action==='approve') {
        $guideId=agent_approve_candidate($pdo,$id);
        return '已创建教程草稿 #'.$guideId.'，尚未发布。请在教程管理中核实内容';
    }
    if ($action==='request_tick') {
        set_setting('agent_run_requested','1');
        return '已请求下次定时调度处理一次，仍遵守 robots 与访问冷却限制';
    }
    throw new InvalidArgumentException('未知 Agent 管理操作');
}

function agent_admin_page(PDO $pdo): void
{
    $global=setting('agent_enabled','0')==='1';
    $sources=$pdo->query('SELECT * FROM agent_sources ORDER BY id DESC LIMIT 100')->fetchAll();
    $candidates=$pdo->query("SELECT c.*,s.origin,s.industry FROM agent_candidates c
        JOIN agent_sources s ON s.id=c.source_id ORDER BY c.id DESC LIMIT 60")->fetchAll();
    $events=$pdo->query('SELECT * FROM agent_events ORDER BY id DESC LIMIT 35')->fetchAll();
    $pending=$pdo->query("SELECT COUNT(*) FROM agent_candidates WHERE status='review'")->fetchColumn();
    $queued=$pdo->query("SELECT COUNT(*) FROM agent_candidates WHERE status='queued'")->fetchColumn();
    page_start('内容发现 Agent','',true);
    echo '<main class="shell admin-main">';
    admin_heading('CONTENT DISCOVERY','内容发现 Agent',
        '全球行业知识、教程、攻略和问答的合规低频发现、AI 初筛与人工审核。');
    echo '<section class="admin-panel"><h2>全局设置</h2><p class="muted">默认关闭。只读取管理员明确登记且手动启用的网站来源，不自动横向扩散到其他网站。不绕过登录、付费墙和访问限制。</p>';
    echo '<p>运行状态：<strong>'.($global?'已启用（需要服务器定时任务）':'已关闭').'</strong>　待审 '.(int)$pending.'　排队 '.(int)$queued.'</p>';
    echo '<form method="post" action="/admin/agent/action">'.csrf_input().'<input type="hidden" name="action" value="global">';
    echo '<input type="hidden" name="enabled" value="'.($global?'0':'1').'"><button class="button primary" type="submit">'.($global?'停止全部自动发现':'启用全部自动发现').'</button></form>';
    echo '<p class="muted">定时任务：每 5 分钟运行 PHP CLI 文件 bin/agent-run.php；每次最多执行一项工作。管理员可以随时暂停。</p>';
    echo '</section>';
    agent_research_admin_section($pdo);

    echo '<section class="admin-panel"><h2>添加来源网站</h2><p class="muted">建议使用有公开 RSS/Atom/Sitemap 的官方知识库、开放教材或已经允许内容使用的网站。不限中文或英文。新来源默认为摘要模式并暂停。</p>';
    echo '<form method="post" action="/admin/agent/action" class="stack-form">'.csrf_input().'<input type="hidden" name="action" value="add">';
    echo '<label>来源网站 HTTPS 域名<input type="url" name="site" required placeholder="https://docs.example.org"></label>';
    echo '<label>RSS / Atom / Sitemap URL（可选；留空尝试 /sitemap.xml）<input type="url" name="feed" placeholder="https://docs.example.org/sitemap.xml"></label>';
    echo '<label>行业领域<input name="industry" maxlength="100" placeholder="IT、汽车、家电、健康教育、制造业等"></label>';
    echo '<label>国家/语言<input name="region" maxlength="80" placeholder="中文、英语、欧洲、北美等"></label>';
    echo '<button type="submit" class="button primary">登记来源（默认暂停）</button></form></section>';
    echo '<section class="admin-panel"><h2>来源与访问预算</h2><div class="responsive-table"><table><thead><tr><th>站点 / 类别</th><th>模式</th><th>限制</th><th>状态</th><th>操作</th></tr></thead><tbody>';
    foreach ($sources as $s) {
        $id=(int)$s['id'];
        $usedQuery=$pdo->prepare('SELECT COUNT(*) FROM agent_requests WHERE source_id=? AND requested_at>?');
        $usedQuery->execute([$id,time()-86400]);
        $used=(int)$usedQuery->fetchColumn();
        echo '<tr><td><strong>'.h($s['origin']).'</strong><br><small>'.h($s['industry']).' · '.h($s['region']).'</small><br><small>'.h($s['feed_url'] ?: 'Sitemap 默认入口').'</small></td>';
        echo '<td>'.($s['rights_mode']==='licensed'?'已记录转载许可':'仅原创摘要（不复制媒体）').'</td>';
        echo '<td>间隔 '.(int)$s['min_interval'].' 秒<br>24 小时已使用 '.$used.' / '.(int)$s['daily_limit'].' 次<br><small>下次最早：'.h(date('Y-m-d H:i',(int)$s['next_fetch'])).'</small></td>';
        echo '<td>'.($s['enabled']?'启用':'暂停').' / robots: '.h($s['robots_state']).'<br><small>'.h($s['last_message']).'</small></td>';
        echo '<td><form method="post" action="/admin/agent/action">'.csrf_input().'<input type="hidden" name="action" value="enable"><input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="enabled" value="'.($s['enabled']?'0':'1').'"><button type="submit" class="button secondary">'.($s['enabled']?'暂停':'启用').'</button></form></td></tr>';
        echo '<tr><td colspan="5"><form method="post" action="/admin/agent/action" class="ai-import-actions">'.csrf_input().'<input type="hidden" name="action" value="limits"><input type="hidden" name="id" value="'.$id.'"><label>间隔（秒）<input type="number" name="interval" min="300" max="86400" value="'.(int)$s['min_interval'].'"></label><label>24h 请求上限<input type="number" name="daily" min="1" max="20" value="'.(int)$s['daily_limit'].'"></label><button class="button secondary" type="submit">保存限额</button></form>';
        echo '<form method="post" action="/admin/agent/action" class="ai-import-actions">'.csrf_input().'<input type="hidden" name="action" value="rights"><input type="hidden" name="id" value="'.$id.'"><label>素材许可<select name="mode"><option value="summary">原创摘要（默认）</option><option value="licensed" '.($s['rights_mode']==='licensed'?'selected':'').'>已取得转载授权</option></select></label><label>授权证据 / 许可链接<input name="evidence" maxlength="1000" value="'.h($s['rights_evidence']).'" placeholder="完整许可范围或书面授权编号"></label><button class="button secondary" type="submit">更新许可</button></form></td></tr>';
    }
    echo '</tbody></table></div></section>';
    echo '<section class="admin-panel"><h2>AI 候选内容 / 待审核</h2><p class="muted">AI 评分并非事实核验；发布之前需检查事实、许可和媒体。授权媒体有待转存任务时不得先创建草稿。</p><div class="responsive-table"><table><thead><tr><th>内容</th><th>评分/状态</th><th>操作</th></tr></thead><tbody>';
    foreach ($candidates as $c) {
        $id=(int)$c['id'];
        echo '<tr><td><strong>'.h($c['title'] ?: '未命名候选').'</strong><br><small>'.h($c['industry']).'</small><br><a href="'.h($c['url']).'" target="_blank" rel="noopener noreferrer nofollow">'.h(mb_substr($c['url'],0,120)).'</a></td>';
        echo '<td>'.(int)$c['score'].' / 100<br>'.h($c['status']).'</td><td><a class="button secondary" href="/admin/agent?candidate='.$id.'">查看内容</a></td></tr>';
    }
    echo '</tbody></table></div></section>';
    $selected=max(0,(int)($_GET['candidate']??0));
    if ($selected) {
        $q=$pdo->prepare('SELECT * FROM agent_candidates WHERE id=?');
        $q->execute([$selected]);
        $c=$q->fetch();
        if ($c) {
            $mediaStatus=$pdo->prepare('SELECT status,COUNT(*) AS n FROM agent_media WHERE candidate_id=? GROUP BY status');
            $mediaStatus->execute([$selected]);
            $mediaCounts=[];
            foreach ($mediaStatus->fetchAll() as $row) $mediaCounts[$row['status']]=(int)$row['n'];
            echo '<section class="admin-panel"><h2>候选详情 #'.(int)$c['id'].'</h2><p class="muted">来源：'.h($c['url']).'</p><p>授权图片转存：待处理 '.(int)($mediaCounts['queued']??0).' / 成功 '.(int)($mediaCounts['done']??0).' / 跳过 '.(int)($mediaCounts['skipped']??0).'</p><p>证据：'.h($c['evidence']).'</p><p>格式：'.h($c['format_notes']).'</p><div class="article-content">'.sanitize_content($c['body_html']).'</div>';
            if ($c['status']==='review') {
                echo '<form method="post" action="/admin/agent/action">'.csrf_input().'<input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="'.(int)$c['id'].'"><button class="button primary" type="submit">人工确认后创建未发布草稿</button></form>';
                echo '<form method="post" action="/admin/agent/action">'.csrf_input().'<input type="hidden" name="action" value="reject"><input type="hidden" name="id" value="'.(int)$c['id'].'"><button class="button secondary" type="submit">拒绝候选</button></form>';
            } elseif ($c['guide_id']) echo '<p><a href="/admin/guides/edit?id='.(int)$c['guide_id'].'">编辑已建立的草稿</a></p>';
            echo '</section>';
        }
    }
    echo '<section class="admin-panel"><h2>运行审计记录</h2><div class="responsive-table"><table><thead><tr><th>时间</th><th>操作</th><th>结果</th><th>说明</th></tr></thead><tbody>';
    foreach ($events as $event) echo '<tr><td>'.h($event['created_at']).'</td><td>'.h($event['action']).'</td><td>'.h($event['outcome']).'</td><td>'.h($event['detail']).'</td></tr>';
    echo '</tbody></table></div></section></main>';
    page_end(true);
}
