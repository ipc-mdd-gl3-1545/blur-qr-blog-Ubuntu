<?php
/**
 * 模糊二维码 · 博客后端（LAMP 版）引导文件
 * 职责：配置、MySQL 连接与建表、会话鉴权、日志落盘、监控采样、通用工具。
 * 说明：不要直接访问本文件；由 index.php / admin.php / sample.php 引入。
 */
declare(strict_types=1);

/* ------------------------------------------------------------------ */
/* 0. 基础设置                                                         */
/* ------------------------------------------------------------------ */
error_reporting(E_ALL);
ini_set('display_errors', '0');          // 生产环境不把错误细节暴露给访客
date_default_timezone_set('Asia/Shanghai');
mb_internal_encoding('UTF-8');

/* ------------------------------------------------------------------ */
/* 1. 配置                                                             */
/* ------------------------------------------------------------------ */
$__cfgFile = __DIR__ . '/config.php';
if (!is_file($__cfgFile)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit("后端未配置：请复制 api/config.example.php 为 api/config.php 并填写数据库信息。\n");
}
/** @var array $CFG */
$CFG = require $__cfgFile;
if (!is_array($CFG)) {
    http_response_code(500);
    exit('config.php 必须返回一个数组。');
}

function cfg(string $key, $default = null) {
    global $CFG;
    if (array_key_exists($key, $CFG)) return $CFG[$key];
    return $default;
}
function cfgStr(string $key, string $default = ''): string { return (string)cfg($key, $default); }
function cfgInt(string $key, int $default): int {
    $n = (int)cfg($key, $default);
    return $n > 0 ? $n : $default;
}
function cfgBool(string $key, bool $default): bool {
    $v = cfg($key, null);
    if ($v === null) return $default;
    return filter_var($v, FILTER_VALIDATE_BOOLEAN);
}

/* ---- 派生配置 ---- */
define('ROOT_DIR', rtrim(cfgStr('WEB_ROOT', dirname(__DIR__)), '/\\'));
define('DATA_DIR', rtrim(cfgStr('DATA_DIR', ROOT_DIR . '/data'), '/\\'));
define('UPLOAD_DIR', rtrim(cfgStr('UPLOAD_DIR', ROOT_DIR . '/uploads'), '/\\'));
define('VIDEO_DIR', rtrim(cfgStr('VIDEO_DIR', ''), '/\\'));
define('ADMIN_PATH', '/' . trim(cfgStr('ADMIN_PATH', '/manage'), '/'));
define('SESSION_TTL', cfgInt('SESSION_TTL_HOURS', 12) * 3600);
define('MAX_IMAGE_BYTES', cfgInt('MAX_IMAGE_MB', 5) * 1024 * 1024);
define('MAX_VIDEO_BYTES', cfgInt('MAX_VIDEO_MB', 4096) * 1024 * 1024);
define('MAX_JSON_BYTES', 4 * 1024 * 1024);
define('DM_MAX_LEN', min(cfgInt('DANMAKU_MAX_LEN', 50), 100));
define('DM_COOLDOWN', cfgInt('DANMAKU_COOLDOWN_SEC', 3));
define('DM_FETCH_MAX', cfgInt('DANMAKU_FETCH_MAX', 2000));
define('LOG_REQUESTS', cfgBool('LOG_REQUESTS', true));
define('LOG_BUFFER', cfgInt('LOG_BUFFER', 1200));
define('LOG_FILE_MAX', cfgInt('LOG_FILE_MB', 5) * 1024 * 1024);
define('METRIC_INTERVAL', max(5, min(60, cfgInt('METRIC_INTERVAL_SEC', 10))));
define('METRIC_HISTORY', cfgInt('METRIC_HISTORY', 180));
define('LOGIN_MAX', cfgInt('LOGIN_MAX_TRIES', 8));
define('TRUST_PROXY', cfgBool('TRUST_PROXY', true));
define('LOG_FILE', DATA_DIR . '/blog.log');

foreach ([DATA_DIR, UPLOAD_DIR] as $__d) {
    if (!is_dir($__d)) @mkdir($__d, 0775, true);
}
if (VIDEO_DIR !== '' && !is_dir(VIDEO_DIR)) @mkdir(VIDEO_DIR, 0775, true);

/* ------------------------------------------------------------------ */
/* 2. 数据库（PDO 单例）                                                */
/* ------------------------------------------------------------------ */
function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        cfgStr('DB_HOST', '127.0.0.1'),
        cfgInt('DB_PORT', 3306),
        cfgStr('DB_NAME', 'blog'),
        cfgStr('DB_CHARSET', 'utf8mb4')
    );
    try {
        $pdo = new PDO($dsn, cfgStr('DB_USER', 'root'), cfgStr('DB_PASS', ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET sql_mode=""',
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        exit("数据库连接失败：请检查 api/config.php 的数据库配置，以及 MySQL 是否已启动。\n");
    }
    return $pdo;
}

/** 补建可观测性错误表（独立 marker，保证旧部署升级后也能建出来） */
function ensureErrorTable(PDO $p): void {
    $m = DATA_DIR . '/.dbinit-obs';
    if (is_file($m)) return;
    $p->exec("
      CREATE TABLE IF NOT EXISTS sys_errors (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        t BIGINT NOT NULL,
        source VARCHAR(12) NOT NULL DEFAULT 'client',
        level VARCHAR(12) NOT NULL DEFAULT 'error',
        kind VARCHAR(40) NOT NULL DEFAULT '',
        message VARCHAR(1000) NOT NULL DEFAULT '',
        stack TEXT,
        page VARCHAR(500) NOT NULL DEFAULT '',
        ua VARCHAR(500) NOT NULL DEFAULT '',
        ip_hash VARCHAR(32) NOT NULL DEFAULT '',
        resolved TINYINT NOT NULL DEFAULT 0,
        resolved_at BIGINT NOT NULL DEFAULT 0,
        KEY idx_t (t),
        KEY idx_src (source),
        KEY idx_level (level),
        KEY idx_resolved (resolved)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    @file_put_contents($m, 'ok', LOCK_EX);
}

/** 建表（幂等。PHP 每请求都是新环境，用标记文件避免每次都跑 CREATE TABLE） */
function dbInit(): void {
    $p = db();
    $marker = DATA_DIR . '/.dbinit';
    $marker2 = DATA_DIR . '/.dbinit-web';
    if (is_file($marker)) {
        if (!is_file($marker2)) {
            $p->exec("
              CREATE TABLE IF NOT EXISTS track_events (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                t BIGINT NOT NULL,
                event VARCHAR(64) NOT NULL,
                page VARCHAR(1000) NOT NULL DEFAULT '',
                ref VARCHAR(1000) NOT NULL DEFAULT '',
                ip_hash VARCHAR(32) NOT NULL DEFAULT '',
                ua VARCHAR(500) NOT NULL DEFAULT '',
                KEY idx_t (t),
                KEY idx_event (event)
              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
            @file_put_contents($marker2, 'ok', LOCK_EX);
        }
        ensureErrorTable($p);
        return;
    }
    $p->exec("
      CREATE TABLE IF NOT EXISTS articles (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title       VARCHAR(500) NOT NULL,
        tag         VARCHAR(120) NOT NULL DEFAULT '',
        body        LONGTEXT,
        cover       VARCHAR(1000) NOT NULL DEFAULT '',
        published   TINYINT NOT NULL DEFAULT 1,
        views       INT UNSIGNED NOT NULL DEFAULT 0,
        created_at  BIGINT NOT NULL,
        updated_at  BIGINT NOT NULL,
        KEY idx_created (created_at DESC)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

      CREATE TABLE IF NOT EXISTS danmaku (
        id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        video       VARCHAR(500) NOT NULL,
        t           DOUBLE NOT NULL DEFAULT 0,
        text        VARCHAR(500) NOT NULL,
        color       VARCHAR(16) NOT NULL DEFAULT '#ffffff',
        ip_hash     VARCHAR(32) NOT NULL DEFAULT '',
        created_at  BIGINT NOT NULL,
        KEY idx_video (video, t)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

      CREATE TABLE IF NOT EXISTS sys_stats (
        id TINYINT PRIMARY KEY,
        req_total BIGINT NOT NULL DEFAULT 0,
        ok2xx BIGINT NOT NULL DEFAULT 0,
        r3xx BIGINT NOT NULL DEFAULT 0,
        r4xx BIGINT NOT NULL DEFAULT 0,
        r5xx BIGINT NOT NULL DEFAULT 0,
        dur_sum BIGINT NOT NULL DEFAULT 0,
        dur_max INT NOT NULL DEFAULT 0,
        boot_at BIGINT NOT NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

      CREATE TABLE IF NOT EXISTS sys_metric (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        t BIGINT NOT NULL,
        cpu DECIMAL(5,1) NOT NULL DEFAULT 0,
        mem DECIMAL(5,1) NOT NULL DEFAULT 0,
        rss INT NOT NULL DEFAULT 0,
        qps DECIMAL(8,2) NOT NULL DEFAULT 0,
        req INT NOT NULL DEFAULT 0,
        err INT NOT NULL DEFAULT 0,
        `lag` DECIMAL(6,2) NOT NULL DEFAULT 0,
        KEY idx_t (t)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

      CREATE TABLE IF NOT EXISTS sys_login (
        ip VARCHAR(64) PRIMARY KEY,
        cnt INT NOT NULL DEFAULT 0,
        since BIGINT NOT NULL,
        lock_until BIGINT NOT NULL DEFAULT 0
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

      CREATE TABLE IF NOT EXISTS sys_dm_cooldown (
        ip VARCHAR(64) PRIMARY KEY,
        last_ts BIGINT NOT NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

      CREATE TABLE IF NOT EXISTS sys_logcount (
        id TINYINT PRIMARY KEY,
        info BIGINT NOT NULL DEFAULT 0,
        warn BIGINT NOT NULL DEFAULT 0,
        error BIGINT NOT NULL DEFAULT 0
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

      /* 可观测性：前端 JS 错误 + 后端 PHP 异常，统一管理 */
      CREATE TABLE IF NOT EXISTS sys_errors (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        t BIGINT NOT NULL,
        source VARCHAR(12) NOT NULL DEFAULT 'client',
        level VARCHAR(12) NOT NULL DEFAULT 'error',
        kind VARCHAR(40) NOT NULL DEFAULT '',
        message VARCHAR(1000) NOT NULL DEFAULT '',
        stack TEXT,
        page VARCHAR(500) NOT NULL DEFAULT '',
        ua VARCHAR(500) NOT NULL DEFAULT '',
        ip_hash VARCHAR(32) NOT NULL DEFAULT '',
        resolved TINYINT NOT NULL DEFAULT 0,
        resolved_at BIGINT NOT NULL DEFAULT 0,
        KEY idx_t (t),
        KEY idx_src (source),
        KEY idx_level (level),
        KEY idx_resolved (resolved)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

      CREATE TABLE IF NOT EXISTS track_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        t BIGINT NOT NULL,
        event VARCHAR(64) NOT NULL,
        page VARCHAR(1000) NOT NULL DEFAULT '',
        ref VARCHAR(1000) NOT NULL DEFAULT '',
        ip_hash VARCHAR(32) NOT NULL DEFAULT '',
        ua VARCHAR(500) NOT NULL DEFAULT '',
        KEY idx_t (t),
        KEY idx_event (event)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    $now = nowMs();
    $p->exec("INSERT IGNORE INTO sys_stats (id, boot_at) VALUES (1, $now)");
    $p->exec("INSERT IGNORE INTO sys_logcount (id) VALUES (1)");
    @file_put_contents($marker, 'ok', LOCK_EX);
    @file_put_contents($marker2, 'ok', LOCK_EX);
    @file_put_contents(DATA_DIR . '/.dbinit-obs', 'ok', LOCK_EX);
}

/** 数据库页要展示的用户表（隐藏内部 sys_ 表） */
function userTables(): array {
    $rows = db()->query("SELECT table_name AS name FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name NOT LIKE 'sys\\_%' ORDER BY table_name")->fetchAll();
    return array_column($rows, 'name');
}

/* ------------------------------------------------------------------ */
/* 3. 通用工具                                                         */
/* ------------------------------------------------------------------ */
function nowMs(): int { return (int)(microtime(true) * 1000); }

function jsonOut(int $status, $obj, array $extra = []): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    foreach ($extra as $k => $v) header($k . ': ' . $v);
    echo json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function fail(int $status, string $msg): void { jsonOut($status, ['error' => $msg]); }
function ok($obj = ['ok' => true]): void { jsonOut(200, $obj); }

/** 输出原始内容（文件下载用），不做 JSON 编码 */
function rawOut(int $status, string $body, array $headers = []): void {
    http_response_code($status);
    header('Cache-Control: no-store');
    foreach ($headers as $k => $v) header($k . ': ' . $v);
    echo $body;
    exit;
}

function readJsonBody(int $limit = MAX_JSON_BYTES): array {
    $raw = file_get_contents('php://input', false, null, 0, $limit + 1);
    if ($raw === false || $raw === '') return [];
    if (strlen($raw) > $limit) fail(413, '请求体超过上限');
    $d = json_decode($raw, true);
    if (!is_array($d)) fail(400, 'JSON 格式不正确');
    return $d;
}
function s($v, int $max = 20000): string { return mb_substr((string)($v ?? ''), 0, $max); }

function clientIp(): string {
    if (TRUST_PROXY) {
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($xff !== '') return trim(explode(',', $xff)[0]);
        if (!empty($_SERVER['HTTP_X_REAL_IP'])) return trim($_SERVER['HTTP_X_REAL_IP']);
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}
function isHttps(): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    if (TRUST_PROXY && !empty($_SERVER['HTTP_X_FORWARDED_PROTO']))
        return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]) === 'https';
    return false;
}

/** 写操作同源校验（配合 SameSite=Strict cookie 双防 CSRF） */
function requireSameOrigin(): void {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($method, ['GET', 'HEAD'], true)) return;
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        $oh = parse_url($origin, PHP_URL_HOST);
        $sh = $_SERVER['HTTP_HOST'] ?? '';
        if ($oh === false || strcasecmp((string)$oh, (string)$sh) !== 0) fail(403, '跨站请求被拒绝');
        return;
    }
    $sfs = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
    if ($sfs !== '' && $sfs !== 'same-origin' && $sfs !== 'none') fail(403, '跨站请求被拒绝');
}

function securityHeaders(bool $isHtml = false): void {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    if ($isHtml) {
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob: https:; " .
               "media-src 'self' blob: data:; style-src 'self' 'unsafe-inline'; " .
               "script-src 'self' 'unsafe-inline'; font-src 'self' data:; connect-src 'self'; " .
               "frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    }
    if (isHttps()) header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

/* ------------------------------------------------------------------ */
/* 4. 密码与会话                                                       */
/* ------------------------------------------------------------------ */
function adminHashFile(): string { return DATA_DIR . '/admin.json'; }

function adminHash(): string {
    $env = cfgStr('ADMIN_PASSWORD_HASH', '');
    if ($env !== '') return $env;
    $f = adminHashFile();
    if (is_file($f)) {
        $d = json_decode((string)file_get_contents($f), true);
        if (is_array($d) && !empty($d['hash'])) return (string)$d['hash'];
    }
    $pw = cfgStr('ADMIN_PASSWORD', '');
    if ($pw === '') return '';
    $hash = password_hash($pw, PASSWORD_DEFAULT);
    @file_put_contents($f, json_encode(['hash' => $hash, 'updatedAt' => nowMs()], JSON_UNESCAPED_UNICODE), LOCK_EX);
    @chmod($f, 0600);
    return $hash;
}

function checkPassword(string $pw, string $stored): bool {
    if ($stored === '') return false;
    return password_verify($pw, $stored);
}

function sessionSecret(): string {
    $env = cfgStr('SESSION_SECRET', '');
    if ($env !== '') return $env;
    $f = DATA_DIR . '/session.key';
    if (is_file($f)) return trim((string)file_get_contents($f));
    $k = bin2hex(random_bytes(32));
    @file_put_contents($f, $k, LOCK_EX);
    @chmod($f, 0600);
    return $k;
}

function signToken(): string {
    $payload = 'admin.' . nowMs();
    $sig = hash_hmac('sha256', $payload, sessionSecret());
    return $payload . '.' . $sig;
}
function verifyToken(string $tok): bool {
    if ($tok === '') return false;
    $i = strrpos($tok, '.');
    if ($i === false) return false;
    $payload = substr($tok, 0, $i);
    $sig = substr($tok, $i + 1);
    $expect = hash_hmac('sha256', $payload, sessionSecret());
    if (!hash_equals($expect, $sig)) return false;
    $parts = explode('.', $payload);
    if (count($parts) < 2 || !ctype_digit($parts[1])) return false;
    $ts = (int)$parts[1];
    $now = nowMs();
    return ($now - $ts) < SESSION_TTL * 1000 && $now >= $ts - 60000;
}
function getToken(): string {
    return isset($_COOKIE['wb_admin']) ? (string)$_COOKIE['wb_admin'] : '';
}
function isAuthed(): bool { return verifyToken(getToken()); }
function setAuthCookie(string $token, int $maxAge): void {
    $bits = ['wb_admin=' . rawurlencode($token), 'HttpOnly', 'SameSite=Strict', 'Path=/', 'Max-Age=' . $maxAge];
    if (isHttps()) $bits[] = 'Secure';
    header('Set-Cookie: ' . implode('; ', $bits));
}

/* ---- 登录限速 ---- */
function loginLockRemain(string $ip): int {
    $st = db()->prepare('SELECT lock_until FROM sys_login WHERE ip=?');
    $st->execute([$ip]);
    $lu = (int)$st->fetchColumn();
    if ($lu <= nowMs()) return 0;
    return (int)ceil(($lu - nowMs()) / 1000);
}
function loginFail(string $ip): void {
    dbInit();
    $now = nowMs();
    $st = db()->prepare('SELECT cnt, since FROM sys_login WHERE ip=?');
    $st->execute([$ip]);
    $r = $st->fetch();
    if (!$r || ($now - (int)$r['since']) > 10 * 60 * 1000) {
        $cnt = 1; $since = $now;
        db()->prepare('INSERT INTO sys_login (ip,cnt,since) VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE cnt=VALUES(cnt), since=VALUES(since), lock_until=0')->execute([$ip, $cnt, $since]);
    } else {
        $cnt = (int)$r['cnt'] + 1;
        $lock = $cnt >= LOGIN_MAX ? $now + 15 * 60 * 1000 : 0;
        db()->prepare('UPDATE sys_login SET cnt=?, lock_until=? WHERE ip=?')->execute([$cnt, $lock, $ip]);
    }
}
function loginOk(string $ip): void {
    db()->prepare('DELETE FROM sys_login WHERE ip=?')->execute([$ip]);
}

/* ------------------------------------------------------------------ */
/* 5. 请求统计                                                         */
/* ------------------------------------------------------------------ */
function recordRequest(int $status, float $ms): void {
    try {
        dbInit();
        $col = 'ok2xx';
        if ($status >= 500) $col = 'r5xx';
        elseif ($status >= 400) $col = 'r4xx';
        elseif ($status >= 300) $col = 'r3xx';
        $max = (int)$ms;
        db()->prepare("UPDATE sys_stats SET req_total=req_total+1, $col=$col+1,
            dur_sum=dur_sum+?, dur_max=GREATEST(dur_max,?) WHERE id=1")->execute([$max, $max]);
    } catch (Throwable $e) { /* 统计失败不影响业务 */ }
}

/* ------------------------------------------------------------------ */
/* 6. 日志（结构化落盘 + 轮转）                                         */
/* ------------------------------------------------------------------ */
function logWrite(string $msg, string $level = 'info', string $kind = 'system', string $ip = ''): void {
    $lv = ($level === 'error') ? 'error' : (($level === 'warn') ? 'warn' : 'info');
    $line = date('Y-m-d H:i:s') . ' [' . strtoupper($lv) . '] ' .
            mb_substr($kind, 0, 20) . ($ip !== '' ? ' ' . mb_substr($ip, 0, 60) : '') .
            ' | ' . mb_substr($msg, 0, 2000) . "\n";
    @file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
    try {
        dbInit();
        db()->prepare("UPDATE sys_logcount SET $lv=$lv+1 WHERE id=1")->execute();
    } catch (Throwable $e) { /* ignore */ }
}

/** 从日志文件尾部读最近 N 条，解析成后台需要的结构 */
function logRead(int $limit): array {
    $out = [];
    if (!is_file(LOG_FILE)) return $out;
    $lines = file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) return $out;
    $slice = array_slice($lines, -$limit);
    $id = count($lines) - count($slice);
    foreach ($slice as $ln) {
        $id++;
        $ts = 0; $level = 'info'; $kind = 'system'; $msg = $ln; $ip = '';
        if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) \[(\w+)\] (\S+)(?: (\S+))? \| (.*)$/', $ln, $m)) {
            $ts = strtotime($m[1]) * 1000;
            $level = strtolower($m[2]);
            $kind = $m[3];
            $ip = $m[4] ?? '';
            $msg = $m[5] ?? '';
        }
        $out[] = ['id' => $id, 'ts' => $ts, 'level' => $level, 'kind' => $kind, 'msg' => $msg, 'ip' => $ip];
    }
    return $out;
}
function logCounts(): array {
    try {
        dbInit();
        $r = db()->query('SELECT info,warn,error FROM sys_logcount WHERE id=1')->fetch();
        if ($r) return ['info' => (int)$r['info'], 'warn' => (int)$r['warn'], 'error' => (int)$r['error']];
    } catch (Throwable $e) { /* ignore */ }
    return ['info' => 0, 'warn' => 0, 'error' => 0];
}
function logRotate(): void {
    if (is_file(LOG_FILE) && filesize(LOG_FILE) > LOG_FILE_MAX) {
        @unlink(LOG_FILE . '.1');
        @rename(LOG_FILE, LOG_FILE . '.1');
    }
}

/* ------------------------------------------------------------------ */
/* 7. 监控采样                                                         */
/* ------------------------------------------------------------------ */
function diskSpace(string $dir): ?array {
    try {
        $total = @disk_total_space($dir);
        $free = @disk_free_space($dir);
        if ($total === false || $free === false) return null;
        $total = (int)$total; $free = (int)$free;
        return ['total' => $total, 'free' => $free, 'used' => $total - $free];
    } catch (Throwable $e) { return null; }
}

/** 采一个点写库；由 sample.php(cron) 或请求按需触发 */
function sampleMetrics(): void {
    try {
        dbInit();
        $st = db()->query('SELECT req_total, r5xx FROM sys_stats WHERE id=1')->fetch();
        $total = (int)($st['req_total'] ?? 0);
        $err = (int)($st['r5xx'] ?? 0);
        $last = db()->query('SELECT t, req FROM sys_metric ORDER BY id DESC LIMIT 1')->fetch();
        $now = nowMs();
        $dt = $last ? max(1, $now - (int)$last['t']) : METRIC_INTERVAL * 1000;
        $winReq = $last ? max(0, $total - (int)$last['req']) : $total;
        $qps = round($winReq / ($dt / 1000), 2);

        // CPU 负载：Linux 用 sys_getloadavg，换算成百分比（按核心数归一）
        $pct = 0.0;
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : [0.0, 0.0, 0.0];
        $cores = 1;
        if (is_file('/proc/cpuinfo')) { $cores = max(1, (int)substr_count((string)file_get_contents('/proc/cpuinfo'), 'processor')); }
        $pct = round(min(100, ((float)($load[0] ?? 0)) / $cores * 100), 1);

        // 系统内存占用
        $memPct = 0.0;
        if (is_file('/proc/meminfo')) {
            $mi = (string)file_get_contents('/proc/meminfo');
            if (preg_match('/MemTotal:\s+(\d+)/', $mi, $a) && preg_match('/MemAvailable:\s+(\d+)/', $mi, $b)) {
                $memPct = round((1 - (int)$b[1] / max(1, (int)$a[1])) * 100, 1);
            }
        }
        $rss = (int)(memory_get_usage(true) / 1024 / 1024);

        db()->prepare('INSERT INTO sys_metric (t,cpu,mem,rss,qps,req,err,`lag`) VALUES (?,?,?,?,?,?,?,0)')
            ->execute([$now, $pct, $memPct, $rss, $qps, $total, $err]);

        $cnt = (int)db()->query('SELECT COUNT(*) FROM sys_metric')->fetchColumn();
        if ($cnt > METRIC_HISTORY + 10) {
            $cut = nowMs() - METRIC_HISTORY * METRIC_INTERVAL * 1000;
            db()->prepare('DELETE FROM sys_metric WHERE t < ?')->execute([$cut]);
        }
    } catch (Throwable $e) { /* ignore */ }
}

/** 请求来了顺带采样（间隔到了才采），不依赖 cron 也有数据 */
function sampleIfDue(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $last = db()->query('SELECT t FROM sys_metric ORDER BY id DESC LIMIT 1')->fetch();
        if (!$last || (nowMs() - (int)$last['t']) >= METRIC_INTERVAL * 1000) sampleMetrics();
    } catch (Throwable $e) { /* ignore */ }
}

/** 组装监控接口完整响应，字段与 Node 版保持一致 */
function buildMetrics(): array {
    dbInit();
    $now = nowMs();
    $st = db()->query('SELECT req_total,ok2xx,r3xx,r4xx,r5xx,dur_sum,dur_max,boot_at FROM sys_stats WHERE id=1')->fetch();
    $boot = (int)($st['boot_at'] ?? $now);
    $reqTotal = (int)($st['req_total'] ?? 0);

    $hist = db()->query('SELECT t,cpu,mem,rss,qps,req,err,`lag` FROM sys_metric ORDER BY t ASC')->fetchAll();
    $history = array_map(function ($r) {
        return [
            't' => (int)$r['t'], 'cpu' => (float)$r['cpu'], 'mem' => (float)$r['mem'],
            'rss' => (int)$r['rss'], 'qps' => (float)$r['qps'], 'req' => (int)$r['req'],
            'err' => (int)$r['err'], 'lag' => (float)$r['lag'],
        ];
    }, $hist);
    $last = $history ? $history[count($history) - 1] : ['cpu' => 0, 'mem' => 0, 'qps' => 0];

    $cores = 1;
    if (is_file('/proc/cpuinfo')) { $cores = max(1, (int)substr_count((string)file_get_contents('/proc/cpuinfo'), 'processor')); }
    $load = function_exists('sys_getloadavg') ? sys_getloadavg() : [0.0, 0.0, 0.0];

    $totalMem = 0; $freeMem = 0; $memUsed = 0; $memPct = 0;
    if (is_file('/proc/meminfo')) {
        $mi = (string)file_get_contents('/proc/meminfo');
        if (preg_match('/MemTotal:\s+(\d+)/', $mi, $a)) $totalMem = (int)$a[1] * 1024;
        if (preg_match('/MemAvailable:\s+(\d+)/', $mi, $b)) $freeMem = (int)$b[1] * 1024;
        $memUsed = max(0, $totalMem - $freeMem);
        $memPct = round((1 - $freeMem / max(1, $totalMem)) * 100, 1);
    }
    $rssBytes = memory_get_usage(true);
    $heapUsed = memory_get_usage(false);
    $heapPeak = memory_get_peak_usage(false);

    // 数据库大小（用户表）
    $dbSize = 0; $tableCount = 0; $tableRows = 0;
    foreach (userTables() as $t) {
        $tableCount++;
        $q = db()->query("SELECT data_length+index_length AS sz, table_rows AS rw FROM information_schema.tables
            WHERE table_schema=DATABASE() AND table_name=" . db()->quote($t))->fetch();
        if ($q) { $dbSize += (int)($q['sz'] ?? 0); $tableRows += (int)($q['rw'] ?? 0); }
    }
    $mysqlVer = db()->query('SELECT VERSION()')->fetchColumn();

    $vids = videoRows();
    $logs = logCounts();
    $lastMinReq = 0;
    if ($hist) {
        foreach ($hist as $p) if ($now - (int)$p['t'] <= 60000) $lastMinReq += (int)$p['req'];
    }

    return [
        'now' => $now, 'bootAt' => $boot, 'uptime' => max(0, (int)(($now - $boot) / 1000)),
        'pid' => getmypid(), 'node' => 'PHP ' . PHP_VERSION,
        'platform' => PHP_OS_FAMILY . ' ' . php_uname('r'), 'host' => gethostname() ?: '',
        'cpu' => ['cores' => $cores, 'load' => array_map(function ($n) { return round((float)$n, 2); }, $load),
                  'pct' => (float)$last['cpu'], 'lag' => (float)($last['lag'] ?? 0)],
        'mem' => ['total' => $totalMem, 'free' => $freeMem, 'used' => $memUsed, 'pct' => $memPct,
                  'rss' => $rssBytes, 'heapUsed' => $heapUsed, 'heapTotal' => $heapPeak, 'external' => 0],
        'disk' => ['root' => diskSpace(ROOT_DIR), 'data' => diskSpace(DATA_DIR)],
        'db' => ['file' => cfgStr('DB_NAME', 'blog'), 'size' => $dbSize, 'wal' => 0,
                 'tables' => $tableCount, 'rows' => $tableRows, 'sqlite' => $mysqlVer],
        'video' => ['count' => count($vids), 'bytes' => array_sum(array_column($vids, 'size')), 'dir' => VIDEO_DIR],
        'req' => ['total' => $reqTotal, 'ok' => (int)($st['ok2xx'] ?? 0), 'r3xx' => (int)($st['r3xx'] ?? 0),
                  'r4xx' => (int)($st['r4xx'] ?? 0), 'r5xx' => (int)($st['r5xx'] ?? 0),
                  'avgMs' => $reqTotal ? (int)round((int)($st['dur_sum'] ?? 0) / $reqTotal) : 0,
                  'maxMs' => (int)($st['dur_max'] ?? 0), 'qps' => (float)($last['qps'] ?? 0), 'lastMin' => $lastMinReq],
        'logs' => ['info' => $logs['info'], 'warn' => $logs['warn'], 'error' => $logs['error'],
                   'buffer' => min(LOG_BUFFER, count(logRead(LOG_BUFFER)))],
        'interval' => METRIC_INTERVAL, 'history' => $history,
    ];
}

/* ------------------------------------------------------------------ */
/* 8. 视频目录工具                                                     */
/* ------------------------------------------------------------------ */
define('VIDEO_EXT_RE', '/\.(mp4|m4v|webm|mkv|mov|avi|flv|f4v|ts|m3u8)$/i');

function videoRows(): array {
    if (VIDEO_DIR === '' || !is_dir(VIDEO_DIR)) return [];
    $out = [];
    foreach (scandir(VIDEO_DIR) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        if (!preg_match(VIDEO_EXT_RE, $name)) continue;
        $full = VIDEO_DIR . '/' . $name;
        if (!is_file($full)) continue;
        $out[] = ['name' => $name, 'size' => (int)filesize($full), 'mtime' => (int)(filemtime($full) * 1000)];
    }
    usort($out, function ($a, $b) { return strcmp($a['name'], $b['name']); });
    return $out;
}
function safeVideoName(string $raw): string {
    $n = rawurldecode($raw);
    if (preg_match('#[/\\\\]#', $n)) return '';
    $n = preg_replace('/[\\\\\/:*?"<>|\x00-\x1f\x7f]/', '_', $n) ?? '';
    $n = ltrim($n, '.');
    $n = preg_replace('/\s+/', ' ', $n) ?? '';
    $n = trim($n);
    if ($n === '' || mb_strlen($n) > 120 || !preg_match(VIDEO_EXT_RE, $n) || strpos($n, '..') !== false) return '';
    return $n;
}
function uniqueVideoName(string $base): string {
    $ext = '.' . pathinfo($base, PATHINFO_EXTENSION);
    $stem = substr($base, 0, strlen($base) - strlen($ext));
    $name = $base;
    for ($i = 2; file_exists(VIDEO_DIR . '/' . $name) && $i < 1000; $i++) $name = $stem . '-' . $i . $ext;
    return $name;
}
function videoWritable(): array {
    if (VIDEO_DIR === '') return ['ok' => false, 'code' => 'VIDEO_DIR 未配置'];
    if (!is_writable(VIDEO_DIR)) return ['ok' => false, 'code' => 'Permission denied'];
    return ['ok' => true];
}

/* 数据库行 -> 前端文章结构（字段名保持与 Node 版一致） */
function articleRow(array $r): array {
    return [
        'id' => (int)$r['id'], 'title' => $r['title'], 'tag' => $r['tag'], 'body' => $r['body'],
        'cover' => $r['cover'], 'published' => (bool)$r['published'], 'views' => (int)$r['views'],
        'created' => (int)$r['created_at'], 'updated' => (int)$r['updated_at'],
    ];
}
function ipHash(string $ip): string { return substr(hash('sha256', $ip . '|dm'), 0, 16); }

/* ------------------------------------------------------------------ */
/* 9. 可观测性：错误上报、聚合、密码管理                                */
/* ------------------------------------------------------------------ */

/** 写入一条错误（前端 JS 或后端 PHP）到 sys_errors，并同步到结构化日志 */
function errorRecord(string $source, string $level, string $kind, string $message, string $stack = '', string $page = ''): int {
    $now = nowMs();
    $st = db()->prepare('INSERT INTO sys_errors (t,source,level,kind,message,stack,page,ua,ip_hash)
        VALUES (?,?,?,?,?,?,?,?,?)');
    $st->execute([
        $now,
        in_array($source, ['client', 'server'], true) ? $source : 'client',
        in_array($level, ['error', 'warn', 'info', 'fatal'], true) ? $level : 'error',
        s($kind, 40), s($message, 1000), s($stack, 8000), s($page, 500),
        s($_SERVER['HTTP_USER_AGENT'] ?? '', 500), ipHash(clientIp()),
    ]);
    // 同步一条到日志，便于 grep；避免 sys_errors 写入失败时完全丢失
    logWrite('[' . strtoupper($source) . '] ' . $kind . ': ' . mb_substr($message, 0, 300),
        $level === 'fatal' ? 'error' : ($level === 'error' ? 'error' : 'warn'), 'bug', clientIp());
    return (int)db()->lastInsertId();
}

/** 安装 PHP 全局错误/致命异常钩子：把后端的 bug 也收进 sys_errors */
function installErrorHooks(): void {
    set_error_handler(function (int $severity, string $msg, string $file, int $line): bool {
        if (!(error_reporting() & $severity)) return false;
        $fatal = in_array($severity, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true);
        $level = $fatal ? 'fatal' : (in_array($severity, [E_WARNING, E_USER_WARNING, E_RECOVERABLE_ERROR], true) ? 'warn' : 'info');
        try {
            errorRecord('server', $level, 'php', $msg,
                basename($file) . ':' . $line . "\n" . $msg, ($_SERVER['REQUEST_URI'] ?? '') ?: '');
        } catch (Throwable $e) { /* 钩子内绝不能再抛 */ }
        return false; // 交还给 PHP 默认处理，保持原有行为
    });
    register_shutdown_function(function (): void {
        $e = error_get_last();
        if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
        try {
            errorRecord('server', 'fatal', 'php', $e['message'],
                basename($e['file']) . ':' . $e['line'] . "\n" . $e['message'], ($_SERVER['REQUEST_URI'] ?? '') ?: '');
        } catch (Throwable $ignored) { /* ignore */ }
    });
}

/** 可观测性聚合：服务健康 + 错误统计 + 资源，一屏看完 */
function buildObservability(): array {
    dbInit();
    $now = nowMs();

    // 错误统计（近 24h / 全部）
    $since = $now - 24 * 3600 * 1000;
    $row = db()->query("SELECT
        COUNT(*) AS total,
        SUM(t >= $since) AS h24,
        SUM(source='client') AS client,
        SUM(source='server') AS server,
        SUM(level IN ('error','fatal')) AS errors,
        SUM(resolved=0) AS open
        FROM sys_errors")->fetch() ?: [];
    $stats = [
        'total' => (int)($row['total'] ?? 0), 'h24' => (int)($row['h24'] ?? 0),
        'client' => (int)($row['client'] ?? 0), 'server' => (int)($row['server'] ?? 0),
        'errors' => (int)($row['errors'] ?? 0), 'open' => (int)($row['open'] ?? 0),
    ];

    // 错误按 message 聚合（去重指纹），用于「哪些 bug 最常出现」
    $groups = db()->query("SELECT source, level, kind, message, COUNT(*) AS n, MAX(t) AS last,
        MIN(resolved) AS hasOpen
        FROM sys_errors GROUP BY source, level, kind, message
        ORDER BY n DESC, last DESC LIMIT 30")->fetchAll();
    $grouped = array_map(function ($g) {
        return [
            'source' => $g['source'], 'level' => $g['level'], 'kind' => $g['kind'],
            'message' => $g['message'], 'count' => (int)$g['n'], 'last' => (int)$g['last'],
            'open' => (int)$g['hasOpen'] === 0 ? true : false,
        ];
    }, $groups);

    // 错误趋势（按 5 分钟桶，近 3 小时）
    $trend = [];
    $bucket = 5 * 60 * 1000;
    $from = $now - 3 * 3600 * 1000;
    $tr = db()->query("SELECT FLOOR(t/$bucket)*$bucket AS b, COUNT(*) AS n,
        SUM(source='client') AS c, SUM(source='server') AS s
        FROM sys_errors WHERE t >= $from GROUP BY b ORDER BY b ASC")->fetchAll();
    foreach ($tr as $r) {
        $trend[] = ['t' => (int)$r['b'], 'n' => (int)$r['n'], 'client' => (int)$r['c'], 'server' => (int)$r['s']];
    }

    // 服务健康探测
    $health = [];
    try { $v = db()->query('SELECT 1')->fetchColumn(); $health[] = ['name' => 'MySQL', 'ok' => ((int)$v === 1), 'detail' => (string)db()->query('SELECT VERSION()')->fetchColumn()]; }
    catch (Throwable $e) { $health[] = ['name' => 'MySQL', 'ok' => false, 'detail' => $e->getMessage()]; }
    $redisHost = cfgStr('REDIS_HOST', 'redis');
    $redisPort = cfgInt('REDIS_PORT', 6379);
    $sock = @fsockopen($redisHost, $redisPort, $errno, $errstr, 0.4);
    if ($sock) { fclose($sock); $health[] = ['name' => 'Redis', 'ok' => true, 'detail' => $redisHost . ':' . $redisPort]; }
    else { $health[] = ['name' => 'Redis', 'ok' => false, 'detail' => ($errstr ?: 'unreachable')]; }
    $health[] = ['name' => 'PHP', 'ok' => true, 'detail' => PHP_VERSION . ' / ' . PHP_OS_FAMILY];
    $health[] = ['name' => '磁盘', 'ok' => (diskSpace(DATA_DIR)['free'] ?? 0) > 100 * 1024 * 1024,
                 'detail' => diskSpace(DATA_DIR) ? round((diskSpace(DATA_DIR)['free'] / 1073741824), 2) . ' GiB 可用' : 'n/a'];

    // 数据库表健康
    $tables = [];
    foreach (db()->query("SELECT table_name AS name, table_rows AS `rows`, data_length+index_length AS bytes
            FROM information_schema.tables WHERE table_schema=DATABASE()
            ORDER BY (data_length+index_length) DESC")->fetchAll() as $t) {
        $tables[] = ['name' => $t['name'], 'rows' => (int)$t['rows'], 'bytes' => (int)$t['bytes']];
    }

    return [
        'now' => $now, 'stats' => $stats, 'groups' => $grouped, 'trend' => $trend,
        'health' => $health, 'tables' => $tables,
        'metrics' => buildMetrics(),
    ];
}

/** 修改管理密码：校验旧密码，生成 bcrypt 写入 data/admin.json */
function changeAdminPassword(string $old, string $new): void {
    $stored = adminHash();
    if ($stored === '' || !password_verify($old, $stored)) fail(401, '当前密码不正确');
    if (mb_strlen($new) < 8 || mb_strlen($new) > 128) fail(400, '新密码长度需 8-128 位');
    if ($new === $old) fail(400, '新密码不能与当前密码相同');
    $hash = password_hash($new, PASSWORD_DEFAULT);
    $f = adminHashFile();
    $ok = @file_put_contents($f, json_encode(['hash' => $hash, 'updatedAt' => nowMs()], JSON_UNESCAPED_UNICODE), LOCK_EX);
    if ($ok === false) fail(500, '写入密码文件失败，请检查 data 目录权限');
    @chmod($f, 0600);
    // 尽量把属主保持为 www-data（写文件时通过 CLI 可能是 root）
    if (function_exists('posix_geteuid') && posix_geteuid() === 0 && function_exists('posix_getpwnam')) {
        $u = posix_getpwnam('www-data');
        if ($u) @chown($f, $u['uid']);
        $g = posix_getgrnam('www-data');
        if ($g) @chgrp($f, $g['gid']);
    }
    logWrite('管理密码已修改', 'warn', 'auth', clientIp());
}

/* 启动建表 + 安装错误钩子 */
dbInit();
installErrorHooks();
