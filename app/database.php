<?php
declare(strict_types=1);

function database(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if (!extension_loaded('pdo_sqlite')) {
        throw new RuntimeException('PHP 未启用 pdo_sqlite 扩展，请在宝塔 PHP 扩展管理中安装 SQLite3 / pdo_sqlite。');
    }
    $dataDir = HOWHUB_ROOT . '/storage';
    if (!is_dir($dataDir) && !mkdir($dataDir, 0770, true) && !is_dir($dataDir)) {
        throw new RuntimeException('无法创建 storage 目录');
    }
    $pdo = new PDO('sqlite:' . $dataDir . '/howhub.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at TEXT
);
CREATE TABLE IF NOT EXISTS categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    slug TEXT NOT NULL UNIQUE,
    description TEXT NOT NULL DEFAULT '',
    sort_order INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS guides (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    summary TEXT NOT NULL DEFAULT '',
    body_html TEXT NOT NULL DEFAULT '',
    cover_image TEXT NOT NULL DEFAULT '',
    category_id INTEGER REFERENCES categories(id) ON DELETE SET NULL,
    tags TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft', 'published')),
    featured INTEGER NOT NULL DEFAULT 0,
    views INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    published_at TEXT
);
CREATE INDEX IF NOT EXISTS guides_status_date_idx ON guides(status, published_at DESC);
CREATE INDEX IF NOT EXISTS guides_category_idx ON guides(category_id);
CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);
SQL);
    seed_howhub($pdo);
    return $pdo;
}

function setting(string $key, string $default = ''): string
{
    $stmt = database()->prepare('SELECT value FROM settings WHERE key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : (string)$value;
}

function set_setting(string $key, string $value): void
{
    $stmt = database()->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $stmt->execute([$key, $value]);
}

function make_slug(string $input): string
{
    $input = trim(mb_strtolower($input, 'UTF-8'));
    $result = preg_replace('/[^\p{L}\p{N}]+/u', '-', $input);
    $result = trim((string)$result, '-');
    return $result !== '' ? $result : 'guide-' . bin2hex(random_bytes(4));
}

function seed_howhub(PDO $pdo): void
{
    // 只在首次初始化时导入示例，之后即使用户删光教程也不能自动恢复。
    $seeded = $pdo->query("SELECT value FROM settings WHERE key='seed_version'")->fetchColumn();
    if ($seeded !== false) return;
    $count = (int)$pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn();
    if ($count === 0) {
        $stmt = $pdo->prepare('INSERT OR IGNORE INTO categories (name, slug, description, sort_order) VALUES (?, ?, ?, ?)');
        foreach ([
            ['设备与电视', 'devices', '电视、投影仪、数码设备的操作教程', 10],
            ['软件与应用', 'software', '软件安装、设置和操作方法', 20],
            ['账号与会员', 'accounts', '账号绑定、会员服务与充值指南', 30],
            ['生活攻略', 'lifestyle', '实用技巧与生活指南', 40],
        ] as $cat) {
            $stmt->execute($cat);
        }
    }
    $count = (int)$pdo->query('SELECT COUNT(*) FROM guides')->fetchColumn();
    if ($count !== 0) {
        $pdo->exec("INSERT OR IGNORE INTO settings (key, value) VALUES ('seed_version', '1')");
        return;
    }
    $urls = [
        'https://cdn.nlark.com/yuque/0/2025/png/27258103/1760958527127-397fcf90-56c3-4276-baa3-da960dc1ff85.png',
        'https://cdn.nlark.com/yuque/0/2025/png/27258103/1760958526750-64361ee2-1bdd-41b5-9d4a-068f5d643ce8.png',
        'https://cdn.nlark.com/yuque/0/2025/png/27258103/1760958528020-a7efb27a-a4a9-4430-99d7-3b6aeaf77502.png',
        'https://cdn.nlark.com/yuque/0/2025/png/27258103/1760958526446-13bf2dc7-6a21-4b1a-820e-2f81f5528610.png',
        'https://cdn.nlark.com/yuque/0/2025/png/27258103/1760958526780-a51ee332-7778-4f0a-9037-88cd840d6b58.png',
    ];
    $figure = static function (string $src, string $alt): string {
        return '<figure><img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') . '"></figure>';
    };
    $body = '<h2>第一步：打开音乐会员界面</h2>'
        . '<p>电视打开开通音乐会员界面，按照下图提示点进去。</p>'
        . $figure($urls[0], '电视开通音乐会员操作图一')
        . $figure($urls[1], '电视开通音乐会员操作图二')
        . $figure($urls[2], '电视开通音乐会员操作图三')
        . '<h2>第二步：使用手机扫码</h2>'
        . '<p>用手机浏览器或者支付宝扫码，<strong>不要用微信</strong>。</p>'
        . $figure($urls[3], '手机扫码绑定页面')
        . '<h2>第三步：通过手机号绑定登录</h2>'
        . '<p>用你手机号验证码绑定登录，<strong>不要点微信图标绑定</strong>。登录后把你手机号和下图界面拍照发给我帮您充值。</p>'
        . $figure($urls[4], '手机号绑定登录页面')
        . '<blockquote><p>安全提示：短信验证码仅用于在官方页面登录，请不要将验证码发送给其他人。</p></blockquote>';
    $categoryId = (int)$pdo->query("SELECT id FROM categories WHERE slug='devices' LIMIT 1")->fetchColumn();
    $insert = $pdo->prepare("INSERT OR IGNORE INTO guides (title, slug, summary, body_html, cover_image, category_id, tags, status, featured, published_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'published', 1, CURRENT_TIMESTAMP)");
    $insert->execute([
        'TCL雷鸟电视查看和绑定酷狗账号',
        'tcl-leiniao-kugou-account',
        '使用电视音乐会员入口、手机扫码和手机号验证码，完成 TCL 雷鸟电视酷狗账号绑定。',
        $body,
        $urls[0],
        $categoryId,
        'TCL,雷鸟电视,酷狗音乐,账号绑定,音乐会员',
    ]);
    foreach (['site_title' => 'HowHub', 'site_description' => '把复杂步骤讲简单，让每份指南都能轻松上手。', 'seed_version' => '1'] as $key => $value) {
        $stmt = $pdo->prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)');
        $stmt->execute([$key, $value]);
    }
}
