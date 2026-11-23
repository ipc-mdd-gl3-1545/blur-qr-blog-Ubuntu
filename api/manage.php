<?php
/**
 * 后台管理页面入口。仅通过 ADMIN_PATH（如 /manage）访问。
 * admin.html 不在网站根目录直接暴露；由本文件读盘返回。
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($method, ['GET', 'HEAD'], true)) {
    http_response_code(405);
    exit('405 Method Not Allowed');
}

$htmlFile = ROOT_DIR . '/admin.html';
if (!is_file($htmlFile)) {
    http_response_code(404);
    exit('后台页面文件不存在（admin.html）');
}

securityHeaders(true);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
readfile($htmlFile);
