<?php
// 强制显示错误
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'JrsScraper.php';

$scraper = new JrsScraper();
$list = $scraper->getLiveList();

echo "<h1>🔍 深度调试报告</h1>";

if ($list['status'] === 'success') {
    echo "<h2 style='color:green'>✅ 抓取成功！</h2>";
    echo "<p>来源: {$list['source']} | 数量: " . count($list['data']) . "</p>";
    echo "<pre>" . print_r($list['data'], true) . "</pre>";
} else {
    echo "<h2 style='color:red'>❌ 抓取失败</h2>";
    echo "<p>错误: " . $list['message'] . "</p>";
    
    echo "<h3>详细日志:</h3><ul>";
    if (isset($list['details'])) {
        foreach ($list['details'] as $err) echo "<li>$err</li>";
    }
    echo "</ul>";

    echo "<h3>🧐 网页返回内容分析:</h3>";
    // 打印 scraper 内部存储的最后一次 HTML
    $html = $scraper->lastHtml;
    
    if (empty($html)) {
        echo "<p style='color:red'>HTML 内容为空！可能是 cURL 请求被拦截且没返回任何数据。</p>";
    } else {
        $len = strlen($html);
        echo "<p>获取到 HTML 长度: <strong>$len 字节</strong></p>";
        
        // 检查是不是 Cloudflare 盾
        if (strpos($html, 'Just a moment') !== false || strpos($html, 'challenge-platform') !== false) {
             echo "<div style='background:#ffebee;padding:10px;border:1px solid red'>⚠️ <strong>检测到 Cloudflare 5秒盾！</strong><br>源站识别出了你是爬虫。Render/Zeabur 的 IP 被标记了。</div>";
        } else {
             echo "<p>网页前 800 个字符预览 (请截图这里):</p>";
             echo "<textarea style='width:100%;height:200px;font-family:monospace'>" . htmlspecialchars(substr($html, 0, 800)) . "</textarea>";
        }
    }
}
?>
