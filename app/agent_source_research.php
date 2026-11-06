<?php
declare(strict_types=1);

const AGENT_BRAVE_WEB_SEARCH_URL = 'https://api.search.brave.com/res/v1/web/search';

function agent_research_api_configured(): bool
{
    return setting('agent_brave_key_cipher','')!=='';
}

function agent_research_save_api_key(string $plain): void
{
    $plain=trim($plain);
    if (strlen($plain)>512 || preg_match('/[\x00-\x20\x7f]/',$plain)) {
        throw new InvalidArgumentException('搜索 API 密钥格式无效');
    }
    if ($plain!=='') set_setting('agent_brave_key_cipher',ai_encrypt($plain));
}

function agent_research_search_query(array $topic, ?callable $ai = null): string
{
    $name=mb_substr((string)$topic['topic'],0,120);
    $language=(string)$topic['language'];
    $fallback=$name.' step by step guide tutorial how to documentation FAQ open education';
    if ($language==='zh') $fallback=$name.' 官方 教程 操作步骤 使用指南 知识库 常见问题';
    if ($ai===null && !ai_config()['configured']) return mb_substr($fallback,0,220);
    try {
        $prompt="只输出一行 5-16 个搜索关键词，用于寻找可靠的操作教程、知识库、问答类公开网站；不要网址或域名。\n行业：".
            $name."\n语言：".$language."\n地区：".(string)$topic['region'];
        $raw=$ai ? $ai($prompt) : ai_chat_content(['model'=>ai_config()['model'],'messages'=>[
            ['role'=>'system','content'=>'只生成查询关键词，绝不推断抓取与转载许可。'],
            ['role'=>'user','content'=>$prompt],
        ]]);
        if (!is_string($raw)) throw new RuntimeException('搜索词不是文本');
        $raw=trim(preg_replace('/[\r\n"<>{}\x00-\x1f]/u',' ',$raw) ?? '');
        $raw=str_replace(chr(96),' ',$raw);
        if (mb_strlen($raw)<7 || mb_strlen($raw)>220 || preg_match('~https?://|www\.~i',$raw)) return mb_substr($fallback,0,220);
        return $raw;
    } catch (Throwable $e) { return mb_substr($fallback,0,220); }
}

function agent_research_brave_search(string $query, ?callable $http = null): array
{
    $query=trim($query);
    if (mb_strlen($query)<3 || mb_strlen($query)>230) throw new InvalidArgumentException('搜索词长度错误');
    if ($http!==null) {
        $result=$http($query);
        if (!is_array($result)) throw new RuntimeException('搜索接口返回格式错误');
        return $result;
    }
    $encrypted=setting('agent_brave_key_cipher','');
    if ($encrypted==='') throw new RuntimeException('未配置 Brave Search API 密钥');
    $key=ai_decrypt($encrypted);
    if (!extension_loaded('curl')) throw new RuntimeException('需要 PHP curl 扩展');
    $url=AGENT_BRAVE_WEB_SEARCH_URL.'?'.http_build_query(
        ['q'=>$query,'count'=>10,'safesearch'=>'strict'],'','&',PHP_QUERY_RFC3986);
    $ch=curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT=>7,
        CURLOPT_TIMEOUT=>18,
        CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4,
        CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_PROXY=>'',
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_HTTPHEADER=>['Accept: application/json','X-Subscription-Token: '.$key],
        CURLOPT_USERAGENT=>'HowHubSourceResearch/1.0',
    ]);
    $raw=curl_exec($ch);
    $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if (!is_string($raw) || $raw==='' || strlen($raw)>500000) throw new RuntimeException('搜索 API 响应无效或过大');
    if ($status!==200) throw new RuntimeException('搜索 API 返回 HTTP '.$status.'，稍后再尝试');
    $json=json_decode($raw,true);
    if (!is_array($json)) throw new RuntimeException('搜索 API 返回非 JSON 内容');
    return $json;
}

function agent_research_ai_suggestions(array $topic, ?callable $ai = null): array
{
    $prompt="请列出 3-6 个可能提供教程、实用方法、知识库或问答的网站。只返回 JSON 对象，必须含 sites 数组，每项 url、name 和 reason 字段。".
        "\n主题：".(string)$topic['topic']."；语言：".(string)$topic['language'].
        "；地区：".(string)$topic['region']."。不要声称网站存在或允许采集，结果仅供人工核实。";
    $raw=$ai ? $ai($prompt) : ai_chat_content([
        'model'=>ai_config()['model'],'messages'=>[
            ['role'=>'system','content'=>'只输出含 sites 数组的 JSON，绝不自行联网或者声称拥有授权。'],
            ['role'=>'user','content'=>$prompt],
        ],
    ]);
    if (!is_string($raw) || strlen($raw)>16000) throw new RuntimeException('AI 来源建议错误');
    $raw=trim($raw);
    $fence=str_repeat(chr(96),3);
    if (str_starts_with($raw,$fence)) $raw=preg_replace('/^.{3}(?:json)?\s*(.*?)\s*.{3}$/su','$1',$raw) ?? $raw;
    $obj=json_decode($raw,true);
    if (!is_array($obj) || !is_array($obj['sites']??null)) throw new RuntimeException('AI 未提供合法的站点 JSON');
    return array_slice($obj['sites'],0,8);
}
