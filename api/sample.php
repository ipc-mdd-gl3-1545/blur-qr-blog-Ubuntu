<?php
/**
 * 监控采样脚本。由 cron 每 10 秒调用一次：
 *   * * * * * for i in 1 2 3 4 5 6; do curl -s http://127.0.0.1/api/sample.php >/dev/null; sleep 10; done
 * 或者：每分钟调用一次，配合后台的按需采样也够用。
 * 本脚本不依赖登录，供本机 cron 使用；如需限制，可检查 REMOTE_ADDR 为 127.0.0.1。
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

if (($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1' && ($_SERVER['REMOTE_ADDR'] ?? '') !== '::1') {
    http_response_code(403);
    exit('only localhost');
}
sampleMetrics();
header('Content-Type: text/plain; charset=utf-8');
echo "ok " . date('c') . "\n";
