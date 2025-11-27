<?php
// 开启错误显示
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'JrsScraper.php';

$scraper = new JrsScraper();

echo "<h1>JRS 抓取调试器</h1>";
echo "<p>服务器时间: " . date('Y-m-d H:i:s') . "</p>";

// 1. 测试 cURL 环境
echo "<h2>1. 环境检测</h2>";
if (function_exists('curl_init')) {
    echo "<span style='color:green'>cURL 扩展已安装 ✅</span><br>";
} else {
    echo "<span style='color:red'>cURL 扩展未安装 ❌</span><br>";
}

// 2. 尝试获取列表
echo "<h2>2. 尝试抓取数据</h2>";
$list = $scraper->getLiveList();

if ($list['status'] === 'success') {
    echo "<h3 style='color:green'>抓取成功! (源站: " . $list['source'] . ")</h3>";
    echo "<p>获取到 " . count($list['data']) . " 场比赛。</p>";
    echo "<textarea style='width:100%;height:300px'>" . print_r($list['data'], true) . "</textarea>";
} else {
    echo "<h3 style='color:red'>抓取失败</h3>";
    echo "<strong>错误信息:</strong> " . $list['message'] . "<br>";
    echo "<strong>详细日志:</strong><br>";
    echo "<ul>";
    foreach ($list['details'] as $err) {
        echo "<li>" . htmlspecialchars($err) . "</li>";
    }
    echo "</ul>";
}

// 3. 连通性测试 (Test Connectivity)
echo "<h2>3. 连通性测试 (Google & JRS)</h2>";

function testConnect($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_NOBODY, true); // 只取头部
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}

$googleCode = testConnect('https://www.google.com');
echo "连接 Google.com: " . ($googleCode ? $googleCode : '失败 (网络不通)') . "<br>";

$jrsCode = testConnect('http://m.jrskan.com');
echo "连接 m.jrskan.com: " . ($jrsCode ? $jrsCode : '失败') . "<br>";

?>
