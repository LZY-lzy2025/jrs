<?php

class JrsScraper
{
    // 域名池：自动轮询，防止单域名被封
    private $domains = [
        'http://m.jrskan.com',      // 手机版通常加密较少，优先
        'http://www.jrs04.com',
        'http://www.jrskan.com',
        'http://www.jrs05.com'
    ];

    // 伪装成 iPhone，诱导服务器返回结构简单的手机版页面
    private $userAgent = 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1';

    private $currentDomain = '';

    public function __construct() {
        $this->currentDomain = $this->domains[0];
    }

    /**
     * 核心请求函数 (增加了 GZIP 处理和 SSL 跳过)
     */
    private function fetchUrl($url, $referer = '')
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        // 【关键】告诉服务器我们接受压缩数据，Curl 会自动解压
        // 解决 502 或乱码的核心
        curl_setopt($ch, CURLOPT_ENCODING, ''); 
        
        if ($referer) {
            curl_setopt($ch, CURLOPT_REFERER, $referer);
        }
        
        $output = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log("JRS Curl Error: " . $error);
            return false;
        }
        return $output;
    }

    /**
     * 获取比赛列表 (使用宽容度最高的正则匹配)
     */
    public function getLiveList()
    {
        foreach ($this->domains as $domain) {
            $this->currentDomain = $domain;
            $data = $this->tryParseList($domain);
            if (!empty($data)) {
                return ['status' => 'success', 'source' => $domain, 'data' => $data];
            }
        }
        return ['status' => 'error', 'message' => '所有源站均无法读取，可能IP被封或源站改版'];
    }

    private function tryParseList($baseUrl)
    {
        $html = $this->fetchUrl($baseUrl);
        if (!$html) return [];

        $matches = [];
        
        // 针对 m.jrskan.com 这种手机版结构的通用匹配
        // 寻找 <a> 标签，里面通常包含 href 和 比赛信息
        // 既然 HTML 结构可能变，我们直接用正则切块
        
        // 1. 匹配所有超链接块
        preg_match_all('/<a.*?href=["\'](.*?)["\'].*?>(.*?)<\/a>/is', $html, $blocks);

        if (empty($blocks[0])) return [];

        for ($i = 0; $i < count($blocks[0]); $i++) {
            $rawUrl = $blocks[1][$i];
            $rawText = strip_tags($blocks[2][$i]); // 去除 HTML 标签取纯文本
            
            // 过滤无效链接
            if (strpos($rawUrl, 'javascript') !== false) continue;
            // 必须包含 "vs" 或者 时间格式 才是比赛
            if (strpos(strtolower($rawText), 'vs') === false && !preg_match('/\d{1,2}:\d{2}/', $rawText)) continue;

            $item = [];

            // URL 补全
            if (strpos($rawUrl, 'http') === false) {
                $item['url'] = rtrim($baseUrl, '/') . '/' . ltrim($rawUrl, '/');
            } else {
                $item['url'] = $rawUrl;
            }

            // 数据清洗
            $text = preg_replace('/\s+/', ' ', trim($rawText));
            
            // 提取时间 (例如 08:30)
            if (preg_match('/(\d{1,2}:\d{2})/', $text, $timeMatch)) {
                $item['time'] = $timeMatch[1];
            } else {
                $item['time'] = 'LIVE';
            }

            // 提取标题
            $item['title'] = $text;
            
            // 状态
            $item['status'] = (strpos($text, '直播') !== false) ? 'live' : 'upcoming';

            $matches[] = $item;
        }

        return $matches;
    }

    /**
     * 【核心解密部分】获取真实流地址
     */
    public function getStreamUrl($detailUrl)
    {
        $html = $this->fetchUrl($detailUrl);
        $res = [
            'type' => 'webview', 
            'url' => $detailUrl,
            'headers' => ['User-Agent' => $this->userAgent]
        ];

        if (!$html) return $res;

        // --- 第一层解密：直接寻找页面中的 m3u8 ---
        if ($url = $this->findM3u8InString($html)) {
            return ['type' => 'video', 'url' => $url];
        }

        // --- 第二层解密：提取 JS 变量中的 Base64 或 URL ---
        // 很多网站用 var playUrl = "aHR0cHM6Ly9..."
        if (preg_match_all('/var\s+\w+\s*=\s*["\'](.*?)["\']/i', $html, $jsVars)) {
            foreach ($jsVars[1] as $val) {
                // 尝试 Base64 解码
                $decoded = base64_decode($val, true);
                if ($decoded && $url = $this->findM3u8InString($decoded)) {
                     return ['type' => 'video', 'url' => $url];
                }
                // 尝试 URL 解码
                $decodedUrl = urldecode($val);
                if ($url = $this->findM3u8InString($decodedUrl)) {
                    return ['type' => 'video', 'url' => $url];
                }
            }
        }

        // --- 第三层解密：Iframe 递归嗅探 (关键) ---
        // 寻找 iframe src
        if (preg_match_all('/<iframe.*?src=["\'](.*?)["\']/i', $html, $iframes)) {
            foreach ($iframes[1] as $src) {
                // 补全 iframe 地址
                $iframeUrl = (strpos($src, 'http') === 0) ? $src : $this->currentDomain . $src;
                
                // 3.1 检查 iframe URL 本身是否包含 m3u8 (作为参数)
                // 例如: player.php?url=http://xxx.m3u8
                if ($url = $this->findM3u8InString(urldecode($iframeUrl))) {
                    return ['type' => 'video', 'url' => $url];
                }

                // 3.2 深度访问 iframe 内容 (仅深入一层，防止超时)
                $subHtml = $this->fetchUrl($iframeUrl, $detailUrl); // 关键：带上 Referer
                
                if ($subHtml) {
                    // 在子页面找 m3u8
                    if ($url = $this->findM3u8InString($subHtml)) {
                         return ['type' => 'video', 'url' => $url];
                    }
                    
                    // 在子页面找 JS 变量解密
                    if (preg_match_all('/var\s+\w+\s*=\s*["\'](.*?)["\']/i', $subHtml, $subJsVars)) {
                        foreach ($subJsVars[1] as $val) {
                             $decoded = base64_decode($val, true);
                             if ($decoded && $url = $this->findM3u8InString($decoded)) {
                                  return ['type' => 'video', 'url' => $url];
                             }
                        }
                    }
                    
                    // 如果都没找到，但这是一个播放器页面，就返回这个 iframe 的地址作为 Webview
                    // 这样比返回最外层详情页要好，广告更少
                    if (strpos($iframeUrl, 'player') !== false || strpos($iframeUrl, 'm3u8') !== false) {
                        $res['type'] = 'webview';
                        $res['url'] = $iframeUrl; // 更新 Webview 地址为内层地址
                    }
                }
            }
        }

        return $res;
    }

    /**
     * 辅助工具：在字符串中提取 http...m3u8
     */
    private function findM3u8InString($str)
    {
        // 匹配 http 或 https 开头，.m3u8 结尾，中间没有引号
        if (preg_match('/(https?:\/\/[^\s"\'<>]+?\.m3u8[^\s"\'<>]*)/i', $str, $matches)) {
            // 清理可能存在的转义符 (例如 \/)
            return str_replace('\/', '/', $matches[1]);
        }
        return false;
    }
}
