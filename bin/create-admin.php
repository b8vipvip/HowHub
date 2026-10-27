<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/app/bootstrap.php';
$username = $argv[1] ?? 'admin';
if (!preg_match('/^[a-zA-Z0-9_-]{3,40}$/', $username)) {
    fwrite(STDERR, "用户名必须为 3-40 位英文、数字、下划线或横线。\n");
    exit(1);
}
echo "创建/更新管理员：{$username}\n请输入密码（至少 12 位，输入时不回显）：";
$echoOff = false;
if (DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec')) {
    @shell_exec('stty -echo 2>/dev/null');
    $echoOff = true;
}
$password = trim((string)fgets(STDIN));
if ($echoOff) @shell_exec('stty echo 2>/dev/null');
echo "\n";
if (strlen($password) < 12) {
    fwrite(STDERR, "密码不足 12 位，未作任何修改。\n");
    exit(1);
}
$pdo = database();
$stmt = $pdo->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?) ON CONFLICT(username) DO UPDATE SET password_hash=excluded.password_hash');
$stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
echo "管理员就绪。后台入口：/admin/login\n";
