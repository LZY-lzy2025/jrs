<?php
require_once 'JrsScraper.php';

$scraper = new JrsScraper();
$action = $_GET['action'] ?? 'view';

// API 模式：获取具体播放地址 (保留原有功能)
if (isset($_GET['play_url'])) {
    $streamInfo = $scraper->getStreamUrl($_GET['play_url']);
    header('Content-Type: application/json');
    echo json_encode($streamInfo);
    exit;
}

// 获取列表
$list = $scraper->getLiveList();

if ($action === 'api') {
    header('Content-Type: application/json');
    echo json_encode($list);
    exit;
}

// 计算 M3U 订阅地址
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'];
$m3uUrl = $protocol . $host . dirname($_SERVER['PHP_SELF']);
$m3uUrl = rtrim($m3uUrl, '/') . '/m3u.php';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JRS 赛事聚合 & M3U源</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .live-tag { animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: .5; } }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-4xl">
        <header class="mb-8 text-center">
            <h1 class="text-3xl font-bold text-blue-800">🏆 JRS 体育聚合 & 订阅源</h1>
            <p class="text-gray-600 mt-2">支持 Web/API/M3U 多种访问方式</p>
        </header>

        <!-- M3U 订阅卡片 -->
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-6 mb-8 shadow-sm">
            <h3 class="text-lg font-bold text-blue-900 mb-2">📡 M3U 订阅地址</h3>
            <p class="text-sm text-blue-700 mb-4">
                复制下方链接到 PotPlayer, VLC, Tivimate 或 TVBox (直播源) 中即可观看。
                <br>
                <span class="text-xs text-gray-500">* 注意：播放器请求时才会实时解析，切台可能需要 2-3 秒缓冲。</span>
            </p>
            <div class="flex gap-2">
                <input type="text" id="m3uInput" value="<?php echo $m3uUrl; ?>" readonly 
                       class="flex-1 p-2 border border-gray-300 rounded text-gray-600 text-sm bg-white focus:outline-none focus:border-blue-500">
                <button onclick="copyM3u()" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded font-medium transition">
                    复制链接
                </button>
                <a href="m3u.php" target="_blank" class="bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 px-4 py-2 rounded font-medium transition">
                    下载文件
                </a>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-md overflow-hidden">
            <div class="p-4 border-b bg-gray-50 flex justify-between items-center">
                <h2 class="font-semibold text-gray-700">今日赛事预览</h2>
                <span class="text-xs px-2 py-1 bg-green-100 text-green-800 rounded">状态: 在线</span>
            </div>

            <?php if ($list['status'] === 'error'): ?>
                <div class="p-6 text-center text-red-500">
                    ERROR: <?php echo htmlspecialchars($list['message']); ?>
                </div>
            <?php else: ?>
                <ul class="divide-y divide-gray-200">
                    <?php foreach ($list['data'] as $match): ?>
                        <li class="hover:bg-gray-50 transition duration-150">
                            <div class="p-4 flex items-center justify-between flex-wrap gap-4">
                                <div class="flex-1">
                                    <div class="flex items-center space-x-2 mb-1">
                                        <span class="text-sm font-bold text-gray-900"><?php echo htmlspecialchars($match['time']); ?></span>
                                        <?php if ($match['status'] === 'live'): ?>
                                            <span class="px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800 live-tag">● 直播中</span>
                                        <?php else: ?>
                                            <span class="px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800">未开始</span>
                                        <?php endif; ?>
                                        <span class="text-xs text-gray-400 bg-gray-100 px-1 rounded">
                                            <?php 
                                                $parts = explode(' ', $match['title']);
                                                echo isset($parts[0]) ? htmlspecialchars($parts[0]) : '赛事';
                                            ?>
                                        </span>
                                    </div>
                                    <p class="text-gray-700 text-sm md:text-base">
                                        <?php echo htmlspecialchars($match['title']); ?>
                                    </p>
                                </div>
                                <a href="play.php?url=<?php echo urlencode($match['url']); ?>" target="_blank" class="text-gray-400 hover:text-blue-600">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                </a>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function copyM3u() {
            const copyText = document.getElementById("m3uInput");
            copyText.select();
            copyText.setSelectionRange(0, 99999); 
            document.execCommand("copy"); // 兼容旧版
            
            // 尝试使用新版 API
            if (navigator.clipboard) {
                navigator.clipboard.writeText(copyText.value).then(() => {
                    alert("✅ 订阅地址已复制！\n请在播放器中添加网络流/订阅源。");
                });
            } else {
                 alert("✅ 订阅地址已复制！");
            }
        }
    </script>
</body>
</html>
