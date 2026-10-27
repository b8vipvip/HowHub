<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

try {
    $pdo = database();
} catch (Throwable $e) {
    error_log('[HowHub startup] ' . $e->getMessage());
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
        } else {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
            $stmt->execute([trim((string)($_POST['username'] ?? ''))]);
            $user = $stmt->fetch();
            if ($user && password_verify((string)($_POST['password'] ?? ''), $user['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['login_attempts'] = [];
                $pdo->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id=?')->execute([$user['id']]);
                redirect_to('/admin');
            }
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

if ($path === '/admin/logout' && $method === 'POST') {
    check_csrf();
    $_SESSION = [];
    session_regenerate_id(true);
    redirect_to('/admin/login');
}

if ($path === '/admin/upload' && $method === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        check_csrf();
        $file = $_FILES['image'] ?? null;
        if (!is_array($file) || $file['error'] !== UPLOAD_ERR_OK || (int)$file['size'] <= 0 || (int)$file['size'] > 6 * 1024 * 1024) {
            throw new RuntimeException('请选择小于 6MB 的图片');
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        $formats = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        if (!isset($formats[$mime]) || !getimagesize($file['tmp_name'])) {
            throw new RuntimeException('仅支持 PNG、JPG、WebP、GIF 图片');
        }
        $subdir = date('Y/m');
        $directory = __DIR__ . '/uploads/' . $subdir;
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) throw new RuntimeException('上传目录没有写入权限');
        $name = bin2hex(random_bytes(16)) . '.' . $formats[$mime];
        if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $name)) throw new RuntimeException('图片保存失败');
        echo json_encode(['ok' => true, 'url' => '/uploads/' . $subdir . '/' . $name], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
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
        flash('教程已保存。');
        redirect_to('/admin/guides/edit?id=' . $id);
    } catch (PDOException $e) {
        error_log('[HowHub guide save] ' . $e->getMessage());
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
    echo '<form id="guide-form" method="post" action="/admin/guides/save" class="editor-layout">' . csrf_input() . '<input type="hidden" name="id" value="' . (int)$guide['id'] . '"><div class="editor-main admin-panel"><label class="field">教程标题<input name="title" required maxlength="180" placeholder="例如：TCL雷鸟电视查看和绑定酷狗账号" value="' . h($guide['title']) . '"></label><label class="field">简短介绍<textarea name="summary" rows="3" maxlength="450" placeholder="一句话告诉读者，这篇教程可以解决什么问题">' . h($guide['summary']) . '</textarea></label><div class="field"><span class="field-title">教程正文</span><p class="field-help">支持标题、加粗、列表、插入图片、引用、链接等富文本内容。</p><div class="editor-toolbar" role="toolbar" aria-label="编辑器工具栏"><button type="button" data-command="formatBlock" data-value="h2">标题 H2</button><button type="button" data-command="formatBlock" data-value="h3">小标题 H3</button><button type="button" data-command="bold"><b>B</b></button><button type="button" data-command="italic"><i>I</i></button><button type="button" data-command="insertUnorderedList">• 列表</button><button type="button" data-command="insertOrderedList">1. 列表</button><button type="button" data-action="link">链接</button><button type="button" data-action="image">上传图片</button><button type="button" data-action="remote-image">图片网址</button><button type="button" data-command="removeFormat">清除格式</button></div><div class="editor-area article-content" id="rich-editor" contenteditable="true" data-placeholder="从这里开始写教程，可以直接粘贴文本或图片…">' . sanitize_content($guide['body_html']) . '</div><textarea name="body_html" id="body-html" hidden></textarea><input type="file" accept="image/png,image/jpeg,image/webp,image/gif" id="image-picker" hidden><div class="editor-message" id="editor-message" aria-live="polite"></div></div></div><aside class="editor-side"><div class="admin-panel editor-side-panel"><h3>发布设置</h3><label class="field">状态<select name="status"><option value="draft" ' . ($guide['status'] === 'draft' ? 'selected' : '') . '>草稿（不公开）</option><option value="published" ' . ($guide['status'] === 'published' ? 'selected' : '') . '>正式发布</option></select></label><label class="field">内容分类<select name="category_id"><option value="">未分类</option>';
    foreach ($categories as $cat) echo '<option value="' . (int)$cat['id'] . '" ' . ((int)$guide['category_id'] === (int)$cat['id'] ? 'selected' : '') . '>' . h($cat['name']) . '</option>';
    echo '</select></label><label class="check-field"><input type="checkbox" name="featured" value="1" ' . ($guide['featured'] ? 'checked' : '') . '> 在列表中优先展示</label><button class="button primary full" type="submit">保存教程 →</button>';
    if ($id && $guide['status'] === 'published') echo '<a href="/guide/' . h(rawurlencode($guide['slug'])) . '" target="_blank" class="button secondary full">预览已发布页面 ↗</a>';
    echo '</div><div class="admin-panel editor-side-panel"><h3>文章信息</h3><label class="field">网址别名<input name="slug" value="' . h($guide['slug']) . '" placeholder="留空自动生成"></label><label class="field">标签（逗号分隔）<input name="tags" value="' . h($guide['tags']) . '" placeholder="电视,软件,账户"></label><label class="field">封面图片网址<input name="cover_image" id="cover-image" value="' . h($guide['cover_image']) . '" placeholder="https://... 或 /uploads/..."></label><button type="button" id="cover-upload" class="button secondary full">上传封面图片</button><div class="cover-preview" id="cover-preview">';
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
            flash('分类已保存。');
        }
    } catch (Throwable $e) {
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
            flash('原密码错误，或新密码不符合要求（至少 12 位，且两次一致）。', 'error');
        } else {
            $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($new, PASSWORD_DEFAULT), $_SESSION['user_id']]);
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
