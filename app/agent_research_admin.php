<?php
declare(strict_types=1);

function agent_research_admin_action(PDO $pdo,array $post): string
{
    $action=(string)($post['action']??'');
    $id=max(0,(int)($post['id']??0));
    if ($action==='research_toggle') {
        set_setting('agent_auto_source_enabled',($post['enabled']??'')==='1'?'1':'0');
        return ($post['enabled']??'')==='1' ? '已开启自动寻找来源站点；找到的网站仍需人工审核' : '已停止自动寻找来源站点';
    }
    if ($action==='research_api_key') {
        $key=trim((string)($post['api_key']??''));
        if ($key==='') throw new InvalidArgumentException('请输入搜索 API 密钥；要清除密钥请使用单独的移除按钮');
        agent_research_save_api_key($key);
        return '搜索 API 密钥已加密保存；不会显示或写入日志';
    }
    if ($action==='research_api_clear') {
        set_setting('agent_brave_key_cipher','');
        return '搜索 API 密钥已停用；如开启自动找站，则回退到未验证的 AI 站点建议';
    }
    if ($action==='research_topic_add') {
        $new=agent_research_add_topic($pdo,(string)($post['topic']??''),
            (string)($post['language']??'zh'),(string)($post['region']??'global'));
        return $new>0 ? '新研究主题已加入轮换' : '该研究主题已存在';
    }
    if ($action==='research_topic_toggle') {
        $pdo->prepare('UPDATE agent_research_topics SET enabled=? WHERE id=?')
            ->execute([($post['enabled']??'')==='1'?1:0,$id]);
        return '搜索主题状态已更新';
    }
    if ($action==='research_topic_delete') {
        $pdo->prepare('DELETE FROM agent_research_topics WHERE id=?')->execute([$id]);
        return '搜索主题及其运行历史已删除';
    }
    if ($action==='research_accept') {
        $sourceId=agent_research_accept($pdo,$id);
        return '已将候选网站加入来源池 #'.$sourceId.'，默认关闭且仅允许原创摘要。请检查条款和 robots 后手动启用';
    }
    if ($action==='research_reject') {
        agent_research_reject($pdo,$id);
        return '候选网站已拒绝，自动研究不会重复登记相同域名';
    }
    throw new InvalidArgumentException('未知的自动研究操作');
}

function agent_research_admin_section(PDO $pdo): void
{
    $enabled=setting('agent_auto_source_enabled','0')==='1';
    $api=agent_research_api_configured();
    $topics=$pdo->query('SELECT * FROM agent_research_topics ORDER BY enabled DESC,last_run ASC,id ASC LIMIT 100')->fetchAll();
    $proposals=$pdo->query("SELECT * FROM agent_source_proposals ORDER BY
        CASE state WHEN 'pending' THEN 0 ELSE 1 END,id DESC LIMIT 100")->fetchAll();
    $usedStmt=$pdo->prepare('SELECT COUNT(*) FROM agent_research_runs WHERE created_at>?');
    $usedStmt->execute([time()-86400]);
    $used=(int)$usedStmt->fetchColumn();
    $latest=$pdo->query('SELECT * FROM agent_research_runs ORDER BY id DESC LIMIT 8')->fetchAll();
    echo '<section class="admin-panel"><h2>AI 自动寻找国内外知识来源网站</h2>';
    echo '<p class="muted">自动按行业及语言寻找教程、攻略、问答、知识库和开放教材网站。使用 Brave Search API 可获取搜索索引证据；没有密钥时 AI 只能提出未经联网验证的候选域名，可能不存在。所有新域名必须人工确认，不自动抓取。</p>';
    echo '<p>研究状态：<strong>'.($enabled?'已启用':'关闭').'</strong> · 过去 24 小时已调用 '.$used.'/4 次 · 模式：'.($api?'搜索 API + AI 查询':'AI 未联网推荐').'</p>';
    echo '<form method="post" action="/admin/agent/action">'.csrf_input().'<input type="hidden" name="action" value="research_toggle"><input type="hidden" name="enabled" value="'.($enabled?'0':'1').'"><button class="button primary" type="submit">'.($enabled?'关闭自动找站':'启用自动找站（需全局 Agent 开启）').'</button></form>';
    echo '<form method="post" action="/admin/agent/action" class="ai-import-actions">'.csrf_input().'<input type="hidden" name="action" value="research_api_key"><label>Brave Web Search API Key（加密保存，不会回显）<input type="password" autocomplete="new-password" name="api_key" maxlength="512" placeholder="'.($api?'已配置；留空保持原密钥':'填入独立申请的 API Key').'" required></label><button type="submit" class="button secondary">保存搜索密钥</button></form>';
    if ($api) echo '<form method="post" action="/admin/agent/action">'.csrf_input().'<input type="hidden" name="action" value="research_api_clear"><button type="submit" class="button secondary">移除搜索 API 密钥</button></form>';
    echo '<p class="muted">每轮只进行一次 API 搜索或 AI 推荐，每日最多 4 次；同一主题搜索后至少冷却 72 小时。搜索发现不视为版权或抓取许可。部分搜索 API 可能产生计费，请自行查看服务商套餐。</p>';
    echo '<h3>行业 / 国家 / 语言主题</h3>';
    echo '<form method="post" action="/admin/agent/action" class="ai-import-actions">'.csrf_input().'<input type="hidden" name="action" value="research_topic_add"><label>行业或搜索主题<input name="topic" maxlength="120" required placeholder="如：家电维修、软件技巧、海外开放教材"></label><label>语言<select name="language"><option value="zh">中文</option><option value="en">英语</option><option value="es">西语</option><option value="fr">法语</option><option value="de">德语</option><option value="ja">日语</option><option value="ko">韩语</option><option value="pt">葡语</option><option value="other">其它</option></select></label><label>地区<input name="region" maxlength="50" value="global"></label><button class="button secondary" type="submit">新增主题</button></form>';
    echo '<div class="responsive-table"><table><thead><tr><th>研究主题</th><th>语言 / 地区</th><th>状态 / 下次运行</th><th>操作</th></tr></thead><tbody>';
    foreach($topics as $t) {
        $id=(int)$t['id'];
        echo '<tr><td>'.h($t['topic']).'</td><td>'.h($t['language']).' · '.h($t['region']).'</td><td>'.($t['enabled']?'轮换中':'已暂停').' / '.($t['next_run'] ? h(date('m-d H:i',(int)$t['next_run'])) : '待首次运行').'</td><td>';
        echo '<form method="post" action="/admin/agent/action">'.csrf_input().'<input type="hidden" name="action" value="research_topic_toggle"><input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="enabled" value="'.($t['enabled']?'0':'1').'"><button type="submit" class="button secondary">'.($t['enabled']?'暂停':'启用').'</button></form>';
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
    echo '<h3>自动发现的网站候选（人工审核）</h3><div class="responsive-table"><table><thead><tr><th>网站与样例</th><th>来源及核验状态</th><th>操作</th></tr></thead><tbody>';
    foreach($proposals as $p) {
        $id=(int)$p['id'];
        $isAi=$p['method']==='ai_suggestion';
        echo '<tr><td><strong>'.h($p['origin']).'</strong><br><a href="'.h($p['example_url']).'" target="_blank" rel="noopener noreferrer nofollow">'.h(mb_substr($p['title'] ?: $p['example_url'],0,120)).'</a><br><small>'.h($p['snippet']).'</small></td>';
        echo '<td>'.($isAi?'AI 猜测，未联网核实':'搜索索引中的网站，未核实许可').'<br><small>'.h($p['topic']).' · '.h($p['language']).' · '.h($p['state']).'</small></td><td>';
        if($p['state']==='pending') {
            echo '<form method="post" action="/admin/agent/action">'.csrf_input().'<input type="hidden" name="action" value="research_accept"><input type="hidden" name="id" value="'.$id.'"><button type="submit" class="button secondary">人工确认并加入暂停来源池</button></form>';
            echo '<form method="post" action="/admin/agent/action">'.csrf_input().'<input type="hidden" name="action" value="research_reject"><input type="hidden" name="id" value="'.$id.'"><button type="submit" class="button secondary">拒绝</button></form>';
        } else echo h($p['state']);
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
    echo '<p class="muted">研究最近记录：</p><div class="responsive-table"><table><thead><tr><th>时间</th><th>方式</th><th>新增候选</th><th>结果</th></tr></thead><tbody>';
    foreach($latest as $run) echo '<tr><td>'.h(date('m-d H:i',(int)$run['created_at'])).'</td><td>'.h($run['method']).'</td><td>'.(int)$run['results'].'</td><td>'.h($run['result']).'</td></tr>';
    echo '</tbody></table></div></section>';
}
