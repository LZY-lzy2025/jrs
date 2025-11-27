<?php

class JrsScraper
{
    // 精简域名池，专注于响应正常的源
    // jrs04 和 jrszhibo 已经挂了，去掉它们防止浪费时间
    private $domains = [
        'http://m.jrskan.com',      // 主力源
        'http://www.jrskan.com',    // 备用
        'http://www.jrs05.com'      // 备用2
    ];

    // 依然伪装成 iPhone，因为手机版页面干扰最少
    private $userAgent = 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1';
    
    // 用于调试，存储最后一次获取的 HTML原始内容
    public $lastHtml = '';
    public $lastError = '';

    public function fetchUrl($url, $referer = '')
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        
        // 极简 Header，有时候 Header 太多反而被怀疑
        $headers = [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
            'Connection: keep-alive'
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        // 关键：强制接受 gzip，防止乱码
        curl_setopt($ch, CURLOPT_ENCODING, ''); 
        
        if ($referer) curl_setopt($ch, CURLOPT_REFERER, $referer);
        
        $output = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->lastError = $error;
            return false;
        }
        return $output;
    }

    public function getLiveList()
    {
        $errors = [];
        foreach ($this->domains as $domain) {
            $data = $this->tryParseList($domain);
            if (!empty($data)) {
                return ['status' => 'success', 'source' => $domain, 'data' => $data];
            }
            $errors[] = "$domain: " . ($this->lastError ?: 'No matches (HTML fetched but regex failed)');
        }
        return ['status' => 'error', 'message' => '解析失败', 'details' => $errors, 'debug_html' => substr($this->lastHtml, 0, 500)];
    }

    private function tryParseList($baseUrl)
    {
        $html = $this->fetchUrl($baseUrl);
        $this->lastHtml = $html; // 保存 HTML 供调试
        
        if (!$html) return [];

        $matches = [];
        
        // 【核弹级正则】
        // 逻辑：寻找页面上所有的 <a href="...">...</a>
        // 不管它在什么标签里。
        preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $links);
        
        if (empty($links[0])) return [];

        foreach ($links[0] as $i => $fullTag) {
            $rawUrl = $links[1][$i];
            $rawText = strip_tags($links[2][$i]); // 去掉标签，只留文字
            
            // 清理文本
            $text = preg_replace('/\s+/', ' ', trim($rawText));
            
            // 过滤逻辑：
            // 1. 链接不能是 js
            if (stripos($rawUrl, 'javascript') !== false) continue;
            
            // 2. 【关键】必须包含时间格式 (如 08:00, 23:30)
            if (!preg_match('/(\d{1,2}:\d{2})/', $text, $timeMatch)) continue;
            
            // 3. 过滤掉看起来像导航栏的短词 (比如 "直播大厅", "比分直播")
            if (mb_strlen($text) < 4) continue;

            // 如果到了这里，这很大概率是一场比赛
            $time = $timeMatch[1];
            
            // 补全 URL
            if (strpos($rawUrl, 'http') === false) {
                $url = rtrim($baseUrl, '/') . '/' . ltrim($rawUrl, '/');
            } else {
                $url = $rawUrl;
            }
            
            // 简单的去重 (防止同一个比赛有多个链接)
            $matches[] = [
                'time' => $time,
                'title' => $text,
                'url' => $url,
                'status' => (stripos($text, 'ing') !== false || stripos($text, '播') !== false) ? 'live' : 'upcoming'
            ];
        }
        
        // 简单的去重：根据标题和时间
        $uniqueMatches = [];
        $seen = [];
        foreach ($matches as $m) {
            $key = $m['time'] . $m['title'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $uniqueMatches[] = $m;
            }
        }

        return $uniqueMatches;
    }

    // ... getStreamUrl 方法保持不变 ...
    public function getStreamUrl($detailUrl)
    {
        $html = $this->fetchUrl($detailUrl);
        $res = ['type' => 'webview', 'url' => $detailUrl];
        if (!$html) return $res;

        if (preg_match('/["\'](http[^"\']+\.m3u8[^"\']*)["\']/i', $html, $m)) {
             return ['type' => 'video', 'url' => stripslashes($m[1])];
        }
        
        // 增加对 player.php?url=... 的提取
        if (preg_match('/url=(http[^&"]+\.m3u8)/i', $html, $m)) {
            return ['type' => 'video', 'url' => urldecode($m[1])];
        }

        if (preg_match_all('/<iframe[^>]+src=["\'](.*?)["\']/i', $html, $frames)) {
            foreach ($frames[1] as $src) {
                $iframeUrl = (strpos($src, 'http') === 0) ? $src : 'http:' . $src;
                if (stripos($src, 'm3u8') !== false || stripos($src, 'player') !== false) {
                     return ['type' => 'webview', 'url' => $iframeUrl];
                }
            }
        }
        return $res;
    }
}
