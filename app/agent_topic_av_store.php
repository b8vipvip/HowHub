<?php
declare(strict_types=1);

/** Independent audiovisual jobs; none are permitted to copy source-site media. */
function agent_topic_av_tables(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS agent_topic_av (
 idea_id INTEGER PRIMARY KEY REFERENCES agent_topic_ideas(id) ON DELETE CASCADE,
 audio_status TEXT NOT NULL DEFAULT 'off' CHECK(audio_status IN ('off','pending','complete','failed','skipped')),
 video_status TEXT NOT NULL DEFAULT 'off' CHECK(video_status IN ('off','pending','complete','failed','skipped')),
 audio_url TEXT NOT NULL DEFAULT '',
 video_url TEXT NOT NULL DEFAULT '',
 notes TEXT NOT NULL DEFAULT ''
);
CREATE TABLE IF NOT EXISTS agent_topic_av_runs (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 kind TEXT NOT NULL CHECK(kind IN ('audio','video')),
 idea_id INTEGER NOT NULL,
 requested_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS agent_topic_av_runs_recent ON agent_topic_av_runs(kind,requested_at);
SQL);
}

function agent_topic_av_budget(PDO $pdo,string $kind,int $id,?int $now=null): bool
{
    if (!in_array($kind,['audio','video'],true)) return false;
    $now??=time();
    $limit=$kind==='audio'?
        min(8,max(0,(int)setting('agent_original_audio_daily','2'))) :
        min(8,max(0,(int)setting('agent_original_video_daily','2')));
    if ($limit===0) return false;
    $begun=false;
    try {
        $pdo->exec('BEGIN IMMEDIATE');$begun=true;
        $q=$pdo->prepare('SELECT COUNT(*) FROM agent_topic_av_runs WHERE kind=? AND requested_at>?');
        $q->execute([$kind,$now-86400]);
        if ((int)$q->fetchColumn()>=$limit) {
            $pdo->exec('COMMIT');$begun=false;return false;
        }
        $pdo->prepare('INSERT INTO agent_topic_av_runs(kind,idea_id,requested_at) VALUES (?,?,?)')
            ->execute([$kind,$id,$now]);
        $pdo->exec('COMMIT');$begun=false;return true;
    } catch(Throwable $e) {
        if ($begun) $pdo->exec('ROLLBACK');
        throw $e;
    }
}

/**
 * Private, download-only media. The file route itself handles publication
 * permission; no video or audio is written into the public uploads directory.
 */
function agent_topic_generated_file(PDO $pdo,string $bytes,string $kind): string
{
    $ext=$kind==='audio'?'mp3':($kind==='video'?'mp4':'');
    if ($ext==='') throw new InvalidArgumentException('原创多媒体格式不支持');
    $size=strlen($bytes);
    if ($size<128 || $size>18*1024*1024) throw new RuntimeException('原创媒体内容大小无效');
    if ($kind==='video' && !str_contains(substr($bytes,0,24),'ftyp')) {
        throw new RuntimeException('原创视频不是有效的 MP4 容器');
    }
    if ($kind==='audio' && !str_starts_with($bytes,'ID3') &&
        !(ord($bytes[0])===255 && (ord($bytes[1])&224)===224)) {
        throw new RuntimeException('原创音频不是有效的 MP3 数据');
    }
    $folder=date('Y/m');
    $directory=media_directory('file').'/'.$folder;
    if (!is_dir($directory) && !mkdir($directory,0770,true) && !is_dir($directory)) {
        throw new RuntimeException('文件素材目录不可写');
    }
    $relative=$folder.'/'.bin2hex(random_bytes(16)).'.'.$ext;
    $path=media_directory('file').'/'.$relative;
    if (file_put_contents($path,$bytes,LOCK_EX)!==$size) {
        @unlink($path);throw new RuntimeException('原创媒体写入不完整');
    }
    @chmod($path,0600);
    try {
        $asset=media_register($pdo,'file',$relative,
            'AI原创教程'.($kind==='video'?'演示视频.mp4':'配音.mp3'),
            $kind==='video'?'video/mp4':'audio/mpeg',$size);
        return (string)$asset['url'];
    } catch(Throwable $e) {
        @unlink($path);throw $e;
    }
}

function agent_topic_av_append(PDO $pdo,int $id,string $kind,string $url): void
{
    if (!in_array($kind,['audio','video'],true)) throw new InvalidArgumentException('媒体类型无效');
    $link=$kind==='audio'?'下载本站原创教程配音（MP3）':'下载本站原创知识演示视频（MP4）';
    $notice=$kind==='audio' ? 'AI 生成配音，请核对读音与表达' :
        '根据原创文章合成的字幕演示，不是真实设备操作录像';
    $query=$pdo->prepare('SELECT html FROM agent_topic_ideas WHERE id=?');
    $query->execute([$id]);
    $html=(string)$query->fetchColumn();
    if ($html==='') throw new RuntimeException('关联原创文章不存在');
    $html.='<p><a href="'.h($url).'">'.h($link).'</a>（'.h($notice).'）</p>';
    $pdo->prepare('UPDATE agent_topic_ideas SET html=? WHERE id=?')
        ->execute([sanitize_content($html),$id]);
}
