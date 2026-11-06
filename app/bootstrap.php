<?php
declare(strict_types=1);

const HOWHUB_ROOT = __DIR__ . '/..';
date_default_timezone_set('Asia/Shanghai');
require_once __DIR__ . '/diagnostics.php';
diag_install();

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('HOWHUBSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/media.php';
require_once __DIR__ . '/remote_images.php';
require_once __DIR__ . '/import_urls.php';
require_once __DIR__ . '/import_resources.php';
require_once __DIR__ . '/import_walk.php';
require_once __DIR__ . '/html_import_engine.php';
require_once __DIR__ . '/import_embedded.php';
require_once __DIR__ . '/ai_import.php';
require_once __DIR__ . '/url_import_assets.php';
require_once __DIR__ . '/url_import_fetch.php';
require_once __DIR__ . '/url_import_extract.php';
require_once __DIR__ . '/url_import_video.php';
require_once __DIR__ . '/url_import_document.php';
require_once __DIR__ . '/url_import_pipeline.php';
require_once __DIR__ . '/url_import_ai.php';
require_once __DIR__ . '/agent_research_store.php';
require_once __DIR__ . '/agent_source_research.php';
require_once __DIR__ . '/agent_research_tick.php';
require_once __DIR__ . '/agent_claim_checks.php';
require_once __DIR__ . '/agent_topic_quality.php';
require_once __DIR__ . '/agent_topic_av_store.php';
require_once __DIR__ . '/agent_ai_video.php';
require_once __DIR__ . '/agent_fact_documents.php';
require_once __DIR__ . '/agent_topic_audio.php';
require_once __DIR__ . '/agent_topic_video.php';
require_once __DIR__ . '/agent_topic_store.php';
require_once __DIR__ . '/agent_topic_questions.php';
require_once __DIR__ . '/agent_topic_ai.php';
require_once __DIR__ . '/agent_topic_image.php';
require_once __DIR__ . '/agent_topic_scheduler.php';
require_once __DIR__ . '/agent_topic_admin.php';
require_once __DIR__ . '/agent_store.php';
require_once __DIR__ . '/agent_robots.php';
require_once __DIR__ . '/agent_discovery.php';
require_once __DIR__ . '/agent_formats.php';
require_once __DIR__ . '/agent_research_admin.php';
require_once __DIR__ . '/agent_audio.php';
require_once __DIR__ . '/agent_math_render.php';
require_once __DIR__ . '/agent_rich_diagrams.php';
require_once __DIR__ . '/agent_runtime.php';
require_once __DIR__ . '/agent_attribution.php';
require_once __DIR__ . '/agent_ai.php';
require_once __DIR__ . '/agent_review.php';
require_once __DIR__ . '/agent_media.php';
require_once __DIR__ . '/agent_scheduler.php';
require_once __DIR__ . '/agent_admin.php';
require_once __DIR__ . '/views.php';
