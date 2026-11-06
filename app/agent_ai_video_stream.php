<?php
declare(strict_types=1);

/**
 * Download longer original provider MP4 output to a private temporary file.
 * Never build a multi-megabyte PHP string, follow model-returned URLs or
 * expose temporary files through the public document root.
 */
function agent_ai_video_receive_mp4(string $remoteId,int $limitMb=18,?callable $fixture=null): string
{
    if(!preg_match('/^[A-Za-z0-9_-]{3,128}$/D',$remoteId)) {
        throw new RuntimeException('视频远端任务 ID 不安全');
    }
    if($limitMb<1 || $limitMb>128) throw new RuntimeException('视频单文件容量超出 128MB 上限');
    $limit=$limitMb*1048576;
    $path=tempnam(sys_get_temp_dir(),'hh-aiv-');
    if($path===false) throw new RuntimeException('创建视频下载缓冲文件失败');
    @chmod($path,0600);
    $handle=fopen($path,'wb');
    if($handle===false) {@unlink($path);throw new RuntimeException('无法写入视频下载缓冲文件');}
    $size=0;$overflow=false;
    try {
        if($fixture!==null) {
            $bytes=$fixture('download',['id'=>$remoteId]);
            if(!is_string($bytes)) throw new RuntimeException('测试视频响应不是 MP4 字节');
            $size=strlen($bytes);
            if($size>$limit || fwrite($handle,$bytes)!==$size) {
                throw new RuntimeException('视频下载文件超出容量限制或写入失败');
            }
        } else {
            $config=agent_ai_video_config();
            $key=ai_decrypt($config['cipher']);
            $url=$config['base'].'/videos/'.$remoteId.'/content';
            $ch=curl_init($url);
            curl_setopt_array($ch,[
                CURLOPT_RETURNTRANSFER=>false,
                CURLOPT_FOLLOWLOCATION=>false,
                CURLOPT_MAXREDIRS=>0,
                CURLOPT_CONNECTTIMEOUT=>10,
                CURLOPT_TIMEOUT=>240,
                CURLOPT_SSL_VERIFYPEER=>true,
                CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,
                CURLOPT_PROXY=>'',
                CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key,'Accept: video/mp4'],
                CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk)use($handle,$limit,&$size,&$overflow):int{
                    $length=strlen($chunk);
                    if($size+$length>$limit){$overflow=true;return 0;}
                    $written=fwrite($handle,$chunk);
                    if($written!==$length) return 0;
                    $size+=$length;
                    return $length;
                },
            ]);
            $ok=curl_exec($ch);
            $http=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
            $mime=strtolower(trim(explode(';',(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE))[0]));
            curl_close($ch);
            if($overflow || $ok===false || $http<200 || $http>=300) {
                throw new RuntimeException('视频下载失败、超时或超过容量限制（HTTP '.$http.'）');
            }
            if(!in_array($mime,['video/mp4','application/octet-stream',''],true)) {
                throw new RuntimeException('视频提供商响应的内容类型不是 MP4');
            }
        }
        fflush($handle);
        if($size<128 || $size>$limit) throw new RuntimeException('MP4 文件太小或超出大小限制');
        fclose($handle);$handle=null;
        $start=file_get_contents($path,false,null,0,24);
        if(!is_string($start) || !str_contains($start,'ftyp') ||
            strpos($start,'ftyp')!==4) {
            throw new RuntimeException('视频服务返回的文件不是 MP4 容器');
        }
        return $path;
    } catch(Throwable $e) {
        if(is_resource($handle)) fclose($handle);
        @unlink($path);
        throw $e;
    }
}

/** Register an already bounded temp MP4 with no large in-memory read. */
function agent_ai_video_register_mp4(PDO $pdo,string $path,int $limitMb=18): string
{
    if($limitMb<1 || $limitMb>128) throw new RuntimeException('视频存储配额非法');
    $real=realpath($path);
    $root=realpath(sys_get_temp_dir());
    if(!$real || !$root || !str_starts_with($real,$root.DIRECTORY_SEPARATOR) ||
       !is_file($real)) throw new RuntimeException('视频上传暂存路径非法');
    $size=filesize($real);
    if(!$size || $size<128 || $size>$limitMb*1048576) throw new RuntimeException('视频文件大小超出限制');
    $prefix=file_get_contents($real,false,null,0,24);
    if(!is_string($prefix) || strpos($prefix,'ftyp')!==4) throw new RuntimeException('视频文件头无效');
    $folder=date('Y/m');$dir=media_directory('file').'/'.$folder;
    if(!is_dir($dir) && !mkdir($dir,0770,true) && !is_dir($dir)) throw new RuntimeException('私有视频素材目录不可写');
    $relative=$folder.'/'.bin2hex(random_bytes(16)).'.mp4';
    $dest=media_directory('file').'/'.$relative;
    if(!copy($real,$dest)) {@unlink($dest);throw new RuntimeException('流式视频转存失败');}
    @chmod($dest,0600);
    try {
        if(filesize($dest)!==$size) throw new RuntimeException('视频转存大小不一致');
        $asset=media_register($pdo,'file',$relative,'AI原创视频模型演示.mp4','video/mp4',(int)$size);
        return (string)$asset['url'];
    } catch(Throwable $e) {
        @unlink($dest);throw $e;
    }
}
