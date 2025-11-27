<?php
/**
 * M3U 订阅源生成器
 * 输出格式符合 standard M3U / EXTINF 规范
 * 适配 Zeabur/Render HTTPS 环境
 */

require_once 'JrsScraper.php';

// 1. 设置响应头：告诉浏览器这是一个播放列表文件
header('Content-Type: audio/x-mpegurl; charset=utf-8');
header('Content-Disposition: inline; filename="jrs_playlist.m3u"');

// 2. 获取数据
$scraper = new JrsScraper();
$list = $scraper->getLiveList();

// 3. 构建当前服务器的基础 URL (用于拼接 play.php)
// 适配 Render/Zeabur/Cloudflare 等反向代理环境
$protocol = (
    (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
    $_SERVER['SERVER_PORT'] == 443 || 
    (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
) ? "https://" : "http://";

$host = $_SERVER['HTTP_HOST'];
$path = rtrim(dirname($_SERVER['PHP_SELF']), '/');

// 播放代理脚本的完整地址
// 形式如: https://jrs-live.zeabur.app/play.php
$playScriptUrl = $protocol . $host . $path . '/play.php';

// 4. 输出 M3U 头部
echo "#EXTM3U x-tvg-url=\"\" x-tvg-url=\"$protocol$host$path/epg.xml\"\n";

if ($list['status'] === 'success' && !empty($list['data'])) {
    foreach ($list['data'] as $index => $match) {
        // --- 数据清洗与格式化 ---

        // 提取标题中的关键信息作为分组 (Group Title)
        // 例如 "NBA 湖人vs勇士" -> Group: NBA
        $titleParts = explode(' ', trim($match['title']));
        $groupTitle = isset($titleParts[0]) ? cleanString($titleParts[0]) : '体育赛事';
        
        // 构建显示名称 (TVG Name)
        // 格式: [19:30] 队伍A vs 队伍B
        $cleanTitle = cleanString($match['title']);
        $displayName = sprintf("[%s] %s", $match['time'], $cleanTitle);

        // 如果正在直播，加个标记方便识别
        if ($match['status'] == 'live') {
            $displayName = "🔴 " . $displayName;
        }

        // 构建 Logo (目前留空，可根据 needs 扩展)
        $logo = "";

        // --- 构建最终播放链接 ---
        // 我们不直接给源站地址，而是给 play.php 的地址，带上源站 URL 参数
        // 这样播放器请求时，play.php 才会实时去解析真实的 m3u8
        $finalPlayUrl = $playScriptUrl . "?url=" . urlencode($match['url']);

        // --- 输出 EXTINF 行 ---
        // 格式: #EXTINF:-1 group-title="分组" tvg-id="id" tvg-name="名称" tvg-logo="图标", 显示名称
        echo "#EXTINF:-1 group-title=\"$groupTitle\" tvg-name=\"$displayName\" tvg-logo=\"$logo\",$displayName\n";
        echo $finalPlayUrl . "\n";
    }
} else {
    // 如果没有比赛或获取失败，输出一个提示频道
    echo "#EXTINF:-1 group-title=\"提示\", 当前无赛事或获取失败\n";
    // 指向一个不存在的地址或你的错误提示视频
    echo "http://127.0.0.1/no_stream.mp4\n";
}

/**
 * 辅助函数：清理字符串中的特殊字符，防止破坏 M3U 格式
 */
function cleanString($str) {
    // 移除换行符、逗号(M3U敏感)、引号
    $str = str_replace(array("\r", "\n", ",", "\""), " ", $str);
    // 移除多余空格
    return preg_replace('/\s+/', ' ', trim($str));
}
?>
