<?php

class JrsScraper
{
    // 更换为静态 HTML 网站，避开 Vue/React 渲染问题
    private $domains = [
        'http://www.360bo.com',    // 推荐：纯静态 HTML，容易抓取
        'https://www.yqqk.cc',     // 备用：静态表格
    ];

    // 伪装成电脑浏览器，因为这些老站对 PC 支持更好
    private $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    
    public $lastHtml = '';
    public $lastError = '';

    private function fetchUrl($url, $referer = '')
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        
        // 强制 HTTP/1.1，有些老旧服务器不支持 HTTP/2
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        
        $headers = [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language: zh-CN,zh;q=0.9',
            'Connection: keep-alive',
            'Cache-Control: no-cache',
            'Pragma: no-cache'
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_ENCODING, ''); // 解压 Gzip
        
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
            // 记录每个域名的失败原因
            $len = strlen($this->lastHtml);
            $errors[] = "$domain: " . ($this->lastError ?: "HTML fetched ($len bytes) but regex failed");
        }
        return ['status' => 'error', 'message' => '所有源站解析失败', 'details' => $errors, 'debug_html' => substr($this->lastHtml, 0, 1000)];
    }

    private function tryParseList($baseUrl)
    {
        $html = $this->fetchUrl($baseUrl);
        $this->lastHtml = $html; 
        
        if (!$html) return [];

        $matches = [];
        
        // 针对 www.360bo.com 的解析逻辑
        if (strpos($baseUrl, '360bo') !== false) {
            // 360bo 结构通常是：<div class="xingcheng">...<span>03:00</span>...<a href="...">队伍vs队伍</a>
            // 我们用通用逻辑：找时间 + 链接
            preg_match_all('/(\d{1,2}:\d{2}).*?<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $blocks);
            
            if (!empty($blocks[0])) {
                foreach ($blocks[0] as $i => $full) {
                    $time = $blocks[1][$i];
                    $rawUrl = $blocks[2][$i];
                    $title = strip_tags($blocks[3][$i]);
                    
                    // 过滤非比赛链接
                    if (strpos($title, 'vs') === false && strpos($title, 'VS') === false) continue;
                    
                    $matches[] = $this->formatItem($baseUrl, $time, $title, $rawUrl);
                }
            }
        }
        
        // 针对 yqqk.cc 或通用解析逻辑 (如果上面没抓到)
        if (empty($matches)) {
            // 寻找表格行 <tr>...</tr>
            preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $html, $rows);
            foreach ($rows[1] as $row) {
                // 提取时间
                if (!preg_match('/(\d{1,2}:\d{2})/', $row, $tm)) continue;
                $time = $tm[1];
                
                // 提取链接
                if (!preg_match('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $row, $link)) continue;
                $rawUrl = $link[1];
                $title = strip_tags($link[2]);
                
                if (mb_strlen($title) < 5) continue; // 太短的标题通常不是比赛
                
                $matches[] = $this->formatItem($baseUrl, $time, $title, $rawUrl);
            }
        }

        return $matches;
    }
    
    private function formatItem($baseUrl, $time, $title, $rawUrl) {
        // 清理标题
        $title = preg_replace('/\s+/', ' ', trim($title));
        $title = str_replace(['直播', '高清', 'CCTV'], '', $title);
        
        // 补全 URL
        if (strpos($rawUrl, 'http') === false) {
            $url = rtrim($baseUrl, '/') . '/' . ltrim($rawUrl, '/');
        } else {
            $url = $rawUrl;
        }
        
        return [
            'time' => $time,
            'title' => $title,
            'url' => $url,
            'status' => 'live' // 简化状态判断
        ];
    }

    // ... getStreamUrl 保持不变 ...
    public function getStreamUrl($detailUrl)
    {
        $html = $this->fetchUrl($detailUrl);
        $res = ['type' => 'webview', 'url' => $detailUrl];
        if (!$html) return $res;

        if (preg_match('/["\'](http[^"\']+\.m3u8[^"\']*)["\']/i', $html, $m)) {
             return ['type' => 'video', 'url' => stripslashes($m[1])];
        }
        
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
