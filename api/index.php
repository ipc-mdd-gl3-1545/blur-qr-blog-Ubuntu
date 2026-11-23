<?php
/**
 * 模糊二维码 · 博客后端 API 入口（LAMP 版）
 * 所有 /api/* 请求由 Apache 重写到本文件。接口路径与响应格式与 Node 版保持一致，
 * 前台 index.html / article.html / 后台 admin.html 无需改动即可工作。
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path   = rawurldecode($path);

/* 写操作先做同源校验 */
requireSameOrigin();

/* 请求计时（响应结束时记录统计 + 记访问日志） */
$T0 = microtime(true);
$LOG_SKIP = ['/api/healthz', '/healthz', '/api/track', '/api/admin/metrics', '/api/admin/logs', '/api/admin/danmaku', '/api/admin/danmaku/rows'];
register_shutdown_function(function () use ($T0, $path, $method, $LOG_SKIP) {
    $code = http_response_code();
    recordRequest($code, (microtime(true) - $T0) * 1000);
    if (LOG_REQUESTS && !in_array($path, $LOG_SKIP, true)) {
        $lv = $code >= 500 ? 'error' : ($code >= 400 ? 'warn' : 'info');
        logWrite($method . ' ' . $path . ' ' . $code, $lv, 'request', clientIp());
    }
    logRotate();
});

/* 定期采样（不依赖 cron 也有监控数据） */
sampleIfDue();

/* ================================================================== */
/* 健康检查                                                            */
/* ================================================================== */
if (($path === '/api/healthz' || $path === '/healthz') && in_array($method, ['GET', 'HEAD'], true)) {
    jsonOut(200, ['ok' => true, 'uptime' => 0, 'php' => PHP_VERSION, 'time' => nowMs()]);
}

/* ================================================================== */
/* 前端错误上报（公开，访客页面出错也能收集；带简单频率限制）          */
/* ================================================================== */
if ($path === '/api/client-error' && $method === 'POST') {
    $d = readJsonBody(16384);
    $msg = trim(s($d['message'] ?? '', 1000));
    if ($msg === '') fail(400, '缺少错误信息');
    // 同一 IP 10 秒内最多 20 条，防止恶意刷屏（按 ip_hash 计数）
    $ip = clientIp();
    $now = nowMs();
    $recent = (int)db()->query("SELECT COUNT(*) FROM sys_errors
        WHERE source='client' AND ip_hash=" . db()->quote(ipHash($ip)) . " AND t >= " . ($now - 10000))->fetchColumn();
    if ($recent >= 20) fail(429, '上报过于频繁');

    $level = strtolower((string)($d['level'] ?? 'error'));
    if (!in_array($level, ['error', 'warn', 'info', 'fatal'], true)) $level = 'error';
    $kind = preg_replace('/[^a-zA-Z0-9_.-]/', '', (string)($d['kind'] ?? 'js')) ?: 'js';
    errorRecord('client', $level, substr($kind, 0, 40), $msg,
        (string)($d['stack'] ?? ''), (string)($d['page'] ?? ''));
    jsonOut(200, ['ok' => true]);
}

/* ================================================================== */
/* 前端埋点：Vue 新版 SDK 上报事件                                     */
/* ================================================================== */
if ($path === '/api/track' && $method === 'POST') {
    $d = readJsonBody(8192);
    $event = substr(preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($d['event'] ?? '')) ?: 'event', 0, 64);
    $page  = substr((string)($d['page'] ?? ''), 0, 1000);
    $ref   = substr((string)($d['ref'] ?? ''), 0, 1000);
    $ua    = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    $st = db()->prepare('INSERT INTO track_events (t, event, page, ref, ip_hash, ua) VALUES (?,?,?,?,?,?)');
    $st->execute([nowMs(), $event, $page, $ref, ipHash(clientIp()), $ua]);
    jsonOut(200, ['ok' => true]);
}

/* ================================================================== */
/* 会话                                                                */
/* ================================================================== */
if ($path === '/api/login' && $method === 'POST') {
    $ip = clientIp();
    $remain = loginLockRemain($ip);
    if ($remain > 0) fail(429, '尝试次数过多，请 ' . (int)ceil($remain / 60) . ' 分钟后再试');
    $d = readJsonBody();
    $pw = (string)($d['password'] ?? '');
    if (!checkPassword($pw, adminHash())) {
        loginFail($ip);
        logWrite('登录失败：密码不正确', 'warn', 'auth', $ip);
        fail(401, '密码不正确');
    }
    loginOk($ip);
    logWrite('登录成功', 'info', 'auth', $ip);
    setAuthCookie(signToken(), SESSION_TTL);
    jsonOut(200, ['ok' => true]);
}

if ($path === '/api/logout' && $method === 'POST') {
    setAuthCookie('', 1);
    jsonOut(200, ['ok' => true]);
}

if ($path === '/api/session' && $method === 'GET') {
    jsonOut(200, ['authed' => isAuthed()]);
}

/* ================================================================== */
/* 公开读取：文章                                                      */
/* ================================================================== */
if ($path === '/api/articles' && $method === 'GET') {
    $rows = db()->query('SELECT id,title,tag,body,cover,published,views,created_at,updated_at
        FROM articles WHERE published=1 ORDER BY created_at DESC')->fetchAll();
    jsonOut(200, ['articles' => array_map('articleRow', $rows)]);
}

if (preg_match('#^/api/articles/(\d+)$#', $path, $m) && $method === 'GET') {
    $id = (int)$m[1];
    $st = db()->prepare('SELECT * FROM articles WHERE id=?');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r || !$r['published']) fail(404, '文章不存在');
    db()->prepare('UPDATE articles SET views=views+1 WHERE id=?')->execute([$id]);
    $r['views'] = (int)$r['views'] + 1;
    jsonOut(200, ['article' => articleRow($r)]);
}

/* ================================================================== */
/* 公开：小影院视频列表 / 播放                                         */
/* ================================================================== */
if ($path === '/api/videos' && $method === 'GET') {
    if (VIDEO_DIR === '') jsonOut(200, ['source' => 'none', 'videos' => [], 'error' => '还没有配置本地视频目录（VIDEO_DIR）']);
    $list = [];
    foreach (videoRows() as $v) {
        $list[] = [
            'name' => $v['name'], 'path' => 'local:' . $v['name'], 'size' => $v['size'],
            'thumb' => '', 'src' => 'local',
            'play' => '/api/play?local=' . rawurlencode($v['name']),
        ];
    }
    jsonOut(200, ['source' => 'local', 'dir' => VIDEO_DIR, 'videos' => $list]);
}

if ($path === '/api/play' && in_array($method, ['GET', 'HEAD'], true)) {
    serveVideo();
    exit;
}

/** 视频流式输出，支持 Range（拖动进度条） */
function serveVideo(): void {
    if (VIDEO_DIR === '') fail(404, '没有配置本地视频目录');
    $name = (string)($_GET['local'] ?? '');
    $base = basename($name);
    if ($base !== $name || !preg_match(VIDEO_EXT_RE, $base)) fail(400, '视频名不合法');
    $full = VIDEO_DIR . '/' . $base;
    if (!is_file($full)) fail(404, '视频不存在');
    $total = (int)filesize($full);
    $start = 0; $end = $total - 1; $code = 200;
    $range = $_SERVER['HTTP_RANGE'] ?? '';
    if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m)) {
        if ($m[1] !== '') {
            $start = (int)$m[1];
            if ($m[2] !== '') $end = min((int)$m[2], $total - 1);
        } elseif ($m[2] !== '') {
            $start = max(0, $total - (int)$m[2]);
        }
        if ($start < 0 || $start >= $total || $start > $end) {
            http_response_code(416);
            header('Content-Range: bytes */' . $total);
            exit;
        }
        $code = 206;
    }
    $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
    $mime = ['mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'webm' => 'video/webm',
             'mov' => 'video/quicktime', 'mkv' => 'video/x-matroska', 'ts' => 'video/mp2t'][$ext] ?? 'video/mp4';
    http_response_code($code);
    header('Content-Type: ' . $mime);
    header('Accept-Ranges: bytes');
    header('Cache-Control: public, max-age=3600');
    header('Content-Length: ' . ($end - $start + 1));
    if ($code === 206) header('Content-Range: bytes ' . $start . '-' . $end . '/' . $total);
    if (($method = strtoupper($_SERVER['REQUEST_METHOD'])) === 'HEAD') return;
    $fp = fopen($full, 'rb');
    if (!$fp) return;
    fseek($fp, $start);
    $left = $end - $start + 1;
    while ($left > 0 && !feof($fp)) {
        $chunk = fread($fp, min(262144, $left));   // 256KB 分块，避免撑爆内存
        if ($chunk === false || $chunk === '') break;
        echo $chunk;
        $left -= strlen($chunk);
        flush();
    }
    fclose($fp);
}

/* ================================================================== */
/* 公开：弹幕（任何人可看可发）                                        */
/* ================================================================== */
if ($path === '/api/danmaku' && $method === 'GET') {
    $video = s($_GET['video'] ?? '', 400);
    if ($video === '') fail(400, '缺少 video 参数');
    $st = db()->prepare('SELECT id,t,text,color FROM danmaku WHERE video=? ORDER BY t ASC,id ASC LIMIT ' . DM_FETCH_MAX);
    $st->execute([$video]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[] = ['id' => (int)$r['id'], 't' => (float)$r['t'], 'x' => $r['text'], 'c' => $r['color']];
    }
    jsonOut(200, ['danmaku' => $out]);
}

if ($path === '/api/danmaku' && $method === 'POST') {
    $d = readJsonBody(16384);
    $video = trim(s($d['video'] ?? '', 400));
    if ($video === '') fail(400, '缺少 video 参数');
    $text = trim(preg_replace('/\s{2,}/', ' ', preg_replace('/[\r\n\t]+/', ' ', s($d['text'] ?? '', 400))) ?? '');
    if ($text === '') fail(400, '弹幕不能为空哦');
    if (mb_strlen($text) > DM_MAX_LEN) fail(400, '弹幕最多 ' . DM_MAX_LEN . ' 个字');
    $t = max(0, min((float)($d['t'] ?? 0), 24 * 3600));
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($d['color'] ?? ''))
        ? strtolower((string)$d['color']) : '#ffffff';

    $ip = clientIp();
    $now = nowMs();
    // 冷却（用表存，多进程安全）
    $st = db()->prepare('SELECT last_ts FROM sys_dm_cooldown WHERE ip=?');
    $st->execute([$ip]);
    $last = (int)$st->fetchColumn();
    if ($now - $last < DM_COOLDOWN * 1000) {
        $wait = (int)ceil((DM_COOLDOWN * 1000 - ($now - $last)) / 1000);
        fail(429, '发得有点快呀，' . $wait . ' 秒后再来～');
    }
    db()->prepare('INSERT INTO sys_dm_cooldown (ip,last_ts) VALUES (?,?)
        ON DUPLICATE KEY UPDATE last_ts=VALUES(last_ts)')->execute([$ip, $now]);

    db()->prepare('INSERT INTO danmaku (video,t,text,color,ip_hash,created_at) VALUES (?,?,?,?,?,?)')
        ->execute([$video, $t, $text, $color, ipHash($ip), $now]);
    $id = (int)db()->lastInsertId();
    // 每个视频最多留 5000 条
    db()->prepare('DELETE FROM danmaku WHERE video=? AND id NOT IN
        (SELECT id FROM (SELECT id FROM danmaku WHERE video=? ORDER BY id DESC LIMIT 5000) x)')->execute([$video, $video]);
    jsonOut(201, ['id' => $id, 't' => $t, 'x' => $text, 'c' => $color]);
}

/* ================================================================== */
/* 以下接口均需登录                                                    */
/* ================================================================== */
if (!isAuthed()) fail(401, '请先登录');

require __DIR__ . '/admin.php';
