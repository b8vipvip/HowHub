<?php
declare(strict_types=1);

/**
 * Diagnostic only: no external HTTP calls, AI requests, writes to articles,
 * migrations beyond normal database() startup, or application updates.
 * Values are safe to display to a logged-in admin or emit as CLI JSON.
 */
function agent_runtime_report(PDO $pdo): array
{
    $report=[
        'mode'=>setting('agent_creation_mode','topic_only'),
        'php'=>PHP_VERSION,
        'server_time'=>date(DATE_ATOM),
        'checks'=>[],
        'queues'=>[],
        'notes'=>[
            '该检查不调用外部搜索、图像模型或 TTS，也不证明模型 API 兼容',
            '生产视频生成仍需 FFmpeg 的 libx264、drawtext 以及可用的中文字库',
        ],
    ];
    $check=static function(string $name,bool $ok,string $message) use (&$report):void {
        $report['checks'][$name]=['ok'=>$ok,'message'=>$message];
    };
    foreach(['dom','mbstring','pdo_sqlite','curl','openssl','fileinfo'] as $ext) {
        $check('php_'.$ext,extension_loaded($ext),
            extension_loaded($ext)?'PHP 扩展已启用':'缺少 PHP '.$ext.' 扩展');
    }
    $ffmpeg=url_import_bin('ffmpeg');
    $check('ffmpeg',$ffmpeg!=='',$ffmpeg!==''?'已找到 FFmpeg':'未安装 FFmpeg，视频合成不可用');
    if ($ffmpeg!=='') {
        foreach([
            'libx264'=>[['-hide_banner','-encoders'],'libx264'],
            'drawtext'=>[['-hide_banner','-filters'],'drawtext'],
        ] as $name=>[$args,$needle]) {
            try {
                $raw=url_import_process(array_merge([$ffmpeg],$args),8,350000);
                $ok=str_contains($raw,$needle);
                $check('ffmpeg_'.$name,$ok,$ok?'已发现 '.$name:'FFmpeg 缺少 '.$name.'，暂不支持原创字幕视频');
            } catch(Throwable $e) {
                $check('ffmpeg_'.$name,false,'无法检测 '.$name.'：'.get_class($e));
            }
        }
    } else {
        $check('ffmpeg_libx264',false,'跳过：FFmpeg 不存在');
        $check('ffmpeg_drawtext',false,'跳过：FFmpeg 不存在');
    }
    try {
        $font=agent_topic_video_font('原创教学视频');
        $check('font_cjk',true,'已找到可用中文字库：'.basename($font));
    } catch(Throwable $e) {
        $check('font_cjk',false,'缺少中文字体；建议安装 fonts-noto-cjk');
    }
    $mediaRoot=media_directory('file');
    $parent=dirname($mediaRoot);
    $check('media_directory',is_dir($mediaRoot) ? is_writable($mediaRoot) :
        (is_dir($parent) && is_writable($parent)),
        '原创 MP3/MP4 必须能写入私有 storage/media_files');
    $uploads=media_directory('image');
    $check('image_directory',is_dir($uploads) && is_writable($uploads),
        '原创插画需要 public/uploads 可写');
    $config=ai_config();
    $check('ai_configured',!empty($config['configured']),
        !empty($config['configured'])?'已配置 AI 供应商（未测试语音和图像接口）':'尚未配置 AI 供应商');
    $report['checks']['search_api']=[
        'ok'=>agent_research_api_configured(),
        'message'=>agent_research_api_configured()?'已配置搜索 API（未连网测试）':'搜索 API 未配置，可禁用交叉检索',
    ];
    foreach([
        'queued'=>"SELECT COUNT(*) FROM agent_topic_ideas WHERE status='queued'",
        'review'=>"SELECT COUNT(*) FROM agent_topic_ideas WHERE status='review'",
        'quality_pending'=>"SELECT COUNT(*) FROM agent_topic_quality WHERE status='pending'",
        'quality_attention'=>"SELECT COUNT(*) FROM agent_topic_quality WHERE status IN ('needs_review','error')",
        'image_pending'=>"SELECT COUNT(*) FROM agent_topic_ideas WHERE asset_status='pending' AND status='review'",
        'audio_pending'=>"SELECT COUNT(*) FROM agent_topic_av WHERE audio_status='pending'",
        'video_pending'=>"SELECT COUNT(*) FROM agent_topic_av WHERE video_status='pending'",
    ] as $name=>$sql) {
        try {$report['queues'][$name]=(int)$pdo->query($sql)->fetchColumn();}
        catch(Throwable $e) {$report['queues'][$name]=null;}
    }
    $report['enabled']=[
        'agent'=>setting('agent_enabled','0')==='1',
        'source_research'=>setting('agent_auto_source_enabled','0')==='1',
        'image'=>setting('agent_original_image_enabled','0')==='1',
        'audio'=>setting('agent_original_audio_enabled','0')==='1',
        'video'=>setting('agent_original_video_enabled','0')==='1',
        'crosscheck'=>setting('agent_fact_search_enabled','0')==='1',
    ];
    return $report;
}
