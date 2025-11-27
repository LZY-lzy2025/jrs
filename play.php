<?php
require_once 'JrsScraper.php';

// 设置 M3U 头信息，让浏览器或播放器识别
header('Content-Type: audio/x-mpegurl');
header('Content-Disposition: inline; filename="jrs_playlist.m3u"');

$scraper = new JrsScraper();
$list = $scraper->getLiveList();

// 自动探测当前服务器的地址，用于构建 play.php 的完整 URL
// 如果你在 Docker 外部访问，可能需要手动指定 IP，例如 $host = "192.168.1.100:8080";
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'];
$baseUrl = $protocol . $host . dirname($_SERVER['PHP_SELF']);
// 处理路径结尾的斜杠
$baseUrl = rtrim($baseUrl, '/'); 
$playScript = $baseUrl . '/play.php';

echo "#EXTM3U x-tvg-url=\"$baseUrl/epg.xml\"\n";

if ($list['status'] === 'success') {
    foreach ($list['data'] as $match) {
        // 1. 构建 Group Title (分组)
        // 尝试从标题中提取联赛名称 (例如 "NBA 湖人vs勇士" -> Group: NBA)
        $titleParts = explode(' ', trim($match['title']));
        $group = isset($titleParts[0]) ? $titleParts[0] : 'JRS直播';
        
        // 2. 构建显示标题
        // 格式: [时间] 主队 vs 客队 (状态)
        $displayTitle = sprintf("[%s] %s", $match['time'], $match['title']);
        if ($match['status'] == 'live') {
            $displayTitle = "🔴 " . $displayTitle; // 加上红点标记正在直播
        }

        // 3. 构建 Logo (可选，这里暂时留空，如果有队伍Logo库可以映射)
        $logo = "";

        // 4. 构建代理播放链接
        // 我们把详情页的 URL 作为参数传给 play.php
        $playUrl = $playScript . "?url=" . urlencode($match['url']);

        // 输出 M3U 条目
        // #EXTINF:-1 group-title="分组" tvg-name="标题" tvg-logo="Logo", 标题
        echo "#EXTINF:-1 group-title=\"$group\" tvg-name=\"$displayTitle\" tvg-logo=\"$logo\",$displayTitle\n";
        echo $playUrl . "\n";
    }
} else {
    // 错误处理，输出一个假的频道提示错误
    echo "#EXTINF:-1 group-title=\"错误\", 获取列表失败\n";
    echo "http://localhost/error.mp4\n";
}
?>
