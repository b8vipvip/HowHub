<?php
declare(strict_types=1);

function agent_topic_admin_action(PDO $pdo,array $post): string
{
    $action=(string)($post['action']??'');
    $id=max(0,(int)($post['id']??0));
    if ($action==='topic_add') {
        $title=(string)($post['title']??'');
        if (!agent_topic_enqueue($pdo,$title,'','manual',(string)($post['industry']??'通用知识'),'zh')) {
            throw new RuntimeException('选题无效、已存在或队列已满，请检查标题');
        }
        return '原创选题已排队。只将问题或标题发送给 AI，不调用原网页';
    }
    if ($action==='topic_approve') {
        $guide=agent_topic_approve($pdo,$id);
        return '已建立不带第三方出处区块的原创文章草稿 #'.$guide.'，仍需人工审核后发布';
    }
    if ($action==='topic_reject') {
        $pdo->prepare("UPDATE agent_topic_ideas SET status='rejected' WHERE id=? AND status IN ('queued','review','error')")
            ->execute([$id]);
        return '原创选题已拒绝';
    }
    if ($action==='topic_budget_settings') {
        $articles=min(24,max(1,(int)($post['articles']??8)));
        $images=min(8,max(0,(int)($post['images']??2)));
        set_setting('agent_topic_article_daily',(string)$articles);
        set_setting('agent_topic_image_daily',(string)$images);
        return '每日原创 AI 预算已保存；已消耗额度按过去 24 小时统计';
    }
    if ($action==='topic_skip_image') {
        $pdo->prepare("UPDATE agent_topic_ideas SET asset_status='skipped' WHERE id=? AND status='review' AND asset_status='pending'")
            ->execute([$id]);
        return '已跳过该文章的待生成插画，可继续人工审核';
    }
    if ($action==='topic_image_settings') {
        $model=trim((string)($post['model']??'gpt-image-1'));
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:+-]{0,100}$/D',$model)) {
            throw new InvalidArgumentException('图片模型 ID 格式无效');
        }
        set_setting('agent_original_image_model',$model);
        set_setting('agent_original_image_enabled',($post['enabled']??'')==='1'?'1':'0');
        return '原创教学插画生成选项已更新；仅使用模型生成的 Base64 图片，不下载外站图片';
    }
    throw new InvalidArgumentException('未知原创选题管理操作');
}

function agent_topic_admin_section(PDO $pdo): void
{
    $pending=(int)$pdo->query("SELECT COUNT(*) FROM agent_topic_ideas WHERE status='queued'")->fetchColumn();
    $review=(int)$pdo->query("SELECT COUNT(*) FROM agent_topic_ideas WHERE status='review'")->fetchColumn();
    $records=$pdo->query("SELECT * FROM agent_topic_ideas
       ORDER BY CASE status WHEN 'review' THEN 0 WHEN 'queued' THEN 1 ELSE 2 END,id DESC LIMIT 80")->fetchAll();
    $enabled=setting('agent_original_image_enabled','0')==='1';
    echo '<section class="admin-panel"><h2>原创选题 Agent（默认创作模式）</h2>';
    echo '<p class="muted">自动从搜索结果与 RSS/Atom 中提取标题/问题，不访问文章正文，也不使用他人的图片和视频。AI 重新拟题，从零生成教程步骤、表格、公式和流程图；原创图片需单独启用。网站 URL 仅保存在后台审计信息中，不显示在原创文章正文里。</p>';
    echo '<p>待写作：<strong>'.$pending.'</strong>　待审核：<strong>'.$review.'</strong>　原创图片：'.($enabled?'已启用':'关闭').'</p>';
    $budgetArticles=min(24,max(1,(int)setting('agent_topic_article_daily','8')));
    $budgetImages=min(8,max(0,(int)setting('agent_topic_image_daily','2')));
    $count=$pdo->prepare('SELECT COUNT(*) FROM agent_topic_runs WHERE kind=? AND requested_at>?');
    $count->execute(['article',time()-86400]);$usedArticles=(int)$count->fetchColumn();
    $count->execute(['image',time()-86400]);$usedImages=(int)$count->fetchColumn();
    echo '<p class="muted">过去 24 小时 AI 调用预算：文章 '.$usedArticles.'/'.$budgetArticles.'；原创图片 '.$usedImages.'/'.$budgetImages.'（失败也计费计数，防止重试循环）</p>';
    echo '<form method="post" action="/admin/agent/action" class="ai-import-actions">'.csrf_input().'<input type="hidden" name="action" value="topic_budget_settings"><label>每日最多原创文章生成<input type="number" name="articles" min="1" max="24" value="'.$budgetArticles.'"></label><label>每日最多原创图片生成<input type="number" name="images" min="0" max="8" value="'.$budgetImages.'"></label><button class="button secondary" type="submit">保存 AI 调用预算</button></form>';

    echo '<form method="post" action="/admin/agent/action" class="ai-import-actions">'.csrf_input().
         '<input type="hidden" name="action" value="topic_add">'.
         '<label>手动新增选题 / 常见问题<input name="title" required maxlength="180" placeholder="例如：怎样排查家中 Wi-Fi 频繁断线？"></label>'.
         '<label>行业<input name="industry" maxlength="100" value="通用知识"></label>'.
         '<button class="button primary" type="submit">加入原创写作队列</button></form>';
    echo '<form method="post" action="/admin/agent/action" class="ai-import-actions">'.csrf_input().
         '<input type="hidden" name="action" value="topic_image_settings">'.
         '<label>原创 AI 插画<select name="enabled"><option value="0" '.(!$enabled?'selected':'').'>关闭（仅 HTML、公式和导图）</option><option value="1" '.($enabled?'selected':'').'>每篇最多一张 AI 原创示意图</option></select></label>'.
         '<label>兼容图片生成的模型 ID<input name="model" maxlength="100" value="'.h(setting('agent_original_image_model','gpt-image-1')).'"></label>'.
         '<button class="button secondary" type="submit">保存原创插画设置</button></form>';
    echo '<p class="muted">图片供应商需要支持 /v1/images/generations 的 b64_json 输出。生成失败不会自动使用第三方素材；流程图和表格是可编辑 HTML。全流程仍保留事实质量和安全审核，原创不等于所有内容一定正确或免除其它合规义务。</p>';
    echo '<h3>原创文章生成队列与审核</h3><div class="responsive-table"><table><thead><tr><th>原创选题 / 生成标题</th><th>状态及评分</th><th>操作</th></tr></thead><tbody>';
    foreach($records as $item) {
        $id=(int)$item['id'];
        $heading=$item['title'] ?: $item['raw_title'];
        echo '<tr><td><strong>'.h($heading).'</strong><br><small>原始问题：'.h(mb_substr((string)$item['raw_title'],0,180)).
            '；领域：'.h($item['industry']).'；仅供内部审计：'.h($item['source_method']).'</small></td>';
        echo '<td>'.h($item['status']).' / '.(int)$item['score'].' 分<br><small>原创配图：'.h($item['asset_status']).'</small></td>';
        echo '<td><a class="button secondary" href="/admin/agent?topic_idea='.$id.'">预览原创内容</a></td></tr>';
    }
    echo '</tbody></table></div>';
    $selected=max(0,(int)($_GET['topic_idea']??0));
    if($selected) {
        $get=$pdo->prepare('SELECT * FROM agent_topic_ideas WHERE id=?');
        $get->execute([$selected]);
        $item=$get->fetch();
        if($item) {
            echo '<div class="admin-panel"><h3>原创选题 #'.(int)$item['id'].'</h3>';
            echo '<p class="muted">内部选题线索（不加入正文）：'.h($item['source_url']?:'管理员输入').
                '</p><p class="muted">审阅问题：'.h($item['review_notes']).'</p>';
            echo '<div class="article-content">'.sanitize_content($item['html']).'</div>';
            if($item['status']==='review' && $item['asset_status']==='pending') {
                echo '<form method="post" action="/admin/agent/action">'.csrf_input().'<input type="hidden" name="action" value="topic_skip_image"><input type="hidden" name="id" value="'.(int)$item['id'].'"><button class="button secondary" type="submit">跳过原创插画，直接审核文字</button></form>';
            }
            if($item['status']==='review') {
                echo '<form method="post" action="/admin/agent/action">'.csrf_input().
                    '<input type="hidden" name="action" value="topic_approve">'.
                    '<input type="hidden" name="id" value="'.(int)$item['id'].'">'.
                    '<button class="button primary" type="submit" '.($item['asset_status']==='pending'?'disabled':'').'>人工确认并创建未发布草稿</button></form>';
            }
            if(in_array($item['status'],['queued','review','error'],true)) {
                echo '<form method="post" action="/admin/agent/action">'.csrf_input().
                    '<input type="hidden" name="action" value="topic_reject">'.
                    '<input type="hidden" name="id" value="'.(int)$item['id'].'">'.
                    '<button class="button secondary" type="submit">拒绝选题</button></form>';
            }
            if($item['guide_id']) echo '<p><a href="/admin/guides/edit?id='.(int)$item['guide_id'].'">编辑已生成的原创草稿</a></p>';
            echo '</div>';
        }
    }
    echo '</section>';
}
