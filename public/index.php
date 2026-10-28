<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

try {
    $pdo = database();
} catch (Throwable $e) {
    diag_exception('system', 'startup', $e);
    error_log('[HowHub startup] ' . get_class($e));
    http_response_code(503);
    exit('HowHub 初始化失败：请检查 PHP 扩展和 storage 目录权限。详情见服务器 PHP 错误日志。');
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = rtrim($path, '/') ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($path === '/healthz') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'app' => 'HowHub'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($path === '/' && $method === 'GET') {
    $q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
    $categorySlug = trim((string)($_GET['category'] ?? ''));
    $page = max(1, min(100000, (int)($_GET['page'] ?? 1)));
    $limit = 9;
    $categories = $pdo->query('SELECT * FROM categories ORDER BY sort_order, id')->fetchAll();
    $filters = ["g.status = 'published'"];
    $params = [];
    if ($q !== '') {
        $filters[] = '(g.title LIKE :q1 OR g.summary LIKE :q2 OR g.tags LIKE :q3)';
        $params[':q1'] = '%' . $q . '%';
        $params[':q2'] = '%' . $q . '%';
        $params[':q3'] = '%' . $q . '%';
    }
    if ($categorySlug !== '') {
        $filters[] = 'c.slug = :category';
        $params[':category'] = $categorySlug;
    }
    $where = implode(' AND ', $filters);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM guides g LEFT JOIN categories c ON c.id = g.category_id WHERE ' . $where);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare('SELECT g.*, c.name AS category_name FROM guides g LEFT JOIN categories c ON c.id=g.category_id WHERE ' . $where . ' ORDER BY g.featured DESC, g.published_at DESC, g.id DESC LIMIT :limit OFFSET :offset');
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', ($page - 1) * $limit, PDO::PARAM_INT);
    $stmt->execute();
    $guides = $stmt->fetchAll();
    page_start('', setting('site_description'));
    echo '<main><section class="hero shell"><div class="hero-copy"><div class="hero-badge"><span class="badge-sparkle">✳</span> YOUR GUIDE TO EVERYTHING</div><h1>好方法，<br><span>一步就懂。</span></h1><p>' . h(setting('site_description')) . '<br>从设备设置到日常技巧，找到答案，从这里开始。</p><form class="search-bar" method="get" action="/"><svg viewBox="0 0 24 24" width="23" height="23" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="7.5"/><path d="m16.5 16.5 5 5"/></svg><input name="q" maxlength="100" aria-label="搜索教程" placeholder="搜索教程、设备、软件或问题..." value="' . h($q) . '"><button type="submit">搜索指南 <span>↗</span></button></form><div class="hero-hints"><span>热门搜索</span><a href="/?q=雷鸟电视">雷鸟电视</a><a href="/?q=酷狗">酷狗账号</a><a href="/?q=会员">会员绑定</a></div></div><div class="hero-art" aria-hidden="true"><div class="art-grid"></div><div class="art-glow"></div><div class="floating-note note-main"><div class="mini-tag"><span class="status-circle"></span> HOW TO GUIDE</div><div class="floating-icon">✦</div><div class="note-lines"><span></span><span></span><span></span></div><div class="step-indicator"><span>01</span> — <span>02</span> — <strong>03</strong></div></div><div class="floating-note note-back"><span class="note-check">✓</span><span>轻松搞定</span></div><span class="art-star star-a">✳</span><span class="art-star star-b">✦</span></div></section>';
    echo '<section class="section shell" id="categories"><div class="section-header"><div><div class="kicker">EXPLORE TOPICS</div><h2>按主题探索</h2><p>想解决什么问题？从这里找到对应的教程。</p></div></div><div class="category-strip"><a class="category-tile ' . ($categorySlug === '' ? 'active' : '') . '" href="/#guides"><span class="cat-icon">✦</span><span>全部指南</span><span class="cat-arrow">↗</span></a>';
    $icons = ['devices' => '▣', 'software' => '⌘', 'accounts' => '♙', 'lifestyle' => '☼'];
    foreach ($categories as $cat) {
        echo '<a class="category-tile ' . ($categorySlug === $cat['slug'] ? 'active' : '') . '" href="/?category=' . rawurlencode($cat['slug']) . '#guides"><span class="cat-icon">' . ($icons[$cat['slug']] ?? '◇') . '</span><span>' . h($cat['name']) . '</span><span class="cat-arrow">↗</span></a>';
    }
    echo '</div></section><section class="section guide-section shell" id="guides"><div class="section-header"><div><div class="kicker">CURATED GUIDES</div><h2>' . ($q !== '' ? '搜索结果' : ($categorySlug !== '' ? '分类指南' : '最新实用指南')) . '</h2><p>清晰的步骤、直观的配图，轻松完成每一项操作。</p></div><span class="count-tag">共 ' . $total . ' 篇指南</span></div>';
    if (!$guides) {
        echo '<div class="empty-state"><div class="empty-icon">⌕</div><h3>暂时没有找到对应指南</h3><p>换个关键词试试，或者浏览全部分类。</p><a class="button primary" href="/">查看全部指南 →</a></div>';
    } else {
        echo '<div class="guide-grid">';
        foreach ($guides as $guide) guide_card($guide);
        echo '</div>';
        if ($total > $limit) {
            $pageCount = (int)ceil($total / $limit);
            echo '<div class="pagination">';
            for ($p = max(1, $page - 2); $p <= min($pageCount, $page + 2); $p++) {
                echo '<a class="' . ($p === $page ? 'current' : '') . '" href="/?' . h(http_build_query(['q' => $q, 'category' => $categorySlug, 'page' => $p])) . '#guides">' . $p . '</a>';
            }
            echo '</div>';
        }
    }
    echo '</section><section class="bottom-banner shell"><div><span class="kicker">MADE SIMPLE</span><h2>复杂的事，<br>也可以很简单。</h2><p>HowHub 持续收录值得收藏的操作指南。</p></div><div class="banner-deco" aria-hidden="true">✳</div></section></main>';
    page_end();
    exit;
}

if (preg_match('~^/guide/([^/]+)$~u', $path, $match) && $method === 'GET') {
    $slug = rawurldecode($match[1]);
    $sql = 'SELECT g.*, c.name AS category_name, c.slug AS category_slug FROM guides g LEFT JOIN categories c ON c.id=g.category_id WHERE g.slug=?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$slug]);
    $guide = $stmt->fetch();
    if (!$guide || ($guide['status'] !== 'published' && !logged_in())) {
        error_page(404, '指南未找到', '这篇指南可能已移动或尚未发布。');
    }
    $pdo->prepare('UPDATE guides SET views=views+1 WHERE id=?')->execute([$guide['id']]);
    [$body, $toc] = render_with_toc($guide['body_html']);
    page_start($guide['title'], $guide['summary']);
    echo '<main class="reader-shell shell"><div class="breadcrumb"><a href="/">首页</a><span>›</span><a href="/?category=' . h(rawurlencode((string)($guide['category_slug'] ?? ''))) . '">' . h($guide['category_name'] ?: '实用指南') . '</a><span>›</span><span>正文</span></div>';
    echo '<div class="reader-layout"><article class="reader-article"><div class="article-heading"><div class="guide-category-pill">✳ ' . h($guide['category_name'] ?: '实用指南') . '</div><h1>' . h($guide['title']) . '</h1><p>' . h($guide['summary']) . '</p><div class="article-meta"><span>HowHub 编辑部</span><i></i><span>更新于 ' . h(substr($guide['updated_at'], 0, 10)) . '</span><i></i><span>' . ((int)$guide['views'] + 1) . ' 次阅读</span></div></div><div class="article-content">' . $body . '</div><div class="article-bottom"><div class="article-end-icon">✓</div><h3>你已经读完这篇指南</h3><p>希望这份步骤说明对你有所帮助。</p><a href="/">← 返回更多指南</a></div></article><aside class="reader-sidebar"><div class="toc-card"><div class="toc-title">本页目录</div>';
    if ($toc) {
        echo '<ul>';
        foreach ($toc as $i => $item) {
            echo '<li class="' . ($item['depth'] === 'h3' ? 'toc-indent' : '') . '"><a href="#' . h($item['id']) . '"><span>' . str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) . '</span>' . h($item['label']) . '</a></li>';
        }
        echo '</ul>';
    } else {
        echo '<p class="muted">暂无章节标题</p>';
    }
    echo '</div><div class="sidebar-tip">💡 <strong>小提示</strong><p>按顺序操作并结合配图查看，更容易完成设置。</p></div></aside></div></main>';
    page_end();
    exit;
}

// 文档存储在非公开的 storage 下，仅以附件形式提供下载。
if ($method === 'GET' && preg_match('~^/file/(\d+)$~', $path, $match)) {
    $q = $pdo->prepare("SELECT * FROM media_assets WHERE id=? AND kind='file'");
    $q->execute([(int)$match[1]]);
    $asset = $q->fetch();
    if (!$asset) error_page(404, '文件不存在', '附件可能已被移除。');
    if (!logged_in() && media_usage_published($pdo, $asset['url']) === 0) error_page(404, '文件不存在', '附件尚未公开发布。');
    $filePath = media_file_path($asset);
    if (!$filePath) error_page(404, '文件不存在', '服务器找不到附件。');
    header('Content-Type: application/octet-stream');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="download"; filename*=UTF-8\'\'' . rawurlencode($asset['original_name']));
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: private, no-store');
    readfile($filePath);
    exit;
}

// 从此处起均为后台路由。
if (!str_starts_with($path, '/admin')) {
    error_page(404, '页面不存在', '找不到你要访问的页面。');
}

if ($path === '/admin/login') {
    if (logged_in()) redirect_to('/admin');
    $error = '';
    if ($method === 'POST') {
        check_csrf();
        $attempts = $_SESSION['login_attempts'] ?? [];
        $attempts = array_values(array_filter($attempts, static fn($t) => is_int($t) && $t > time() - 900));
        if (count($attempts) >= 8) {
            $error = '尝试次数过多，请 15 分钟后再试。';
            diag_event('warning', 'auth', 'rate_limit', 'blocked');
        } else {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
            $stmt->execute([trim((string)($_POST['username'] ?? ''))]);
            $user = $stmt->fetch();
            if ($user && password_verify((string)($_POST['password'] ?? ''), $user['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['login_attempts'] = [];
                $pdo->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id=?')->execute([$user['id']]);
                diag_event('info', 'auth', 'login', 'success');
                redirect_to('/admin');
            }
            diag_event('warning', 'auth', 'login', 'failure');
            $attempts[] = time();
            $_SESSION['login_attempts'] = $attempts;
            $error = '用户名或密码不正确。';
        }
    }
    page_start('登录后台');
    echo '<main class="auth-layout"><div class="auth-panel"><div class="auth-logo">H<span>.</span></div><div class="kicker">WELCOME BACK</div><h1>登录 HowHub</h1><p>管理你的教程、分类与网站内容。</p>';
    if ($error !== '') echo '<div class="form-error">' . h($error) . '</div>';
    echo '<form action="/admin/login" method="post" class="stack-form">' . csrf_input() . '<label>管理员账号<input name="username" autocomplete="username" required autofocus placeholder="请输入账号"></label><label>登录密码<input type="password" name="password" autocomplete="current-password" required placeholder="请输入密码"></label><button type="submit" class="button primary full">安全登录 →</button></form><div class="login-footnote">首次使用？请按 README 中的说明在服务器创建管理员账号。</div></div></main>';
    page_end();
    exit;
}

require_admin();

if ($path === '/admin/diagnostics/client' && $method === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    check_csrf();
    $count = (int)($_SESSION['diagnostic_client_count'] ?? 0);
    if ($count >= 30) { http_response_code(429); echo '{"ok":false}'; exit; }
    $_SESSION['diagnostic_client_count'] = $count + 1;
    $action = (string)($_POST['action'] ?? 'ui_error');
    $feature = (string)($_POST['feature'] ?? 'editor');
    // Only log a controlled event name, no browser-provided error messages or stack traces.
    diag_event('warning', $feature, $action, 'failure');
    echo '{"ok":true}';
    exit;
}

if (($path === '/admin/diagnostics' || $path === '/admin/diagnostics/export') && $method === 'GET') {
    $level = (string)($_GET['level'] ?? '');
    $feature = (string)($_GET['feature'] ?? '');
    if (!in_array($level, ['', 'info', 'warning', 'error', 'critical'], true)) $level = '';
    if (!in_array($feature, ['', 'system', 'php', 'http', 'auth', 'guides', 'categories', 'media', 'ai', 'editor', 'settings'], true)) $feature = '';
    if ($path === '/admin/diagnostics/export') {
        $items = diag_entries(2000, $level, $feature);
        diag_event('info', 'system', 'logs_export', 'success', ['count' => count($items)]);
        header('Content-Type: application/x-ndjson; charset=utf-8');
        header('Content-Disposition: attachment; filename="howhub-diagnostics-' . gmdate('Ymd-His') . '.jsonl"');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        foreach (array_reverse($items) as $entry) echo json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
        exit;
    }
    $items = diag_entries(200, $level, $feature);
    $health = diag_health();
    page_start('诊断日志', '', true);
    echo '<main class="shell admin-main">';
    admin_heading('SYSTEM DIAGNOSTICS', '诊断日志', '查看运行状态、功能事件与报错详情；日志存储在非公开目录并自动轮转。', '<a class="button secondary" href="/admin">← 返回工作台</a>');
    echo '<section class="diag-summary-grid">';
    echo '<div class="admin-panel diag-summary"><span>运行版本</span><strong>' . h($health['version']['release']) . '</strong><small>Commit: ' . h($health['version']['commit']) . '</small></div>';
    echo '<div class="admin-panel diag-summary"><span>服务状态</span><strong class="' . ($health['status'] === 'healthy' ? 'diag-good' : 'diag-bad') . '">' . ($health['status'] === 'healthy' ? '正常' : '需要检查') . '</strong><small>PHP ' . h($health['php']) . ' · ' . h($health['sapi']) . '</small></div>';
    echo '<div class="admin-panel diag-summary"><span>AI 供应商</span><strong>' . ($health['ai_configured'] ? '已配置' : '未配置') . '</strong><small>仅显示状态，不显示 API Key</small></div>';
    echo '<div class="admin-panel diag-summary"><span>剩余磁盘空间</span><strong>' . ($health['disk_free_mb'] === null ? '未知' : h((string)$health['disk_free_mb']) . ' MB') . '</strong><small>日志上限约 5 MB</small></div></section>';
    echo '<section class="admin-panel diag-checks"><h2>运行环境检查</h2><div class="diag-check-grid">';
    foreach ($health['checks'] as $label => $ok) echo '<span class="diag-check ' . ($ok ? 'diag-good' : 'diag-bad') . '">' . ($ok ? '✓ ' : '✕ ') . h($label) . '</span>';
    echo '</div></section>';
    echo '<section class="admin-panel"><div class="panel-head"><h2>最近功能日志</h2><p class="muted">时间为 UTC；已记录操作、HTTP 状态、耗时与请求 ID，不记录正文、密码、密钥或请求体。</p></div>';
    echo '<form method="get" action="/admin/diagnostics" class="diag-filters"><label>级别 <select name="level">';
    foreach ([''=>'全部', 'info'=>'信息', 'warning'=>'警告', 'error'=>'错误', 'critical'=>'严重'] as $key => $value) echo '<option value="' . h($key) . '"' . ($level === $key ? ' selected' : '') . '>' . h($value) . '</option>';
    echo '</select></label><label>模块 <select name="feature">';
    foreach ([''=>'全部', 'system'=>'系统', 'php'=>'PHP', 'http'=>'HTTP', 'auth'=>'登录安全', 'guides'=>'教程', 'categories'=>'分类', 'media'=>'素材', 'ai'=>'AI', 'editor'=>'编辑器', 'settings'=>'设置'] as $key => $value) echo '<option value="' . h($key) . '"' . ($feature === $key ? ' selected' : '') . '>' . h($value) . '</option>';
    echo '</select></label><button class="button primary" type="submit">筛选</button><a class="button secondary" href="/admin/diagnostics/export?' . h(http_build_query(['level'=>$level,'feature'=>$feature])) . '">导出日志（最多 2000 条）</a></form>';
    echo '<div class="responsive-table"><table class="diag-table"><thead><tr><th>时间 UTC</th><th>级别</th><th>功能</th><th>事件 / 结果</th><th>HTTP</th><th>请求 ID / 详情</th></tr></thead><tbody>';
    if (!$items) echo '<tr><td colspan="6">暂无符合筛选条件的日志。尝试登录、保存文章或调用 AI 后刷新查看。</td></tr>';
    foreach ($items as $item) {
        $details = (array)($item['details'] ?? []);
        echo '<tr><td class="diag-nowrap">' . h($item['time'] ?? '') . '</td><td><span class="diag-level diag-' . h($item['level'] ?? 'info') . '">' . h($item['level'] ?? '') . '</span></td><td>' . h($item['feature'] ?? '') . '</td><td>' . h($item['action'] ?? '') . ' <small>' . h($item['result'] ?? '') . '</small></td><td>' . (int)($item['status_code'] ?? 0) . '</td><td><details><summary>' . h($item['request_id'] ?? '无') . '</summary><pre>' . h(json_encode(['route'=>$item['route'] ?? '', 'method'=>$item['method'] ?? '', 'version'=>$item['version'] ?? [], 'details'=>$details], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE)) . '</pre></details></td></tr>';
    }
    echo '</tbody></table></div></section></main>';
    page_end(true);
    exit;
}

// AI configuration is admin-only. The client never receives decrypted API credentials.
if ($path === '/admin/ai-settings') {
    if ($method === 'POST') {
        check_csrf();
        try {
            ai_save_config($_POST);
            diag_event('info', 'ai', 'settings_save');
            flash('AI 供应商配置已加密保存。');
        } catch (Throwable $e) {
            diag_exception('ai', 'settings_save', $e);
            flash($e->getMessage(), 'error');
        }
        redirect_to('/admin/ai-settings');
    }
    $ai = ai_config();
    page_start('AI 供应商设置', '', true);
    echo '<main class="shell admin-main">';
    admin_heading('AI PROVIDER', 'AI 供应商', '采用 mv3c 使用的 OpenAI 兼容 chat2api 接口；单独配置 HowHub 的服务端密钥。');
    echo '<section class="admin-panel settings-panel"><form method="post" action="/admin/ai-settings" class="stack-form">' . csrf_input();
    echo '<label>API 基础地址（以 /v1 结尾）<input required name="base_url" type="url" maxlength="2048" placeholder="https://api.example.com/v1" value="' . h($ai['base_url']) . '"></label>';
    echo '<label>API Key <input name="api_key" type="password" autocomplete="new-password" placeholder="' . ($ai['configured'] ? '已配置；留空则保持原有密钥' : '输入 API Key') . '" maxlength="4096"></label>';
    echo '<p class="muted">密钥使用 AES-256-GCM 加密后存放在 SQLite，解密密钥仅保存在非公开的 storage 目录；此处永不回显。</p>';
    echo '<label>导入教程使用的模型 ID <input name="model" id="ai-model-input" required maxlength="128" list="ai-model-options" value="' . h($ai['model']) . '"></label>';
    echo '<datalist id="ai-model-options">';
    foreach ($ai['models'] as $model) echo '<option value="' . h($model) . '"></option>';
    echo '</datalist><button class="button primary" type="submit">保存供应商配置</button></form><hr>';
    echo '<form id="ai-model-sync" method="post" action="/admin/ai/models">' . csrf_input() . '<button type="submit" class="button secondary">从供应商发现模型</button></form>';
    echo '<p class="muted">首次配置先保存地址及密钥，再点击发现模型。模型发现不会覆盖你手动选择的当前模型。</p>';
    echo '<p><strong>当前状态：</strong>' . ($ai['configured'] ? '已配置' : '未配置') . '</p>';
    echo '<p class="muted">提交网页代码会把其中的文本和图片占位符发送给配置的 AI 供应商。请勿粘贴密码、验证码或其它秘密。AI 生成后只创建草稿，不自动发布。</p>';
    echo '</section></main>';
    page_end(true);
    exit;
}

if ($path === '/admin/ai/models' && $method === 'POST') {
    check_csrf();
    try {
        $models = ai_sync_models();
        diag_event('info', 'ai', 'models_sync', 'success', ['count' => count($models)]);
        flash('已从 AI 供应商同步 ' . count($models) . ' 个模型，请在上方模型框中选择并保存。');
    } catch (Throwable $e) { diag_exception('ai', 'models_sync', $e); flash($e->getMessage(), 'error'); }
    redirect_to('/admin/ai-settings');
}

// Safe HTML normalization for cross-website copy/paste in contenteditable.
if ($path === '/admin/import/clean' && $method === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        check_csrf();
        $html = (string)($_POST['html'] ?? '');
        if (strlen($html) > 300000) throw new InvalidArgumentException('粘贴内容不得超过 300KB');
        $html = str_replace(['https\\://','http\\://'], ['https://','http://'], $html);
        $cleaned = sanitize_content($html);
        diag_event('info', 'editor', 'paste_clean', 'success', ['size_bytes' => strlen($html)]);
        echo json_encode(['ok'=>true, 'html'=>$cleaned], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $e) {
        diag_exception('editor', 'paste_clean', $e);
        http_response_code(400);
        echo json_encode(['ok'=>false, 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Optimize the unsaved current HTML only. Never write to the database in this endpoint.
if ($path === '/admin/guides/optimize' && $method === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        check_csrf();
        $attempts = $_SESSION['ai_optimize_attempts'] ?? [];
        $attempts = array_values(array_filter($attempts, static fn($time) => is_int($time) && $time > time() - 600));
        if (count($attempts) >= 6) throw new RuntimeException('AI 优化调用频繁，请 10 分钟后再试');
        $attempts[] = time();
        $_SESSION['ai_optimize_attempts'] = $attempts;
        $result = ai_optimize_current_guide((string)($_POST['html'] ?? ''), (string)($_POST['title'] ?? ''));
        diag_event('info', 'ai', 'optimize', 'success', ['count' => $result['image_count'] ?? 0]);
        echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $e) {
        diag_exception('ai', 'optimize', $e);
        http_response_code(400);
        $error = $e instanceof InvalidArgumentException || $e instanceof RuntimeException
            ? $e->getMessage() : 'AI 优化失败，请稍后重试';
        echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
    exit;
}

// Independent new draft: never overwrite a published article on AI import.
if ($path === '/admin/import/generate' && $method === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        check_csrf();
        $attempts = $_SESSION['ai_import_attempts'] ?? [];
        $attempts = array_values(array_filter($attempts, static fn($t) => is_int($t) && $t > time() - 600));
        if (count($attempts) >= 10) throw new RuntimeException('AI 导入操作过于频繁，请十分钟后重试');
        $attempts[] = time();
        $_SESSION['ai_import_attempts'] = $attempts;
        $source = import_prepare_source((string)($_POST['html'] ?? ''));
        $draft = ai_generate_guide($source);
        $id = import_create_draft($pdo, $draft);
        diag_event('info', 'ai', 'draft_generate', 'success', ['guide_id' => $id, 'count' => count($source['images'])]);
        echo json_encode(['ok'=>true, 'edit_url'=>'/admin/guides/edit?id='.$id, 'image_count'=>count($source['images'])], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        diag_exception('ai', 'draft_generate', $e);
        http_response_code(400);
        echo json_encode(['ok'=>false, 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
    exit;
}

if ($path === '/admin/logout' && $method === 'POST') {
    check_csrf();
    diag_event('info', 'auth', 'logout');
    $_SESSION = [];
    session_regenerate_id(true);
    redirect_to('/admin/login');
}

if ($path === '/admin/upload' && $method === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        check_csrf();
        $file = $_FILES['asset'] ?? $_FILES['image'] ?? null;
        if (!is_array($file)) throw new RuntimeException('没有收到上传文件');
        $asset = media_upload($pdo, $file);
        diag_event('info', 'media', 'upload', 'success', ['asset_id' => $asset['id'], 'kind' => $asset['kind'], 'size_bytes' => $asset['byte_size']]);
        echo json_encode(['ok' => true, 'url' => $asset['url'], 'kind' => $asset['kind'], 'asset' => $asset], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        diag_exception('media', 'upload', $e);
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($path === '/admin/media/list' && $method === 'GET') {
    media_sync_legacy($pdo);
    $kind = (string)($_GET['kind'] ?? 'all');
    $sql = "SELECT * FROM media_assets" . ($kind === 'image' || $kind === 'file' ? " WHERE kind=?" : '') . ' ORDER BY id DESC LIMIT 250';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($kind === 'image' || $kind === 'file' ? [$kind] : []);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => true, 'assets' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($path === '/admin/media/action' && $method === 'POST') {
    check_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'delete') {
            media_delete($pdo, $id);
            diag_event('info', 'media', 'delete', 'success', ['asset_id' => $id]);
            flash('素材文件已删除。');
        } elseif ($action === 'rename') {
            $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 120);
            if ($name === '') throw new RuntimeException('素材名称不能为空');
            $old = $pdo->prepare('SELECT original_name FROM media_assets WHERE id=?');
            $old->execute([$id]);
            $previousName = $old->fetchColumn();
            if ($previousName === false) throw new RuntimeException('素材不存在');
            if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== strtolower(pathinfo($previousName, PATHINFO_EXTENSION))) {
                throw new RuntimeException('为避免文件类型误导，请保留原文件扩展名');
            }
            $q = $pdo->prepare('UPDATE media_assets SET original_name=? WHERE id=?');
            $q->execute([$name, $id]);
            diag_event('info', 'media', 'rename', 'success', ['asset_id' => $id]);
            flash('素材名称已修改。');
        } else {
            throw new RuntimeException('操作不受支持');
        }
    } catch (Throwable $e) {
        diag_exception('media', $action === 'delete' ? 'delete' : 'rename', $e);
        flash($e->getMessage(), 'error');
    }
    redirect_to('/admin/media');
}

if ($path === '/admin/media' && $method === 'GET') {
    media_sync_legacy($pdo);
    $stmt = $pdo->query('SELECT * FROM media_assets ORDER BY id DESC LIMIT 250');
    $assets = $stmt->fetchAll();
    page_start('素材管理', '', true);
    echo '<main class="shell admin-main">';
    admin_heading('MEDIA LIBRARY', '素材管理', '统一管理图片和教程附件，支持上传、重命名、查看引用及安全删除。', '<a class="button secondary" href="/admin/guides">返回教程 →</a>');
    echo '<section class="admin-panel"><div class="media-toolbar"><label class="button primary media-upload-label">＋ 上传图片或附件<input id="media-upload-input" type="file" accept=".png,.jpg,.jpeg,.webp,.gif,.pdf,.txt,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip" multiple hidden></label><span class="muted">图片上限 6MB，文档上限 20MB；不支持可执行文件。删除被文章引用的素材会被阻止。</span></div><div id="media-status" class="editor-message" role="status"></div><div class="media-grid">';
    if (!$assets) echo '<p class="muted">还没有上传素材。</p>';
    foreach ($assets as $asset) {
        $url = $asset['url'];
        $used = media_usage($pdo, $url);
        echo '<article class="media-card"><div class="media-thumb">';
        if ($asset['kind'] === 'image') echo '<img loading="lazy" src="' . h($url) . '" alt="' . h($asset['original_name']) . '">';
        else echo '<span class="media-file-icon">📄</span>';
        echo '</div><div class="media-card-content"><strong title="' . h($asset['original_name']) . '">' . h($asset['original_name']) . '</strong><small>' . h(strtoupper(pathinfo($asset['original_name'], PATHINFO_EXTENSION))) . ' · ' . round((int)$asset['byte_size'] / 1024) . ' KB · 引用 ' . $used . ' 篇</small><div class="media-url"><input readonly value="' . h($url) . '" aria-label="素材网址"><button type="button" data-copy="' . h($url) . '">复制</button></div>';
        echo '<form method="post" action="/admin/media/action" class="media-rename">' . csrf_input() . '<input type="hidden" name="action" value="rename"><input type="hidden" name="id" value="' . (int)$asset['id'] . '"><input name="name" value="' . h($asset['original_name']) . '" aria-label="文件名称" maxlength="120"><button class="button secondary" type="submit">改名</button></form>';
        echo '<form method="post" action="/admin/media/action" data-confirm="确定删除这个文件吗？此操作不可撤销。" class="media-delete">' . csrf_input() . '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . (int)$asset['id'] . '"><button type="submit" class="button secondary text-danger"' . ($used > 0 ? ' disabled title="请先从引用文章中移除"' : '') . '>删除文件</button></form></div></article>';
    }
    echo '</div></section></main>';
    page_end(true);
    exit;
}

if ($path === '/admin') {
    $stats = $pdo->query("SELECT COUNT(*) AS total, SUM(CASE WHEN status='published' THEN 1 ELSE 0 END) AS published, SUM(CASE WHEN status='draft' THEN 1 ELSE 0 END) AS drafts, COALESCE(SUM(views),0) AS views FROM guides")->fetch();
    $recent = $pdo->query('SELECT g.*, c.name AS category_name FROM guides g LEFT JOIN categories c ON c.id=g.category_id ORDER BY g.updated_at DESC, g.id DESC LIMIT 7')->fetchAll();
    page_start('管理工作台', '', true);
    echo '<main class="shell admin-main">';
    admin_heading('OVERVIEW', '工作台', '在这里管理你的知识内容与教程发布。', '<a class="button primary" href="/admin/guides/new">＋ 新建教程</a>');
    echo '<div class="stats-grid"><div class="stat-box"><span>教程总数</span><strong>' . (int)$stats['total'] . '</strong><small>全部教程与攻略</small></div><div class="stat-box"><span>已发布</span><strong>' . (int)$stats['published'] . '</strong><small>读者可以公开查看</small></div><div class="stat-box"><span>草稿</span><strong>' . (int)$stats['drafts'] . '</strong><small>暂未公开</small></div><div class="stat-box"><span>累计浏览</span><strong>' . (int)$stats['views'] . '</strong><small>所有文章阅读量</small></div></div><section class="admin-panel"><div class="panel-head"><h2>最近编辑</h2><a href="/admin/guides">查看全部 →</a></div><div class="responsive-table"><table><thead><tr><th>教程名称</th><th>分类</th><th>状态</th><th>更新时间</th><th>操作</th></tr></thead><tbody>';
    foreach ($recent as $row) admin_guide_row($row);
    echo '</tbody></table></div></section></main>';
    page_end(true);
    exit;
}

if ($path === '/admin/guides' && $method === 'GET') {
    $rows = $pdo->query('SELECT g.*, c.name AS category_name FROM guides g LEFT JOIN categories c ON c.id=g.category_id ORDER BY g.updated_at DESC, g.id DESC')->fetchAll();
    page_start('教程管理', '', true);
    echo '<main class="shell admin-main">';
    admin_heading('CONTENT LIBRARY', '教程管理', '创建、编辑或下架图文教程。', '<a class="button primary" href="/admin/guides/new">＋ 新建教程</a>');
    echo '<section class="admin-panel"><div class="responsive-table"><table><thead><tr><th>教程名称</th><th>分类</th><th>状态</th><th>更新时间</th><th>操作</th></tr></thead><tbody>';
    foreach ($rows as $row) admin_guide_row($row);
    echo '</tbody></table></div></section></main>';
    page_end(true);
    exit;
}

if ($path === '/admin/guides/delete' && $method === 'POST') {
    check_csrf();
    $pdo->prepare('DELETE FROM guides WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
    diag_event('info', 'guides', 'delete', 'success', ['guide_id' => (int)($_POST['id'] ?? 0)]);
    flash('教程已删除。');
    redirect_to('/admin/guides');
}

if ($path === '/admin/guides/save' && $method === 'POST') {
    check_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 180);
    $slug = make_slug(mb_substr(trim((string)($_POST['slug'] ?? '')) ?: $title, 0, 180));
    $summary = mb_substr(trim((string)($_POST['summary'] ?? '')), 0, 450);
    $body = sanitize_content((string)($_POST['body_html'] ?? ''));
    $cover = safe_image((string)($_POST['cover_image'] ?? ''));
    $tags = mb_substr(trim((string)($_POST['tags'] ?? '')), 0, 255);
    $category = (int)($_POST['category_id'] ?? 0);
    $category = $category > 0 ? $category : null;
    $status = ($_POST['status'] ?? 'draft') === 'published' ? 'published' : 'draft';
    $featured = empty($_POST['featured']) ? 0 : 1;
    if ($title === '' || trim(strip_tags($body)) === '') {
        diag_event('warning', 'guides', 'save_validation', 'blocked', ['guide_id' => $id]);
        flash('请输入标题和正文内容。', 'error');
        redirect_to($id ? '/admin/guides/edit?id=' . $id : '/admin/guides/new');
    }
    try {
        if ($id > 0) {
            $sql = "UPDATE guides SET title=?, slug=?, summary=?, body_html=?, cover_image=?, category_id=?, tags=?, status=?, featured=?, updated_at=CURRENT_TIMESTAMP, published_at=CASE WHEN ?='published' AND published_at IS NULL THEN CURRENT_TIMESTAMP ELSE published_at END WHERE id=?";
            $pdo->prepare($sql)->execute([$title, $slug, $summary, $body, $cover, $category, $tags, $status, $featured, $status, $id]);
        } else {
            $sql = "INSERT INTO guides (title, slug, summary, body_html, cover_image, category_id, tags, status, featured, published_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CASE WHEN ?='published' THEN CURRENT_TIMESTAMP ELSE NULL END)";
            $pdo->prepare($sql)->execute([$title, $slug, $summary, $body, $cover, $category, $tags, $status, $featured, $status]);
            $id = (int)$pdo->lastInsertId();
        }
        diag_event('info', 'guides', 'save', 'success', ['guide_id' => $id]);
        flash('教程已保存。');
        redirect_to('/admin/guides/edit?id=' . $id);
    } catch (PDOException $e) {
        diag_exception('guides', 'save', $e);
        flash('保存失败：网址别名可能与已有教程重复，请换一个。', 'error');
        redirect_to($id ? '/admin/guides/edit?id=' . $id : '/admin/guides/new');
    }
}

if (($path === '/admin/guides/new' || $path === '/admin/guides/edit') && $method === 'GET') {
    $id = $path === '/admin/guides/edit' ? (int)($_GET['id'] ?? 0) : 0;
    $guide = ['id' => 0, 'title' => '', 'slug' => '', 'summary' => '', 'body_html' => '', 'cover_image' => '', 'category_id' => '', 'tags' => '', 'status' => 'draft', 'featured' => 0];
    if ($id > 0) {
        $stmt = $pdo->prepare('SELECT * FROM guides WHERE id = ?');
        $stmt->execute([$id]);
        $guide = $stmt->fetch();
        if (!$guide) error_page(404, '教程不存在', '请返回教程管理页面。');
    }
    $categories = $pdo->query('SELECT * FROM categories ORDER BY sort_order, id')->fetchAll();
    page_start($id ? '编辑教程' : '新建教程', '', true);
    echo '<main class="shell admin-main">';
    admin_heading('EDITOR', $id ? '编辑教程' : '新建教程', '用图文结合的方式，清楚地描述每一步操作。', '<a class="button secondary" href="/admin/guides">← 返回列表</a>');
    echo '<section class="admin-panel ai-import-panel"><div><span class="ai-import-kicker">网页复制 · AI 整理</span><h2>导入网页图文教程</h2><p class="muted">可以直接从其他网页复制图文到下方正文，也可以把完整 HTML（包括语雀 ne-p / ne-card 代码）粘贴在这里，通过 AI 提取标题、步骤与配图位置，自动创建一个新的未发布草稿。</p></div><label class="field">粘贴网页 HTML / 网页代码 / 文章内容<textarea id="ai-source-html" rows="5" maxlength="300000" placeholder="在这里粘贴 &lt;div class=&quot;ne-viewer-body&quot;&gt;... 或整个网页代码"></textarea></label><div class="ai-import-actions"><button id="ai-import-generate" class="button primary" type="button">AI 解析并创建文章草稿</button><a href="/admin/ai-settings" class="button secondary">配置 AI 供应商</a><span id="ai-import-status" class="muted" role="status"></span></div><p class="field-help">导入时会将素材内容提交到你配置的 AI 服务；不会发送管理员密码。生成结果保存为新的草稿，不覆盖当前文章，也不会公开发布。外链图片需另行转存到本站。</p></section>';
    echo '<form id="guide-form" method="post" action="/admin/guides/save" class="editor-layout">' . csrf_input() . '<input type="hidden" name="id" value="' . (int)$guide['id'] . '"><div class="editor-main admin-panel"><label class="field">教程标题<input name="title" required maxlength="180" placeholder="例如：TCL雷鸟电视查看和绑定酷狗账号" value="' . h($guide['title']) . '"></label><label class="field">简短介绍<textarea name="summary" rows="3" maxlength="450" placeholder="一句话告诉读者，这篇教程可以解决什么问题">' . h($guide['summary']) . '</textarea></label><div class="field"><span class="field-title">教程正文</span><p class="field-help">支持直接粘贴其他网页复制的图文（保留标题、段落、列表、表格与图片链接）。图片可以替换、删除、调整大小与顺序，也可以使用素材库。</p><div class="editor-toolbar" role="toolbar" aria-label="编辑器工具栏"><button type="button" data-command="formatBlock" data-value="h2">标题 H2</button><button type="button" data-command="formatBlock" data-value="h3">小标题 H3</button><button type="button" data-command="bold"><b>B</b></button><button type="button" data-command="italic"><i>I</i></button><button type="button" data-command="insertUnorderedList">• 列表</button><button type="button" data-command="insertOrderedList">1. 列表</button><button type="button" data-action="link">链接</button><button type="button" data-action="image">上传图片</button><button type="button" data-action="library">素材库</button><button type="button" data-action="replace-image">替换选中图片</button><button type="button" data-action="remove-image">删除选中图片</button><button type="button" data-action="image-alt">图片描述</button><button type="button" data-action="image-size">图片大小</button><button type="button" data-action="image-up">图片上移</button><button type="button" data-action="image-down">图片下移</button><button type="button" data-action="remote-image">图片网址</button><button type="button" data-command="removeFormat">清除格式</button><span class="editor-toolbar-divider"></span><button type="button" id="editor-toggle-source" class="editor-mode-button" aria-pressed="false">&lt;/&gt; HTML 源代码</button><button type="button" id="editor-preview-button" class="editor-mode-button">预览当前内容</button><button type="button" id="editor-ai-optimize" class="editor-ai-button">✦ AI 优化</button><button type="button" id="editor-ai-undo" class="editor-mode-button" hidden>撤销 AI 优化</button></div><div class="editor-area article-content" id="rich-editor" contenteditable="true" data-placeholder="从这里开始写教程，可以直接粘贴文本或图片…">' . sanitize_content($guide['body_html']) . '</div><textarea id="source-editor" class="editor-source" spellcheck="false" aria-label="教程 HTML 源代码" placeholder="可直接编辑 HTML 源代码" hidden></textarea><p id="source-editor-help" class="field-help" hidden>HTML 源码可直接修改，切回可视化或预览时会执行安全过滤。AI 优化结果会自动填入这里，保存文章前可检查修改。</p><textarea name="body_html" id="body-html" hidden></textarea><input type="file" accept="image/png,image/jpeg,image/webp,image/gif" id="image-picker" hidden><div class="editor-message" id="editor-message" aria-live="polite"></div><dialog id="editor-preview-dialog" class="editor-preview-dialog"><div class="dialog-header"><div><h3>未保存的内容预览</h3><p class="muted">只预览当前编辑器，不会修改已发布文章</p></div><button type="button" id="editor-preview-close" class="button secondary">关闭预览</button></div><iframe id="editor-preview-frame" sandbox="" referrerpolicy="no-referrer" title="当前文章预览"></iframe></dialog><dialog id="media-dialog" class="media-dialog"><div class="dialog-header"><h3>从素材库中选择</h3><button type="button" id="media-dialog-close" class="button secondary">关闭</button></div><div class="media-dialog-tools"><button type="button" data-media-kind="all">全部</button><button type="button" data-media-kind="image">图片</button><button type="button" data-media-kind="file">文档</button><label class="button secondary media-upload-label">＋ 上传<input id="dialog-upload" type="file" accept=".png,.jpg,.jpeg,.webp,.gif,.pdf,.txt,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip" multiple hidden></label></div><div id="media-dialog-list" class="media-dialog-list">正在加载…</div></dialog></div></div><aside class="editor-side"><div class="admin-panel editor-side-panel"><h3>发布设置</h3><label class="field">状态<select name="status"><option value="draft" ' . ($guide['status'] === 'draft' ? 'selected' : '') . '>草稿（不公开）</option><option value="published" ' . ($guide['status'] === 'published' ? 'selected' : '') . '>正式发布</option></select></label><label class="field">内容分类<select name="category_id"><option value="">未分类</option>';
    foreach ($categories as $cat) echo '<option value="' . (int)$cat['id'] . '" ' . ((int)$guide['category_id'] === (int)$cat['id'] ? 'selected' : '') . '>' . h($cat['name']) . '</option>';
    echo '</select></label><label class="check-field"><input type="checkbox" name="featured" value="1" ' . ($guide['featured'] ? 'checked' : '') . '> 在列表中优先展示</label><button class="button primary full" type="submit">保存教程 →</button>';
    if ($id && $guide['status'] === 'published') echo '<a href="/guide/' . h(rawurlencode($guide['slug'])) . '" target="_blank" class="button secondary full">预览已发布页面 ↗</a>';
    echo '</div><div class="admin-panel editor-side-panel"><h3>文章信息</h3><label class="field">网址别名<input name="slug" value="' . h($guide['slug']) . '" placeholder="留空自动生成"></label><label class="field">标签（逗号分隔）<input name="tags" value="' . h($guide['tags']) . '" placeholder="电视,软件,账户"></label><label class="field">封面图片网址<input name="cover_image" id="cover-image" value="' . h($guide['cover_image']) . '" placeholder="https://... 或 /uploads/..."></label><button type="button" id="cover-upload" class="button secondary full">上传封面图片</button><button type="button" id="cover-library" class="button secondary full">从素材库选择封面</button><button type="button" id="cover-clear" class="button secondary full">移除封面</button><div class="cover-preview" id="cover-preview">';
    if ($guide['cover_image']) echo '<img src="' . h(safe_image($guide['cover_image'])) . '" alt="封面预览">';
    echo '</div></div></aside></form></main>';
    page_end(true);
    exit;
}

if ($path === '/admin/categories' && $method === 'POST') {
    check_csrf();
    $action = (string)($_POST['action'] ?? 'save');
    $id = (int)($_POST['id'] ?? 0);
    try {
        if ($action === 'delete') {
            $pdo->prepare('DELETE FROM categories WHERE id=?')->execute([$id]);
            diag_event('info', 'categories', 'delete', 'success');
            flash('分类已删除，原分类下的文章将转为未分类。');
        } else {
            $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 80);
            $slug = make_slug(trim((string)($_POST['slug'] ?? '')) ?: $name);
            $description = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 240);
            if ($name === '') throw new RuntimeException('分类名称不能为空');
            if ($id > 0) {
                $pdo->prepare('UPDATE categories SET name=?, slug=?, description=? WHERE id=?')->execute([$name, $slug, $description, $id]);
            } else {
                $pdo->prepare('INSERT INTO categories (name, slug, description, sort_order) VALUES (?, ?, ?, 100)')->execute([$name, $slug, $description]);
            }
            diag_event('info', 'categories', 'save');
            flash('分类已保存。');
        }
    } catch (Throwable $e) {
        diag_exception('categories', 'save', $e);
        flash('操作失败：名称或别名可能重复。', 'error');
    }
    redirect_to('/admin/categories');
}

if ($path === '/admin/categories' && $method === 'GET') {
    $categories = $pdo->query('SELECT c.*, COUNT(g.id) AS guide_count FROM categories c LEFT JOIN guides g ON g.category_id=c.id GROUP BY c.id ORDER BY c.sort_order, c.id')->fetchAll();
    page_start('分类管理', '', true);
    echo '<main class="shell admin-main">';
    admin_heading('ORGANIZE', '分类管理', '给每一份教程一个清晰的归属。');
    echo '<div class="category-admin-layout"><section class="admin-panel"><div class="panel-head"><h2>现有分类</h2></div>';
    foreach ($categories as $cat) {
        echo '<div class="category-admin-item"><div><strong>' . h($cat['name']) . '</strong><small>/' . h($cat['slug']) . ' · ' . (int)$cat['guide_count'] . ' 篇</small><p>' . h($cat['description']) . '</p></div><div><a href="/admin/categories?edit=' . (int)$cat['id'] . '">编辑</a><form class="inline-form" method="post" action="/admin/categories" data-confirm="删除分类后，相关教程将变成未分类。确认删除？">' . csrf_input() . '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . (int)$cat['id'] . '"><button type="submit" class="text-danger">删除</button></form></div></div>';
    }
    echo '</section>';
    $editId = (int)($_GET['edit'] ?? 0);
    $edit = ['id' => 0, 'name' => '', 'slug' => '', 'description' => ''];
    foreach ($categories as $cat) if ((int)$cat['id'] === $editId) $edit = $cat;
    echo '<section class="admin-panel"><h2>' . ($edit['id'] ? '编辑分类' : '新建分类') . '</h2><form method="post" action="/admin/categories" class="stack-form">' . csrf_input() . '<input type="hidden" name="id" value="' . (int)$edit['id'] . '"><label>分类名称<input name="name" required value="' . h($edit['name']) . '" placeholder="例如：数码设备"></label><label>网址别名<input name="slug" value="' . h($edit['slug']) . '" placeholder="例如：devices"></label><label>分类描述<textarea rows="3" name="description">' . h($edit['description']) . '</textarea></label><button type="submit" class="button primary full">保存分类</button></form></section></div></main>';
    page_end(true);
    exit;
}

if ($path === '/admin/settings') {
    if ($method === 'POST') {
        check_csrf();
        set_setting('site_title', mb_substr(trim((string)($_POST['site_title'] ?? 'HowHub')), 0, 60));
        set_setting('site_description', mb_substr(trim((string)($_POST['site_description'] ?? '')), 0, 240));
        diag_event('info', 'settings', 'save');
        flash('站点设置已保存。');
        redirect_to('/admin/settings');
    }
    page_start('站点设置', '', true);
    echo '<main class="shell admin-main">';
    admin_heading('PREFERENCES', '站点设置', '自定义网站名称与介绍。');
    echo '<section class="admin-panel settings-panel"><form method="post" action="/admin/settings" class="stack-form">' . csrf_input() . '<label>网站名称<input name="site_title" required value="' . h(setting('site_title', 'HowHub')) . '"></label><label>网站简介<textarea name="site_description" rows="3">' . h(setting('site_description')) . '</textarea></label><button class="button primary" type="submit">保存设置</button></form><hr><h2>账户安全</h2><p class="muted">管理员密码可在下方修改。推荐使用至少 12 位的随机强密码。</p><a href="/admin/password" class="button secondary">修改登录密码 →</a></section></main>';
    page_end(true);
    exit;
}

if ($path === '/admin/password') {
    if ($method === 'POST') {
        check_csrf();
        $old = (string)($_POST['old_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id=?');
        $stmt->execute([$_SESSION['user_id']]);
        if (!password_verify($old, (string)$stmt->fetchColumn()) || strlen($new) < 12 || $new !== $confirm) {
            diag_event('warning', 'auth', 'password_change', 'blocked');
            flash('原密码错误，或新密码不符合要求（至少 12 位，且两次一致）。', 'error');
        } else {
            $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($new, PASSWORD_DEFAULT), $_SESSION['user_id']]);
            diag_event('info', 'auth', 'password_change');
            flash('登录密码已更新。');
        }
        redirect_to('/admin/password');
    }
    page_start('修改密码', '', true);
    echo '<main class="shell admin-main">';
    admin_heading('ACCOUNT SECURITY', '修改管理员密码', '保护后台账号安全。');
    echo '<section class="admin-panel settings-panel"><form method="post" action="/admin/password" class="stack-form">' . csrf_input() . '<label>当前密码<input type="password" name="old_password" required autocomplete="current-password"></label><label>新密码（至少 12 位）<input type="password" name="new_password" required minlength="12" autocomplete="new-password"></label><label>再次输入新密码<input type="password" name="confirm_password" required minlength="12" autocomplete="new-password"></label><button class="button primary" type="submit">更新密码</button></form></section></main>';
    page_end(true);
    exit;
}

error_page(404, '后台页面不存在', '请从工作台导航访问有效页面。');

function admin_guide_row(array $guide): void
{
    $isPublished = $guide['status'] === 'published';
    echo '<tr><td><strong>' . h($guide['title']) . '</strong><small>/' . h($guide['slug']) . '</small></td><td>' . h($guide['category_name'] ?: '未分类') . '</td><td><span class="status-pill ' . ($isPublished ? 'is-live' : 'is-draft') . '">' . ($isPublished ? '已发布' : '草稿') . '</span></td><td>' . h(substr($guide['updated_at'], 0, 16)) . '</td><td class="table-actions"><a href="/admin/guides/edit?id=' . (int)$guide['id'] . '">编辑</a><form class="inline-form" method="post" action="/admin/guides/delete" data-confirm="此操作会永久删除教程，确定吗？">' . csrf_input() . '<input type="hidden" name="id" value="' . (int)$guide['id'] . '"><button type="submit" class="text-danger">删除</button></form></td></tr>';
}
