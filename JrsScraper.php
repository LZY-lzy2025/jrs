<?php

class JrsScraper
{
    private $baseUrl = 'http://www.jrs04.com'; // 目标地址，如有变动可修改
    private $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36';

    /**
     * 获取 HTTP 请求内容
     */
    private function fetchUrl($url)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $output = curl_exec($ch);
        curl_close($ch);
        return $output;
    }

    /**
     * 解析首页赛事列表
     */
    public function getLiveList()
    {
        $html = $this->fetchUrl($this->baseUrl);
        if (empty($html)) {
            return ['status' => 'error', 'message' => '无法连接到源站'];
        }

        // 使用 DOMDocument 进行解析 (比正则更稳定)
        $dom = new DOMDocument();
        @$dom->loadHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $html);
        $xpath = new DOMXPath($dom);

        // 注意：这里的 XPath 需要根据实际网站结构调整
        // 假设结构是常见的列表结构
        $matches = [];
        
        // 查找所有赛事条目 (根据 JRS 常见结构假设)
        // 通常是 .match-item 或者 li 标签
        // 这里做一个通用的宽泛匹配演示
        $nodes = $xpath->query("//ul[@id='match-list']/li | //div[contains(@class, 'item')]");

        if ($nodes->length == 0) {
            // 尝试另一种常见的 JRS 结构
            $nodes = $xpath->query("//div[@class='list_content']//a");
        }

        foreach ($nodes as $node) {
            $item = [];
            
            // 提取链接
            $href = $node->getAttribute('href');
            if (!$href) continue;
            
            // 补全 URL
            if (strpos($href, 'http') === false) {
                $item['url'] = rtrim($this->baseUrl, '/') . '/' . ltrim($href, '/');
            } else {
                $item['url'] = $href;
            }

            // 提取文本内容进行分割 (时间、队伍)
            $text = trim($node->textContent);
            // 简单的文本清洗
            $text = preg_replace('/\s+/', ' ', $text);
            
            $item['raw_text'] = $text;
            
            // 尝试解析时间 (格式如 19:30)
            preg_match('/(\d{1,2}:\d{2})/', $text, $timeMatch);
            $item['time'] = $timeMatch[1] ?? 'Live';

            // 简单的队伍解析逻辑 (仅作演示，实际需根据 HTML 结构微调)
            // 假设格式：英超 曼联 vs 切尔西
            $item['title'] = $text;

            // 状态判断
            if (strpos($text, '直播中') !== false || strpos($text, 'ing') !== false) {
                $item['status'] = 'live';
            } else {
                $item['status'] = 'upcoming';
            }

            $matches[] = $item;
        }

        return ['status' => 'success', 'data' => $matches];
    }

    /**
     * 解析详情页获取真实播放地址
     * 实现 Webview/Video 双模式判断逻辑
     */
    public function getStreamUrl($detailUrl)
    {
        $html = $this->fetchUrl($detailUrl);
        $result = [
            'type' => 'webview', // 默认为 webview 模式，最稳妥
            'url' => $detailUrl,
            'headers' => ['User-Agent' => $this->userAgent]
        ];

        // 1. 尝试查找 m3u8 直链 (Video 模式)
        if (preg_match('/["\'](https?:\/\/.*\.m3u8.*?)["\']/', $html, $m3u8Match)) {
            $result['type'] = 'video';
            $result['url'] = $m3u8Match[1];
        } 
        // 2. 尝试查找 iframe 嵌入
        elseif (preg_match('/<iframe.*?src=["\'](.*?)["\']/', $html, $iframeMatch)) {
            $src = $iframeMatch[1];
            // 如果 iframe src 包含 m3u8，直接用
            if (strpos($src, '.m3u8') !== false) {
                $result['type'] = 'video';
                $result['url'] = $src;
            } else {
                // 否则这是一个更深层的页面，使用 webview 加载
                $result['type'] = 'webview';
                $result['url'] = (strpos($src, 'http') === 0) ? $src : $this->baseUrl . $src;
            }
        }

        return $result;
    }
}
