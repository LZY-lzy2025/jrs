<?php

class JrsScraper
{
    // 【关键】更新域名池
    // 很多主域名有 Cloudflare，我们尝试一些备用域名或 IP 直连
    private $domains = [
        'http://www.jrskan.com',
        'http://m.jrskan.com',      // 手机版
        'http://www.jrs04.com',
        'http://www.jrszhibo.com',  
        'https://jrs.kanqiu.cc'     // 备用
    ];

    private $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    private $lastError = '';

    /**
     * 对外暴露错误信息
     */
    public function getLastError() {
        return $this->lastError;
    }

    /**
     * 增强版 cURL 请求
     */
    public function fetchUrl($url, $referer = '')
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        
        // 伪装头部，模拟真实浏览器
        $headers = [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
            'Accept-Language: zh-CN,zh;q=0.9',
            'Upgrade-Insecure-Requests: 1',
            'Cache-Control: no-cache',
            'Connection: keep-alive'
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        // 自动跳转
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        
        // 忽略 SSL 证书问题
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        // User Agent
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        
        // 超时设置
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        
        // 关键：处理 GZIP 压缩，防止乱码
        curl_setopt($ch, CURLOPT_ENCODING, ''); 
        
        if ($referer) {
            curl_setopt($ch, CURLOPT_REFERER, $referer);
        } else {
            // 如果没有 referer，默认用当前域名
            $parsed = parse_url($url);
            if (isset($parsed['scheme']) && isset($parsed['host'])) {
                 curl_setopt($ch, CURLOPT_REFERER, $parsed['scheme'] . '://' . $parsed['host']);
            }
        }
        
        $output = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->lastError = "cURL Error: $error";
            return false;
        }
        
        if ($httpCode >= 400) {
            $this->lastError = "HTTP Error: $httpCode | URL: $url";
            // 即使报错也返回内容，有些 403 页面可能包含验证信息
            // return false; 
        }

        return $output;
    }

    /**
     * 获取列表（带详细错误记录）
     */
    public function getLiveList()
    {
        $errors = [];
        foreach ($this->domains as $domain) {
            $data = $this->tryParseList($domain);
            if (!empty($data)) {
                return ['status' => 'success', 'source' => $domain, 'data' => $data];
            } else {
                $errors[] = "$domain: " . ($this->lastError ?: 'No matches found');
            }
        }
        return ['status' => 'error', 'message' => '所有源站失败', 'details' => $errors];
    }

    private function tryParseList($baseUrl)
    {
        $html = $this->fetchUrl($baseUrl);
        if (!$html) {
            return [];
        }

        $matches = [];
        
        // --- 策略 A: 针对 m.jrskan 的正则 ---
        // 寻找包含 "vs" 的 li 或 div
        // 格式通常是: <span>18:00</span> ... <span>队伍A</span> vs <span>队伍B</span>
        
        // 1. 先用非常宽泛的正则把每一行（li 或 div）切出来
        // 匹配 li, div, p 标签，且内部包含 "vs" (忽略大小写)
        preg_match_all('/<(li|div|p)[^>]*>.*?vs.*?<\/\1>/is', $html, $blocks);

        if (empty($blocks[0])) {
             // 策略 B: 如果没有 vs，尝试匹配包含时间的链接
             preg_match_all('/<a[^>]+href=[\'"]([^\'"]+)[\'"][^>]*>(.*?\d{1,2}:\d{2}.*?)<\/a>/is', $html, $linkBlocks);
             
             // 构造 blocks 结构兼容
             if (!empty($linkBlocks[0])) {
                 $blocks = $linkBlocks; // 这里的结构略有不同，下面处理要做适配
                 // 简易处理策略B
                 foreach ($linkBlocks[0] as $i => $fullTag) {
                     $url = $linkBlocks[1][$i];
                     $text = strip_tags($linkBlocks[2][$i]);
                     $this->processItem($baseUrl, $url, $text, $matches);
                 }
                 return $matches;
             }
        }

        // 处理策略 A 的结果
        foreach ($blocks[0] as $block) {
            // 提取链接
            if (!preg_match('/href=["\'](.*?)["\']/i', $block, $urlMatch)) continue;
            $rawUrl = $urlMatch[1];
            
            // 提取纯文本
            $text = strip_tags($block);
            
            $this->processItem($baseUrl, $rawUrl, $text, $matches);
        }

        return $matches;
    }
    
    // 辅助函数：处理单个条目
    private function processItem($baseUrl, $rawUrl, $text, &$matches) {
        // 过滤杂项
        if (strpos($rawUrl, 'javascript') !== false) return;
        
        $text = preg_replace('/\s+/', ' ', trim($text)); // 合并空格
        
        // 必须包含时间
        if (!preg_match('/(\d{1,2}:\d{2})/', $text, $timeMatch)) return;
        $time = $timeMatch[1];
        
        // 补全 URL
        if (strpos($rawUrl, 'http') === false) {
            $url = rtrim($baseUrl, '/') . '/' . ltrim($rawUrl, '/');
        } else {
            $url = $rawUrl;
        }
        
        // 状态
        $status = (stripos($text, 'ing') !== false || stripos($text, '直播中') !== false) ? 'live' : 'upcoming';
        
        $matches[] = [
            'time' => $time,
            'title' => $text,
            'url' => $url,
            'status' => $status
        ];
    }

    /**
     * 获取详情页真实地址
     */
    public function getStreamUrl($detailUrl)
    {
        $html = $this->fetchUrl($detailUrl);
        $res = ['type' => 'webview', 'url' => $detailUrl];

        if (!$html) return $res;

        // 1. 查找 m3u8
        if (preg_match('/["\'](http[^"\']+\.m3u8[^"\']*)["\']/i', $html, $m)) {
             return ['type' => 'video', 'url' => stripslashes($m[1])];
        }

        // 2. 查找 iframe
        if (preg_match_all('/<iframe[^>]+src=["\'](.*?)["\']/i', $html, $frames)) {
            foreach ($frames[1] as $src) {
                // 补全
                $iframeUrl = (strpos($src, 'http') === 0) ? $src : 'http:' . $src;
                // 简单判断 iframe 是否包含播放器关键字
                if (stripos($src, 'm3u8') !== false || stripos($src, 'player') !== false) {
                     // 找到了内嵌播放器，直接返回这个 webview，比外层干净
                     return ['type' => 'webview', 'url' => $iframeUrl];
                }
            }
        }
        
        return $res;
    }
}
