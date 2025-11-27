<?php
/**
 * JRS 体育赛事聚合 - 首页
 * 适配 Docker/Zeabur/Render 环境
 */

require_once 'JrsScraper.php';

// 初始化爬虫
$scraper = new JrsScraper();
$action = $_GET['action'] ?? 'view';

// ---------------------------------------------------------
// 1. API 模式：供前端 JS 调用的解析接口
// ---------------------------------------------------------
if (isset($_GET['play_url'])) {
    // 调用核心解析逻辑
    $streamInfo = $scraper->getStreamUrl($_GET['play_url']);
    header('Content-Type: application/json');
    echo json_encode($streamInfo);
    exit;
}

// ---------------------------------------------------------
// 2. 获取赛事列表数据
// ---------------------------------------------------------
$list = $scraper->getLiveList();

// 如果请求参数是 ?action=api，直接返回 JSON 数据
if ($action === 'api') {
    header('Content-Type: application/json');
    echo json_encode($list);
    exit;
}

// ---------------------------------------------------------
// 3. 动态生成 M3U 订阅地址 (核心适配逻辑)
// ---------------------------------------------------------
// 兼容 Render/Zeabur 的负载均衡 SSL
$protocol = (
    (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
    $_SERVER['SERVER_PORT'] == 443 || 
    (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
) ? "https://" : "http://";

$host = $_SERVER['HTTP_HOST'];
// 获取当前脚本所在目录的路径
$path = rtrim(dirname($_SERVER['PHP_SELF']), '/');

// 拼接完整的 m3u.php 地址
$m3uUrl = $protocol . $host . $path . '/m3u.php';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JRS 体育赛事聚合 & M3U源</title>
    <!-- 引入 Tailwind CSS 进行样式美化 -->
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* 直播中的呼吸灯效果 */
        .live-tag { animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: .5; } }
        /* 简单的加载动画 */
        .spinner { border: 3px solid rgba(0, 0, 0, 0.1); border-left-color: #3b82f6; border-radius: 50%; width: 20px; height: 20px; animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body class="bg-gray-100 min-h-screen font-sans">
    <div class="container mx-auto px-4 py-8 max-w-4xl">
        
        <!-- 头部标题 -->
        <header class="mb-8 text-center">
            <h1 class="text-3xl font-bold text-blue-800 tracking-tight">🏆 JRS 体育聚合 & 订阅源</h1>
            <p class="text-gray-600 mt-2 text-sm">支持 Web 直接观看 / API 调用 / TVBox M3U 订阅</p>
        </header>

        <!-- 订阅卡片区域 -->
        <div class="bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-200 rounded-xl p-6 mb-8 shadow-sm">
            <h3 class="text-lg font-bold text-blue-900 mb-2 flex items-center">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                M3U 直播源订阅
            </h3>
            <p class="text-sm text-blue-700 mb-4 leading-relaxed">
                适用于 <strong>PotPlayer, VLC, Tivimate, TVBox</strong> 等播放器。<br>
                <span class="text-xs text-gray-500 bg-white px-1 rounded border border-gray-200">提示</span> 
                <span class="text-xs text-gray-500">播放器请求时服务器会实时解析源站，切台请耐心等待 2-5 秒。</span>
            </p>
            
            <div class="flex flex-col sm:flex-row gap-3">
                <div class="relative flex-1">
                    <input type="text" id="m3uInput" value="<?php echo $m3uUrl; ?>" readonly 
                           class="w-full p-3 pl-4 border border-gray-300 rounded-lg text-gray-600 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500 shadow-sm transition-all"
                           onclick="this.select()">
                </div>
                <button onclick="copyM3u()" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg font-medium transition shadow-md flex justify-center items-center">
                    <span>复制链接</span>
                </button>
                <a href="m3u.php" target="_blank" class="bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 px-6 py-3 rounded-lg font-medium transition shadow-sm text-center flex justify-center items-center">
                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                    下载文件
                </a>
            </div>
        </div>

        <!-- 赛事列表区域 -->
        <div class="bg-white rounded-xl shadow-md overflow-hidden border border-gray-100">
            <div class="p-4 border-b border-gray-100 bg-gray-50 flex justify-between items-center">
                <h2 class="font-semibold text-gray-700 flex items-center">
                    <svg class="w-5 h-5 mr-2 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
                    今日赛事列表
                </h2>
                <div class="flex items-center space-x-2">
                    <span class="relative flex h-3 w-3">
                      <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                      <span class="relative inline-flex rounded-full h-3 w-3 bg-green-500"></span>
                    </span>
                    <span class="text-xs text-green-700 font-medium">服务正常</span>
                </div>
            </div>

            <?php if ($list['status'] === 'error'): ?>
                <div class="p-10 text-center">
                    <div class="text-red-500 font-bold mb-2">获取数据失败</div>
                    <div class="text-gray-500 text-sm"><?php echo htmlspecialchars($list['message']); ?></div>
                    <button onclick="location.reload()" class="mt-4 text-blue-600 underline text-sm">刷新重试</button>
                </div>
            <?php elseif (empty($list['data'])): ?>
                <div class="p-10 text-center text-gray-500">
                    当前暂无赛事数据，请稍后再试。
                </div>
            <?php else: ?>
                <ul class="divide-y divide-gray-100">
                    <?php foreach ($list['data'] as $match): ?>
                        <li class="hover:bg-blue-50 transition duration-200 group">
                            <div class="p-4 flex items-center justify-between flex-wrap gap-4">
                                <div class="flex-1">
                                    <div class="flex items-center space-x-2 mb-2">
                                        <!-- 时间 -->
                                        <span class="text-sm font-bold text-gray-900 font-mono bg-gray-100 px-2 py-1 rounded">
                                            <?php echo htmlspecialchars($match['time']); ?>
                                        </span>
                                        
                                        <!-- 直播状态 -->
                                        <?php if ($match['status'] === 'live'): ?>
                                            <span class="px-2 py-1 rounded text-xs font-bold bg-red-100 text-red-600 border border-red-200 live-tag flex items-center">
                                                <span class="w-2 h-2 bg-red-500 rounded-full mr-1"></span> LIVE
                                            </span>
                                        <?php else: ?>
                                            <span class="px-2 py-1 rounded text-xs font-medium bg-gray-100 text-gray-500 border border-gray-200">
                                                未开始
                                            </span>
                                        <?php endif; ?>

                                        <!-- 联赛标签 (尝试从标题提取) -->
                                        <span class="text-xs text-blue-600 bg-blue-50 px-2 py-1 rounded border border-blue-100 hidden sm:inline-block">
                                            <?php 
                                                $parts = explode(' ', $match['title']);
                                                echo isset($parts[0]) ? htmlspecialchars($parts[0]) : '赛事';
                                            ?>
                                        </span>
                                    </div>
                                    
                                    <!-- 标题 -->
                                    <h3 class="text-gray-800 text-base font-medium group-hover:text-blue-700 transition-colors">
                                        <?php echo htmlspecialchars($match['title']); ?>
                                    </h3>
                                </div>
                                
                                <!-- 播放按钮 -->
                                <a href="play.php?url=<?php echo urlencode($match['url']); ?>" target="_blank" 
                                   class="flex items-center px-4 py-2 bg-white border border-gray-200 text-gray-600 rounded-lg hover:bg-blue-600 hover:text-white hover:border-transparent transition shadow-sm text-sm font-medium">
                                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                    Web播放
                                </a>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        
        <footer class="mt-8 text-center text-gray-400 text-xs">
            <p>本站仅聚合展示公开网络资源，不提供视频流存储服务。</p>
        </footer>
    </div>

    <!-- 交互脚本 -->
    <script>
        function copyM3u() {
            const copyText = document.getElementById("m3uInput");
            copyText.select();
            copyText.setSelectionRange(0, 99999); // 兼容移动端
            
            // 现代剪贴板 API
            if (navigator.clipboard) {
                navigator.clipboard.writeText(copyText.value).then(() => {
                    showToast("✅ 订阅地址已复制！");
                }).catch(err => {
                    // 降级方案
                    document.execCommand("copy");
                    showToast("✅ 订阅地址已复制 (兼容模式)");
                });
            } else {
                 document.execCommand("copy");
                 showToast("✅ 订阅地址已复制");
            }
        }

        // 简单的 Toast 提示
        function showToast(message) {
            const div = document.createElement('div');
            div.className = 'fixed top-4 left-1/2 transform -translate-x-1/2 bg-gray-800 text-white px-6 py-3 rounded-full shadow-lg z-50 text-sm font-medium transition-opacity duration-300';
            div.textContent = message;
            document.body.appendChild(div);
            setTimeout(() => {
                div.style.opacity = '0';
                setTimeout(() => div.remove(), 300);
            }, 2000);
        }
    </script>
</body>
</html>
