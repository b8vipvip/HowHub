<?php
declare(strict_types=1);

/**
 * An original short slide explainer assembled entirely from this guide's new
 * text. Never downloads or imitates third-party video. This is NOT a real
 * device recording. FFmpeg draws the captions on generated color backgrounds.
 */
function agent_topic_video_storyboard(array $idea): array
{
    $title=mb_substr(trim((string)$idea['title']),0,140);
    if (mb_strlen($title)<6) throw new RuntimeException('原创讲解视频缺少标题');
    $html=sanitize_content((string)$idea['html']);
    $doc=new DOMDocument('1.0','UTF-8');
    $old=libxml_use_internal_errors(true);
    try {
        $doc->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>',
            LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);
    } finally {libxml_clear_errors();libxml_use_internal_errors($old);}
    $xp=new DOMXPath($doc);
    $headings=[];
    $steps=[];
    foreach ($xp->query('//h3') ?: [] as $node) {
        $text=trim($node->textContent);
        if ($text!=='') $headings[]=mb_substr($text,0,90);
        if (count($headings)>=5) break;
    }
    foreach ($xp->query('//li') ?: [] as $node) {
        $text=trim($node->textContent);
        if ($text!=='') $steps[]=mb_substr($text,0,100);
        if (count($steps)>=5) break;
    }
    if (!$headings && !$steps) throw new RuntimeException('原创文章没有可用的讲解步骤');
    $slides=[['heading'=>$title,'body'=>'原创教程概览 / 内容为概念演示']];
    $max=min(4,max(count($headings),count($steps)));
    for ($i=0;$i<$max;$i++) {
        $slides[]=['heading'=>$headings[$i]??('操作要点 '.($i+1)),
            'body'=>$steps[$i]??'请结合教程正文检查相关操作步骤'];
    }
    return $slides;
}

function agent_topic_video_font(string $text): string
{
    $cjkFonts=[
        '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc',
        '/usr/share/fonts/truetype/noto/NotoSansCJK-Regular.ttc',
        '/usr/share/fonts/truetype/wqy/wqy-microhei.ttc',
        '/usr/share/fonts/truetype/arphic/ukai.ttc',
    ];
    foreach($cjkFonts as $path) if (is_file($path) && is_readable($path)) return $path;
    if (preg_match('/[\x{3000}-\x{9fff}\x{ff00}-\x{ffef}]/u',$text)) {
        throw new RuntimeException('视频含中文，请在服务器安装 fonts-noto-cjk 后再生成');
    }
    foreach([
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/truetype/liberation2/LiberationSans-Regular.ttf',
    ] as $path) if (is_file($path)&&is_readable($path)) return $path;
    throw new RuntimeException('未找到视频字幕字体，请安装 Noto CJK 或 DejaVu 字体');
}

function agent_topic_video_lines(string $heading,string $body): string
{
    $lines=[];
    foreach([$heading,$body] as $part) {
        $part=trim(preg_replace('/[\r\n\t\x00-\x1f]+/u',' ',$part)??'');
        // 17 CJK glyphs per row; short English lines remain readable.
        $part=mb_substr($part,0,120);
        for ($i=0;$i<mb_strlen($part);$i+=22) {
            $lines[]=mb_substr($part,$i,22);
            if (count($lines)>=6) break;
        }
    }
    return implode("\n",$lines);
}

function agent_topic_video_render(array $slides,string $audioFile = ''): string
{
    $ffmpeg=url_import_bin('ffmpeg');
    if ($ffmpeg==='') throw new RuntimeException('原创短视频需要 FFmpeg；服务器尚未安装');
    if (count($slides)<2 || count($slides)>5) throw new RuntimeException('视频分镜数量必须在 2 至 5 张之间');
    $allText='';
    foreach ($slides as $slide) $allText.=(string)($slide['heading']??'').(string)($slide['body']??'');
    $font=agent_topic_video_font($allText);
    $dir=sys_get_temp_dir().'/hh-original-slide-'.bin2hex(random_bytes(12));
    if (!mkdir($dir,0700)) throw new RuntimeException('无法创建原创视频临时目录');
    $files=[];
    try {
        $filters=[];
        foreach ($slides as $i=>$slide) {
            $text=agent_topic_video_lines((string)$slide['heading'],(string)$slide['body']);
            $path=$dir.'/caption-'.$i.'.txt';
            if (file_put_contents($path,$text)!==strlen($text)) throw new RuntimeException('字幕文件写入失败');
            $files[]=$path;
            $start=$i*5;
            $end=$start+5;
            $filters[]='drawtext=fontfile='.$font.':textfile='.$path.
                ':fontcolor=white:fontsize=27:line_spacing=10:'.
                'x=(w-text_w)/2:y=(h-text_h)/2:'.
                'enable=between(t\\,'.$start.'\\,'.$end.')';
        }
        $duration=count($slides)*5;
        $output=$dir.'/original-lesson.mp4';
        $args=[$ffmpeg,'-nostdin','-hide_banner','-loglevel','error',
            '-f','lavfi','-i','color=c=0x142744:s=854x480:r=12:d='.$duration];
        if ($audioFile!=='') {
            if (!is_file($audioFile) || !str_starts_with(realpath($audioFile)?:'',realpath(media_directory('file')).DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('配音文件不是本站已归档素材');
            }
            array_push($args,'-i',$audioFile);
        }
        array_push($args,'-vf',implode(',',$filters),'-map','0:v:0');
        if ($audioFile!=='') {
            array_push($args,'-map','1:a:0','-af','apad','-c:a','aac','-b:a','96k');
        } else $args[]='-an';
        array_push($args,'-c:v','libx264','-preset','ultrafast','-crf','29',
            '-pix_fmt','yuv420p','-threads','1','-movflags','+faststart',
            '-t',(string)$duration,'-y',$output);
        url_import_process($args,100,16384);
        $size=@filesize($output);
        if (!$size || $size>18*1024*1024) throw new RuntimeException('合成视频为空或超出 18MB 限制');
        $bytes=file_get_contents($output,false,null,0,18*1024*1024+1);
        if (!is_string($bytes)) throw new RuntimeException('无法读取完成的视频文件');
        return $bytes;
    } finally {
        foreach($files as $path) @unlink($path);
        foreach(glob($dir.'/*')?:[] as $path) @unlink($path);
        @rmdir($dir);
    }
}

function agent_topic_video_process(PDO $pdo,array $idea,?callable $renderer=null): array
{
    $id=(int)$idea['id'];
    $q=$pdo->prepare('SELECT * FROM agent_topic_av WHERE idea_id=?');
    $q->execute([$id]);$av=$q->fetch();
    if (!$av || $av['video_status']!=='pending' || $idea['status']!=='review') return ['status'=>'idle'];
    if ($av['audio_status']==='pending') return ['status'=>'waiting_audio'];
    if (setting('agent_original_video_enabled','0')!=='1') {
        $pdo->prepare("UPDATE agent_topic_av SET video_status='skipped' WHERE idea_id=?")->execute([$id]);
        return ['status'=>'skipped'];
    }
    try {
        $slides=agent_topic_video_storyboard($idea);
        $audioFile='';
        if ($av['audio_status']==='complete' && $av['audio_url']!=='') {
            $asset=$pdo->prepare("SELECT * FROM media_assets WHERE url=? AND kind='file' AND mime='audio/mpeg'");
            $asset->execute([$av['audio_url']]);
            $found=$asset->fetch();
            if ($found) $audioFile=media_file_path($found)?:'';
        }
        $bytes=$renderer!==null ? $renderer($slides,$audioFile) :
            agent_topic_video_render($slides,$audioFile);
        if (!is_string($bytes)) throw new RuntimeException('合成视频返回数据无效');
        $url=agent_topic_generated_file($pdo,$bytes,'video');
        agent_topic_av_append($pdo,$id,'video',$url);
        $pdo->prepare("UPDATE agent_topic_av SET video_status='complete',video_url=? WHERE idea_id=?")
            ->execute([$url,$id]);
        agent_event($pdo,'topic_video','success','原创步骤合成静态教程视频；非真实设备操作录像');
        return ['status'=>'complete','url'=>$url,'slides'=>count($slides)];
    } catch(Throwable $e) {
        $pdo->prepare("UPDATE agent_topic_av SET video_status='failed',notes=? WHERE idea_id=?")
            ->execute([mb_substr($e->getMessage(),0,240),$id]);
        agent_event($pdo,'topic_video','error',get_class($e).' 原创视频合成失败');
        return ['status'=>'failed','message'=>$e->getMessage()];
    }
}
