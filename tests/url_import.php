<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

$body = <<<'HTML'
<html><head><title>电视播放器设置教程</title><meta name="description" content="介绍完成电视应用绑定的具体操作。"></head><body>
<nav>广告菜单</nav><article>
<h2>如何配置电视上的播放器</h2>
<p>第一步：打开设备设置页面，找到音乐播放选项。</p>
<p>第二步：进入账号管理，点击绑定按钮。</p>
<p>第三步：根据界面要求完成绑定并确认状态。</p>
<p><a href="../support">查看完整说明</a></p>
</article></body></html>
HTML;
$fixture = static function (string $url) use ($body): array {
    if (str_ends_with($url, '/entry')) return ['status'=>302,'body'=>'','headers'=>['Location'=>'guides/setup.html']];
    if (str_ends_with($url, '/guides/setup.html')) {
        return ['status'=>200,'body'=>$body,'headers'=>['Content-Type'=>'text/html; charset=UTF-8']];
    }
    throw new RuntimeException('意外的模拟请求');
};
$response = url_import_fetch('https://docs.example.com/entry', 200000, $fixture);
if ($response['url'] !== 'https://docs.example.com/guides/setup.html' || $response['type'] !== 'text/html') {
    throw new RuntimeException('HTTP 重定向或 MIME 解析错误');
}
$source = url_import_prepare(database(), 'https://docs.example.com/entry', null, $fixture);
if ($source['source_kind'] !== 'webpage' || count($source['links']) !== 2 ||
    $source['links'][0]['href'] !== 'https://docs.example.com/support' ||
    $source['links'][1]['href'] !== 'https://docs.example.com/guides/setup.html' ||
    str_contains($source['content'], '广告菜单')) {
    throw new RuntimeException('网页导入没有正确保留正文和来源链接');
}
try {
    url_import_fetch('https://docs.example.com/start', 200000,
        static fn(string $url): array => ['status'=>302,'body'=>'','headers'=>['Location'=>'http://127.0.0.1/private']]);
    throw new LogicException('接受了内部 IP 重定向');
} catch (InvalidArgumentException $e) { /* rejected */ }

$videoPage = <<<'HTML'
<html><head><title>设备连线视频教程</title><meta property="og:type" content="video.other">
<meta property="og:description" content="视频中依次介绍连接线缆、进入设置、选择频道和确认连接的操作。"></head>
<body><article><h2>连接教程</h2><p>介绍电视的连接步骤和操作方法。</p>
<video><track kind="captions" src="/subs/guide.vtt" srclang="zh"></video></article></body></html>
HTML;
$captions = "WEBVTT\n\n00:00:01.000 --> 00:00:03.000\n第一步打开电视设置页面，进入网络设置选项。\n\n00:00:04.000 --> 00:00:06.000\n第二步按下确认并等待连接提示。\n\n00:00:08.000 --> 00:00:10.000\n第三步检查状态并返回主页，确认连接成功。还应检查设备显示的网络状态，确认已经正常连接。";
$videoFixture = static function (string $url) use ($videoPage, $captions): array {
    if (str_ends_with($url, '/video.html')) return ['status'=>200,'body'=>$videoPage,'headers'=>['content-type'=>'text/html']];
    if (str_ends_with($url, '/subs/guide.vtt')) return ['status'=>200,'body'=>$captions,'headers'=>['content-type'=>'text/vtt']];
    throw new RuntimeException('未预期的视频资源地址');
};
$video = url_import_prepare(database(), 'https://videos.example.com/video.html', null, $videoFixture);
if ($video['source_kind'] !== 'video_page' || !str_contains($video['content'], '第三步检查状态') ||
    !str_contains($video['content'], '[[LINK_1]]')) {
    throw new RuntimeException('视频字幕转教程素材失败');
}
$bad = static fn(string $url): array => ['status'=>200,'body'=>'<html><head><meta property="og:type" content="video.other"><title>好看的视频</title></head><body>简介</body></html>','headers'=>['content-type'=>'text/html']];
try {
    url_import_prepare(database(), 'https://videos.example.com/watch', null, $bad);
    throw new LogicException('视频无证据却允许导入');
} catch (RuntimeException $e) {
    if (!str_contains($e->getMessage(), '字幕')) throw $e;
}
if (url_import_caption_text($captions) === '' || !str_contains(url_import_caption_text($captions), '第一步')) {
    throw new RuntimeException('字幕文本转换异常');
}
foreach (['http://127.0.0.1/', 'http://localhost/', 'http://169.254.169.254/latest/meta-data/',
          'http://example.com:9999/a'] as $url) {
    try {
        url_import_fetch($url);
        throw new LogicException('不安全 URL 未拦截');
    } catch (Throwable $error) {
        if ($error instanceof LogicException) throw $error;
    }
}
echo "HowHub URL import passed: redirects / article / provenance / relative links / video captions / insufficient video / private IP\n";
