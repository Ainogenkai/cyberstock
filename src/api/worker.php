<?php
/**
 * 赛博多人股市 - 独立市场后台常驻守护进程 (CLI Daemon)
 * 作用: 无论是否有玩家在线访问网站，市场行情每隔 3 秒自动推进、更新 K 线、巡检做空强平与全服事件
 */

if (php_sapi_name() !== 'cli') {
    die("此脚本仅限 CLI 命令行或守护进程运行\n");
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/market.php';

echo "[" . date('Y-m-d H:i:s') . "] 赛博股市后台常驻引擎已启动...\n";

$db = DB::getConnection();
$interval = 3; // 默认每 3 秒推进一个 Tick

while (true) {
    try {
        DB::beginTransaction();
        $res = advanceMarketTick($db, true);
        DB::commit();

        if (!empty($res['updated'])) {
            echo "[" . date('Y-m-d H:i:s') . "] Tick #" . ($res['tick_count'] ?? 0) . " 成功推进\n";
        }
    } catch (Exception $e) {
        DB::rollBack();
        echo "[" . date('Y-m-d H:i:s') . "] 推进异常: " . $e->getMessage() . "\n";
    }

    sleep($interval);
}
