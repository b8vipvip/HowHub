<?php
declare(strict_types=1);

function page_start(string $title, string $description = '', bool $admin = false, bool $showHeader = true): void
{
    $site = setting('site_title', 'HowHub');
    $description = $description !== '' ? $description : setting('site_description');
    $fullTitle = $title === '' ? $site . ' · 每一步，都讲清楚' : $title . ' · ' . $site;
    $nav = $admin ? '<a href="/admin">工作台</a><a href="/admin/guides">教程管理</a><a href="/admin/categories">分类管理</a><a href="/admin/media">素材管理</a><a href="/admin/ai-settings">AI 供应商</a><a href="/admin/settings">站点设置</a><a href="/admin/diagnostics">诊断日志</a><a href="/" target="_blank">查看网站 ↗</a>' : '<a href="/#guides">全部文章</a><a href="/#categories">分类</a>';
    $auth = $admin ? '<form class="logout" action="/admin/logout" method="post">' . csrf_input() . '<button type="submit">退出登录</button></form>' : '<a class="nav-action" href="/admin">管理后台 <span aria-hidden="true">↗</span></a>';
    $class = $admin ? 'admin-page' : 'site-page';
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#f6f8fc"><meta name="description" content="' . h($description) . '"><title>' . h($fullTitle) . '</title><link rel="stylesheet" href="/assets/style.css?v=' . (int)@filemtime(HOWHUB_ROOT . '/public/assets/style.css') . '"></head><body class="' . $class . '">';
    if ($showHeader) {
    echo '<header class="topbar"><div class="shell nav-inner"><a href="/" class="brand" aria-label="HowHub 首页"><span class="brand-logo"><span class="brand-mark">H</span></span><span>' . h($site) . '</span><span class="brand-dot">.</span></a><nav class="nav-links" aria-label="主导航">' . $nav . '</nav><div class="nav-right">' . $auth . '</div></div></header>';
    }
    if (isset($GLOBALS['howhub_read_flash']) || isset($_SESSION['flash'])) {
        $message = $GLOBALS['howhub_read_flash'] ?? $_SESSION['flash'];
        unset($GLOBALS['howhub_read_flash'], $_SESSION['flash']);
        echo '<div class="shell"><div class="flash flash-' . h($message['type']) . '">' . h($message['text']) . '</div></div>';
    }
}

function page_end(bool $admin = false): void
{
    echo '<footer class="footer"><div class="shell footer-content"><div><div class="footer-brand">HowHub<span>.</span></div><p>让每一步操作，都简单明了。</p></div><div class="footer-links"><span>© ' . date('Y') . ' HowHub</span><a href="/">返回首页</a>' . ($admin ? '' : '<a href="/admin/login">管理入口</a>') . '</div></div></footer><script src="/assets/site.js?v=' . (int)@filemtime(HOWHUB_ROOT . '/public/assets/site.js') . '" defer></script></body></html>';
}

function guide_card(array $guide): void
{
    $url = '/guide/' . rawurlencode($guide['slug']);
    $cover = safe_image((string)$guide['cover_image']);
    $image = $cover !== '' ? '<img loading="lazy" src="' . h($cover) . '" alt="' . h($guide['title']) . '">' : '<div class="card-placeholder">H<span>.</span></div>';
    $category = $guide['category_name'] ?: '实用指南';
    $published = $guide['published_at'] ?: $guide['created_at'];
    echo '<article class="guide-card"><a class="guide-cover" href="' . h($url) . '">' . $image . '<span class="cover-arrow">↗</span></a><div class="guide-card-content"><div class="card-meta"><span class="eyebrow">' . h($category) . '</span><span>' . h(substr((string)$published, 0, 10)) . '</span></div><h3><a href="' . h($url) . '">' . h($guide['title']) . '</a></h3><p>' . h($guide['summary']) . '</p><a class="read-link" href="' . h($url) . '">阅读指南 <span>→</span></a></div></article>';
}

function admin_heading(string $eyebrow, string $title, string $subtitle = '', string $action = ''): void
{
    echo '<div class="admin-head"><div><div class="kicker">' . h($eyebrow) . '</div><h1>' . h($title) . '</h1><p>' . h($subtitle) . '</p></div>' . $action . '</div>';
}

function error_page(int $code, string $title, string $message): never
{
    http_response_code($code);
    page_start($title);
    echo '<main class="shell empty-state error-state"><span class="error-number">' . $code . '</span><h1>' . h($title) . '</h1><p>' . h($message) . '</p><a class="button primary" href="/">返回首页 →</a></main>';
    page_end();
    exit;
}
