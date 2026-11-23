<?php
/**
 * 后台接口（已登录态）。由 index.php 在鉴权通过后引入。
 * 路径与响应格式与 Node 版一致。
 */
declare(strict_types=1);

/* ================================================================== */
/* 文章                                                                */
/* ================================================================== */
if ($path === '/api/articles/all' && $method === 'GET') {
    $rows = db()->query('SELECT * FROM articles ORDER BY created_at DESC')->fetchAll();
    jsonOut(200, ['articles' => array_map('articleRow', $rows)]);
}

if ($path === '/api/articles' && $method === 'POST') {
    $d = readJsonBody();
    $title = trim(s($d['title'] ?? '', 200));
    if ($title === '') fail(400, '标题不能为空');
    $now = nowMs();
    $pub = ($d['published'] ?? true) === false ? 0 : 1;
    db()->prepare('INSERT INTO articles (title,tag,body,cover,published,created_at,updated_at) VALUES (?,?,?,?,?,?,?)')
        ->execute([$title, trim(s($d['tag'] ?? '', 60)), s($d['body'] ?? ''), s($d['cover'] ?? '', 500), $pub, $now, $now]);
    $id = (int)db()->lastInsertId();
    logWrite('新建文章《' . $title . '》', 'info', 'article', clientIp());
    $r = db()->query('SELECT * FROM articles WHERE id=' . $id)->fetch();
    jsonOut(201, ['article' => articleRow($r)]);
}

if (preg_match('#^/api/articles/(\d+)$#', $path, $m) && $method === 'PUT') {
    $id = (int)$m[1];
    $st = db()->prepare('SELECT id FROM articles WHERE id=?');
    $st->execute([$id]);
    if (!$st->fetch()) fail(404, '文章不存在');
    $d = readJsonBody();
    $title = trim(s($d['title'] ?? '', 200));
    if ($title === '') fail(400, '标题不能为空');
    $pub = ($d['published'] ?? true) === false ? 0 : 1;
    db()->prepare('UPDATE articles SET title=?,tag=?,body=?,cover=?,published=?,updated_at=? WHERE id=?')
        ->execute([$title, trim(s($d['tag'] ?? '', 60)), s($d['body'] ?? ''), s($d['cover'] ?? '', 500), $pub, nowMs(), $id]);
    logWrite('更新文章《' . $title . '》#' . $id, 'info', 'article', clientIp());
    $r = db()->query('SELECT * FROM articles WHERE id=' . $id)->fetch();
    jsonOut(200, ['article' => articleRow($r)]);
}

if (preg_match('#^/api/articles/(\d+)$#', $path, $m) && $method === 'DELETE') {
    $id = (int)$m[1];
    $st = db()->prepare('DELETE FROM articles WHERE id=?');
    $st->execute([$id]);
    if ($st->rowCount() === 0) fail(404, '文章不存在');
    logWrite('删除文章 #' . $id, 'warn', 'article', clientIp());
    jsonOut(200, ['ok' => true]);
}

/* ================================================================== */
/* 图片上传（multipart）                                               */
/* ================================================================== */
if ($path === '/api/upload' && $method === 'POST') {
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) fail(400, '没有收到图片文件');
    $f = $_FILES['file'];
    if ($f['size'] > MAX_IMAGE_BYTES) fail(413, '图片不能超过 ' . (int)(MAX_IMAGE_BYTES / 1048576) . 'MB');
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($f['tmp_name']) ?: '';
    $map = ['image/png' => '.png', 'image/jpeg' => '.jpg', 'image/gif' => '.gif', 'image/webp' => '.webp'];
    if (!isset($map[$mime])) fail(400, '仅支持 PNG / JPG / GIF / WebP 图片');
    $name = bin2hex(random_bytes(16)) . $map[$mime];
    if (!move_uploaded_file($f['tmp_name'], UPLOAD_DIR . '/' . $name)) fail(500, '保存图片失败');
    jsonOut(201, ['url' => '/uploads/' . $name, 'filename' => $name, 'size' => $f['size']]);
}

/* ================================================================== */
/* 视频管理                                                            */
/* ================================================================== */
if ($path === '/api/admin/videos' && $method === 'GET') {
    $rows = videoRows();
    $w = videoWritable();
    $res = [
        'dir' => VIDEO_DIR, 'maxMB' => (int)(MAX_VIDEO_BYTES / 1048576),
        'totalBytes' => array_sum(array_column($rows, 'size')),
        'writable' => $w['ok'], 'videos' => $rows,
    ];
    if (!$w['ok']) $res['writeErr'] = $w['code'];
    if (VIDEO_DIR === '') $res['error'] = '还没有配置 VIDEO_DIR';
    jsonOut(200, $res);
}

// 原始字节流写盘（XHR 直接 send(blob)，不是 multipart）
if ($path === '/api/admin/videos' && $method === 'POST') {
    if (VIDEO_DIR === '') fail(400, '还没有配置 VIDEO_DIR');
    $want = safeVideoName((string)($_GET['name'] ?? ''));
    if ($want === '') fail(400, '文件名不合法，或扩展名不在支持列表里');
    $w = videoWritable();
    if (!$w['ok']) {
        $hint = stripos($w['code'], 'Permission') !== false ? '，Web 用户（www-data）对这个目录没有写权限，请先 chown' : '';
        fail(507, '视频目录写不进去（' . $w['code'] . '）' . $hint);
    }
    $declared = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($declared > MAX_VIDEO_BYTES) fail(413, '视频不能超过 ' . (int)(MAX_VIDEO_BYTES / 1048576) . 'MB');

    $name = uniqueVideoName($want);
    $dest = VIDEO_DIR . '/' . $name;
    $tmp = $dest . '.part-' . bin2hex(random_bytes(4));
    $in = fopen('php://input', 'rb');
    $out = fopen($tmp, 'wb');
    if (!$in || !$out) { if ($out) fclose($out); fail(500, '无法创建临时文件'); }
    $size = 0; $tooBig = false;
    while (!feof($in)) {
        $chunk = fread($in, 262144);
        if ($chunk === false || $chunk === '') break;
        $size += strlen($chunk);
        if ($size > MAX_VIDEO_BYTES) { $tooBig = true; break; }
        fwrite($out, $chunk);
    }
    fclose($in);
    fclose($out);
    if ($tooBig || $size === 0) {
        @unlink($tmp);
        fail($tooBig ? 413 : 400, $tooBig ? '视频不能超过 ' . (int)(MAX_VIDEO_BYTES / 1048576) . 'MB' : '没有收到文件内容');
    }
    if (!rename($tmp, $dest)) { @unlink($tmp); fail(500, '写入失败'); }
    logWrite('视频上传完成 ' . $name . '（' . round($size / 1048576, 1) . ' MB）', 'info', 'video', clientIp());
    jsonOut(201, ['ok' => true, 'name' => $name, 'size' => $size]);
}

if ($path === '/api/admin/videos' && $method === 'DELETE') {
    if (VIDEO_DIR === '') fail(400, '还没有配置 VIDEO_DIR');
    $raw = (string)($_GET['name'] ?? '');
    $name = basename($raw);
    if ($name === '' || $name !== $raw || !preg_match(VIDEO_EXT_RE, $name)) fail(400, '文件名不合法');
    $full = VIDEO_DIR . '/' . $name;
    if (!is_file($full)) fail(404, '视频不存在');
    if (!@unlink($full)) fail(500, '删除失败，请检查目录权限');
    db()->prepare('DELETE FROM danmaku WHERE video=?')->execute([$name]);
    logWrite('视频已删除 ' . $name, 'warn', 'video', clientIp());
    jsonOut(200, ['ok' => true]);
}

/* ================================================================== */
/* 弹幕管理                                                            */
/* ================================================================== */
if ($path === '/api/admin/danmaku' && $method === 'GET') {
    $videos = videoRows();
    $counts = [];
    foreach (db()->query('SELECT video, count(*) c FROM danmaku GROUP BY video')->fetchAll() as $r) {
        $counts[$r['video']] = (int)$r['c'];
    }
    $rows = [];
    foreach ($videos as $v) {
        $rows[] = ['name' => $v['name'], 'size' => $v['size'], 'count' => $counts[$v['name']] ?? 0, 'exists' => true];
    }
    foreach ($counts as $name => $c) {
        $found = false;
        foreach ($rows as $r) if ($r['name'] === $name) { $found = true; break; }
        if (!$found) $rows[] = ['name' => $name, 'size' => 0, 'count' => $c, 'exists' => false];
    }
    usort($rows, function ($a, $b) { return strcmp($a['name'], $b['name']); });
    $orphan = 0;
    foreach ($rows as $r) if (!$r['exists']) $orphan++;
    jsonOut(200, ['videos' => $rows, 'total' => array_sum($counts), 'orphan' => $orphan]);
}

if ($path === '/api/admin/danmaku/rows' && $method === 'GET') {
    $video = s($_GET['video'] ?? '', 400);
    if ($video === '') fail(400, '缺少 video 参数');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $size = min(200, max(10, (int)($_GET['size'] ?? 50)));
    $q = trim(s($_GET['q'] ?? '', 100));
    $where = $q ? 'video=? AND text LIKE ?' : 'video=?';
    $args = $q ? [$video, '%' . $q . '%'] : [$video];
    $st = db()->prepare("SELECT count(*) FROM danmaku WHERE $where");
    $st->execute($args);
    $total = (int)$st->fetchColumn();
    $st = db()->prepare("SELECT id,t,text,color,ip_hash,created_at FROM danmaku WHERE $where
        ORDER BY t ASC,id ASC LIMIT $size OFFSET " . (($page - 1) * $size));
    $st->execute($args);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $rows[] = [
            'id' => (int)$r['id'], 't' => (float)$r['t'], 'text' => $r['text'], 'color' => $r['color'],
            'ip' => $r['ip_hash'] ? substr($r['ip_hash'], 0, 8) : '', 'time' => (int)$r['created_at'],
        ];
    }
    jsonOut(200, ['video' => $video, 'total' => $total, 'page' => $page, 'size' => $size,
                  'hasMore' => ($page * $size) < $total, 'rows' => $rows]);
}

if ($path === '/api/admin/danmaku/delete' && $method === 'POST') {
    $d = readJsonBody(16384);
    $id = (int)($d['id'] ?? 0);
    if ($id <= 0) fail(400, '弹幕 id 不合法');
    $st = db()->prepare('DELETE FROM danmaku WHERE id=?');
    $st->execute([$id]);
    if ($st->rowCount() === 0) fail(404, '弹幕不存在');
    logWrite('删除弹幕 #' . $id, 'warn', 'danmaku', clientIp());
    jsonOut(200, ['ok' => true]);
}

if ($path === '/api/admin/danmaku/clear' && $method === 'POST') {
    $d = readJsonBody(16384);
    $all = ($d['all'] ?? false) === true || ($d['all'] ?? 0) === 1;
    $video = $all ? '' : s($d['video'] ?? '', 400);
    if (!$all && $video === '') fail(400, '缺少 video 参数');
    if ($all) {
        $removed = (int)db()->query('DELETE FROM danmaku')->rowCount();
    } else {
        $st = db()->prepare('DELETE FROM danmaku WHERE video=?');
        $st->execute([$video]);
        $removed = $st->rowCount();
    }
    logWrite('清空弹幕 ' . ($all ? '（全部）' : $video) . '，共 ' . $removed . ' 条', 'warn', 'danmaku', clientIp());
    jsonOut(200, ['ok' => true, 'removed' => $removed]);
}

/* ================================================================== */
/* 站点统计                                                            */
/* ================================================================== */
if ($path === '/api/stats' && $method === 'GET') {
    $total = (int)db()->query('SELECT count(*) FROM articles')->fetchColumn();
    $pub = (int)db()->query('SELECT count(*) FROM articles WHERE published=1')->fetchColumn();
    $views = (int)db()->query('SELECT coalesce(sum(views),0) FROM articles')->fetchColumn();
    $dm = (int)db()->query('SELECT count(*) FROM danmaku')->fetchColumn();
    $bytes = 0;
    foreach (scandir(UPLOAD_DIR) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        if (is_file(UPLOAD_DIR . '/' . $f)) $bytes += (int)filesize(UPLOAD_DIR . '/' . $f);
    }
    jsonOut(200, ['total' => $total, 'published' => $pub, 'drafts' => $total - $pub,
                  'views' => $views, 'uploadBytes' => $bytes, 'danmaku' => $dm]);
}

/* ================================================================== */
/* 数据库页                                                            */
/* ================================================================== */
function validTable(string $name): bool { return $name !== '' && in_array($name, userTables(), true); }
function tableColumns(string $table): array {
    if (!validTable($table)) return [];
    $rows = db()->query('SELECT column_name AS name, data_type AS type, column_key AS colkey
        FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=' . db()->quote($table) . '
        ORDER BY ordinal_position')->fetchAll();
    return array_map(function ($c) {
        return ['name' => $c['name'], 'type' => strtoupper($c['type']),
                'pk' => ($c['colkey'] === 'PRI'), 'notnull' => false];
    }, $rows);
}
function qIdent(string $name): string { return '`' . str_replace('`', '``', $name) . '`'; }
function csvCell($v): string {
    if ($v === null) return '';
    $s = (string)$v;
    return preg_match('/[",\n\r]/', $s) ? '"' . str_replace('"', '""', $s) . '"' : $s;
}
function dbSizeBytes(): int {
    return (int)db()->query('SELECT coalesce(sum(data_length+index_length),0) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn();
}

if ($path === '/api/admin/db' && $method === 'GET') {
    $tables = [];
    foreach (userTables() as $name) {
        $tables[] = [
            'name' => $name,
            'rows' => (int)db()->query('SELECT count(*) FROM ' . qIdent($name))->fetchColumn(),
            'cols' => count(tableColumns($name)),
        ];
    }
    $ver = (string)db()->query('SELECT VERSION()')->fetchColumn();
    // 字段名保持与 Node 版一致（前端读 walSize / shmSize / pageSize / sqlite / file）
    jsonOut(200, ['tables' => $tables, 'file' => 'MySQL://' . cfgStr('DB_NAME', 'blog') . '/tables',
                  'size' => dbSizeBytes(), 'walSize' => 0, 'shmSize' => 0, 'pageSize' => 0, 'sqlite' => $ver]);
}

if ($path === '/api/admin/db/rows' && $method === 'GET') {
    $name = s($_GET['table'] ?? '', 80);
    if (!validTable($name)) fail(404, '表不存在');
    $cols = tableColumns($name);
    $size = min(200, max(5, (int)($_GET['size'] ?? 25)));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $kw = trim(s($_GET['q'] ?? '', 120));
    $where = ''; $args = [];
    if ($kw !== '') {
        $textCols = array_slice(array_filter($cols, function ($c) {
            return preg_match('/CHAR|TEXT|ENUM|SET/i', $c['type']);
        }), 0, 8);
        if (!$textCols) $textCols = array_slice($cols, 0, 4);
        if ($textCols) {
            $parts = [];
            foreach ($textCols as $c) { $parts[] = qIdent($c['name']) . ' LIKE ?'; $args[] = '%' . $kw . '%'; }
            $where = ' WHERE (' . implode(' OR ', $parts) . ')';
        }
    }
    $st = db()->prepare('SELECT count(*) FROM ' . qIdent($name) . $where);
    $st->execute($args);
    $total = (int)$st->fetchColumn();
    $sortIn = s($_GET['sort'] ?? '', 60);
    $sort = in_array($sortIn, array_column($cols, 'name'), true) ? qIdent($sortIn) : '1';
    $dir = strtolower((string)($_GET['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
    $st = db()->prepare('SELECT * FROM ' . qIdent($name) . $where . ' ORDER BY ' . $sort . ' ' . $dir .
        ' LIMIT ' . $size . ' OFFSET ' . (($page - 1) * $size));
    $st->execute($args);
    $rows = $st->fetchAll();
    jsonOut(200, ['table' => $name, 'columns' => $cols, 'rows' => $rows, 'total' => $total,
                  'page' => $page, 'pageSize' => $size, 'pages' => max(1, (int)ceil($total / $size)),
                  'sort' => $sortIn ?: '1', 'dir' => $dir]);
}

if ($path === '/api/admin/db/export' && $method === 'GET') {
    $what = strtolower((string)($_GET['type'] ?? 'csv'));
    if ($what === 'db') {
        // 用纯 PHP 导出整库为 SQL（不依赖 mysqldump 命令，共享主机也能用）
        $stamp = date('YmdHis');
        $sql = "-- MySQL dump\n-- " . date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";
        foreach (userTables() as $t) {
            $create = db()->query('SHOW CREATE TABLE ' . qIdent($t))->fetch();
            $ddl = $create['Create Table'] ?? '';
            $sql .= "DROP TABLE IF EXISTS " . qIdent($t) . ";\n" . $ddl . ";\n";
            foreach (db()->query('SELECT * FROM ' . qIdent($t))->fetchAll() as $r) {
                $vals = array_map(function ($v) {
                    if ($v === null) return 'NULL';
                    return db()->quote((string)$v);
                }, array_values($r));
                $sql .= 'INSERT INTO ' . qIdent($t) . ' VALUES (' . implode(',', $vals) . ");\n";
            }
            $sql .= "\n";
        }
        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
        logWrite('下载数据库快照（' . round(strlen($sql) / 1048576, 2) . ' MB）', 'info', 'db', clientIp());
        rawOut(200, $sql, [
            'Content-Type: application/sql; charset=utf-8',
            'Content-Disposition: attachment; filename="blog-backup-' . $stamp . '.sql"',
        ]);
    }
    $name = s($_GET['table'] ?? '', 80);
    if (!validTable($name)) fail(404, '表不存在');
    $cols = tableColumns($name);
    $csv = "\xEF\xBB\xBF";
    $csv .= implode(',', array_map(function ($c) { return csvCell($c['name']); }, $cols)) . "\r\n";
    foreach (db()->query('SELECT * FROM ' . qIdent($name))->fetchAll() as $r) {
        $csv .= implode(',', array_map(function ($c) use ($r) { return csvCell($r[$c['name']] ?? ''); }, $cols)) . "\r\n";
    }
    rawOut(200, $csv, [
        'Content-Type' => 'text/csv; charset=utf-8',
        'Content-Disposition: attachment; filename="' . $name . '.csv"',
    ]);
}

if ($path === '/api/admin/db/query' && $method === 'POST') {
    $d = readJsonBody();
    $sql = trim((string)($d['sql'] ?? ''));
    if ($sql === '') fail(400, '请输入 SQL');
    if (strlen($sql) > 4000) fail(400, 'SQL 太长');
    $sql = rtrim($sql, "; \t\n\r\0\x0B");
    if (!preg_match('/^(select|with|explain|show|describe|desc)\b/i', $sql)) fail(400, '只允许 SELECT / WITH / EXPLAIN 等只读查询');
    if (preg_match('/;\s*\S/', $sql)) fail(400, '一次只能执行一条语句');
    $clean = preg_replace("/'[^']*'/", "''", $sql) ?? $sql;
    if (preg_match('/\b(insert|update|delete|drop|alter|create|replace|truncate|grant|revoke|set|call|load)\b/i', $clean))
        fail(400, '只读模式：不允许写操作');
    $t0 = microtime(true);
    try {
        $rows = db()->query($sql)->fetchAll();
        $cols = $rows ? array_keys($rows[0]) : [];
        jsonOut(200, ['columns' => $cols, 'rows' => array_slice($rows, 0, 200),
                      'rowCount' => count($rows), 'truncated' => count($rows) > 200,
                      'ms' => (int)round((microtime(true) - $t0) * 1000)]);
    } catch (PDOException $e) {
        fail(400, 'SQL 执行失败：' . $e->getMessage());
    }
}

if ($path === '/api/admin/db/maintain' && $method === 'POST') {
    $d = readJsonBody();
    $action = (string)($d['action'] ?? '');
    $beforeSize = dbSizeBytes();
    $before = ['size' => $beforeSize];
    try {
        if ($action === 'vacuum') {
            foreach (userTables() as $t) db()->exec('OPTIMIZE TABLE ' . qIdent($t));
        } elseif ($action === 'checkpoint') {
            db()->exec('FLUSH TABLES');
        } elseif ($action === 'orphan') {
            $keep = array_column(videoRows(), 'name');
            $removed = 0;
            $st = db()->prepare('DELETE FROM danmaku WHERE video=?');
            foreach (db()->query('SELECT DISTINCT video FROM danmaku')->fetchAll() as $r) {
                if (!in_array($r['video'], $keep, true)) {
                    $st->execute([$r['video']]);
                    $removed += $st->rowCount();
                }
            }
            $after = ['size' => dbSizeBytes()];
            logWrite('数据库维护 orphan：清理孤立弹幕 ' . $removed . ' 条', 'warn', 'db', clientIp());
            jsonOut(200, ['ok' => true, 'action' => $action, 'removed' => $removed, 'before' => $before, 'after' => $after]);
        } else {
            fail(400, '不支持的操作');
        }
    } catch (PDOException $e) {
        logWrite('数据库维护失败 ' . $action . '：' . $e->getMessage(), 'error', 'db', clientIp());
        fail(500, '维护失败：' . $e->getMessage());
    }
    $after = ['size' => dbSizeBytes()];
    logWrite('数据库维护 ' . $action . '，大小 ' . $beforeSize . ' → ' . $after['size'] . ' 字节', 'info', 'db', clientIp());
    jsonOut(200, ['ok' => true, 'action' => $action, 'before' => $before, 'after' => $after]);
}

/* ================================================================== */
/* 日志页                                                              */
/* ================================================================== */
if ($path === '/api/admin/logs' && $method === 'GET') {
    $level = strtolower((string)($_GET['level'] ?? ''));
    $kind = strtolower(s($_GET['kind'] ?? '', 20));
    $kw = strtolower(trim(s($_GET['q'] ?? '', 120)));
    $limit = min(500, max(10, (int)($_GET['limit'] ?? 200)));
    $before = (int)($_GET['before'] ?? 0);
    $rows = logRead(max($limit, LOG_BUFFER));
    if ($level === 'warn+') $rows = array_values(array_filter($rows, function ($r) { return $r['level'] !== 'info'; }));
    elseif ($level !== '' && $level !== 'all') $rows = array_values(array_filter($rows, function ($r) use ($level) { return $r['level'] === $level; }));
    if ($kind !== '' && $kind !== 'all') $rows = array_values(array_filter($rows, function ($r) use ($kind) { return $r['kind'] === $kind; }));
    if ($kw !== '') $rows = array_values(array_filter($rows, function ($r) use ($kw) {
        return strpos(strtolower($r['msg']), $kw) !== false || strpos($r['ip'], $kw) !== false || strpos($r['kind'], $kw) !== false;
    }));
    if ($before > 0) $rows = array_values(array_filter($rows, function ($r) use ($before) { return $r['id'] < $before; }));
    $total = count($rows);
    $page = array_reverse(array_slice($rows, -$limit));   // 最新在前
    $kinds = array_values(array_unique(array_column(logRead(LOG_BUFFER), 'kind')));
    sort($kinds);
    jsonOut(200, [
        'logs' => $page, 'total' => $total,
        'hasMore' => ($page ? $page[count($page) - 1]['id'] : 0) > 1 && $total > count($page),
        'counts' => logCounts(), 'kinds' => $kinds, 'buffered' => min(LOG_BUFFER, count(logRead(LOG_BUFFER))),
        'file' => ['path' => LOG_FILE, 'size' => is_file(LOG_FILE) ? filesize(LOG_FILE) : 0, 'max' => LOG_FILE_MAX],
        'bufferMax' => LOG_BUFFER,
    ]);
}

if ($path === '/api/admin/logs/export' && $method === 'GET') {
    $stamp = date('YmdHis');
    $body = is_file(LOG_FILE) ? (string)file_get_contents(LOG_FILE) : '';
    rawOut(200, $body, [
        'Content-Type: text/plain; charset=utf-8',
        'Content-Disposition: attachment; filename="blog-log-' . $stamp . '.log"',
    ]);
}

if ($path === '/api/admin/logs' && $method === 'DELETE') {
    $removed = min(LOG_BUFFER, count(logRead(LOG_BUFFER)));
    @file_put_contents(LOG_FILE, '', LOCK_EX);
    @file_put_contents(LOG_FILE . '.1', '', LOCK_EX);
    db()->query('UPDATE sys_logcount SET info=0,warn=0,error=0 WHERE id=1');
    logWrite('日志已清空（' . $removed . ' 条）', 'info', 'system', clientIp());
    jsonOut(200, ['ok' => true, 'removed' => $removed]);
}

/* ================================================================== */
/* 监控                                                                */
/* ================================================================== */
if ($path === '/api/admin/metrics' && $method === 'GET') {
    sampleIfDue();
    jsonOut(200, buildMetrics());
}

if ($path === '/api/admin/observability' && $method === 'GET') {
    sampleIfDue();
    jsonOut(200, buildObservability());
}

/* ---------- 错误追踪（bug 中心） ---------- */
if ($path === '/api/admin/errors' && $method === 'GET') {
    $limit = min(500, max(10, (int)($_GET['limit'] ?? 100)));
    $source = strtolower((string)($_GET['source'] ?? 'all'));
    $level  = strtolower((string)($_GET['level'] ?? 'all'));
    $state  = strtolower((string)($_GET['state'] ?? 'all'));
    $kw     = strtolower(trim(s($_GET['q'] ?? '', 120)));
    $where = ['1=1'];
    if (in_array($source, ['client', 'server'], true)) $where[] = "source=" . db()->quote($source);
    if ($level === 'error') $where[] = "level IN ('error','fatal')";
    elseif (in_array($level, ['warn', 'info', 'fatal'], true)) $where[] = "level=" . db()->quote($level);
    if ($state === 'open') $where[] = 'resolved=0';
    elseif ($state === 'resolved') $where[] = 'resolved=1';
    if ($kw !== '') $where[] = '(LOWER(message) LIKE ' . db()->quote('%' . $kw . '%') .
        ' OR LOWER(kind) LIKE ' . db()->quote('%' . $kw . '%') .
        ' OR LOWER(stack) LIKE ' . db()->quote('%' . $kw . '%') . ')';
    $sql = 'SELECT id,t,source,level,kind,message,stack,page,ip_hash,resolved,resolved_at
        FROM sys_errors WHERE ' . implode(' AND ', $where) . ' ORDER BY t DESC,id DESC LIMIT ' . $limit;
    $rows = db()->query($sql)->fetchAll();
    jsonOut(200, [
        'errors' => array_map(function ($r) {
            return [
                'id' => (int)$r['id'], 'ts' => (int)$r['t'], 'source' => $r['source'],
                'level' => $r['level'], 'kind' => $r['kind'], 'message' => $r['message'],
                'stack' => $r['stack'], 'page' => $r['page'], 'ip' => $r['ip_hash'],
                'resolved' => (bool)$r['resolved'], 'resolvedAt' => (int)$r['resolved_at'],
            ];
        }, $rows),
        'count' => count($rows),
    ]);
}

if ($path === '/api/admin/errors/resolve' && $method === 'POST') {
    $d = readJsonBody();
    $ids = $d['ids'] ?? (isset($d['id']) ? [$d['id']] : []);
    if (!is_array($ids) || !$ids) fail(400, '请选择要处理的错误');
    $ids = array_slice(array_values(array_filter(array_map('intval', $ids))), 0, 500);
    if (!$ids) fail(400, '无效的错误 ID');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("UPDATE sys_errors SET resolved=1, resolved_at=? WHERE id IN ($ph)");
    $st->execute(array_merge([nowMs()], $ids));
    logWrite('标记 ' . $st->rowCount() . ' 条错误为已处理', 'info', 'bug', clientIp());
    jsonOut(200, ['ok' => true, 'updated' => $st->rowCount()]);
}

if ($path === '/api/admin/errors' && $method === 'DELETE') {
    $n = (int)db()->query('SELECT COUNT(*) FROM sys_errors')->fetchColumn();
    db()->exec('TRUNCATE TABLE sys_errors');
    logWrite('清空错误中心（' . $n . ' 条）', 'warn', 'bug', clientIp());
    jsonOut(200, ['ok' => true, 'removed' => $n]);
}

/* ---------- 修改管理密码 ---------- */
if ($path === '/api/admin/password' && $method === 'POST') {
    $d = readJsonBody();
    $old = (string)($d['oldPassword'] ?? '');
    $new = (string)($d['newPassword'] ?? '');
    if ($old === '' || $new === '') fail(400, '请填写当前密码和新密码');
    changeAdminPassword($old, $new);
    jsonOut(200, ['ok' => true]);
}

/* ================================================================== */
/* 备份导入 / 导出                                                      */
/* ================================================================== */
if ($path === '/api/export' && $method === 'GET') {
    $rows = db()->query('SELECT * FROM articles ORDER BY created_at DESC')->fetchAll();
    jsonOut(200, ['version' => 2, 'exportedAt' => nowMs(), 'articles' => array_map('articleRow', $rows)]);
}

if ($path === '/api/import' && $method === 'POST') {
    $d = readJsonBody();
    if (!isset($d['articles']) || !is_array($d['articles'])) fail(400, '备份格式不正确');
    if (($d['mode'] ?? '') === 'replace') db()->exec('DELETE FROM articles');
    $now = nowMs();
    $n = 0;
    $st = db()->prepare('INSERT INTO articles (title,tag,body,cover,published,created_at,updated_at) VALUES (?,?,?,?,?,?,?)');
    foreach ($d['articles'] as $a) {
        if (!is_array($a) || !isset($a['title']) || !is_string($a['title'])) continue;
        $st->execute([
            s($a['title'], 200), s($a['tag'] ?? '', 60), s($a['body'] ?? ''), s($a['cover'] ?? '', 500),
            (($a['published'] ?? true) === false) ? 0 : 1,
            (int)($a['created'] ?? $now), (int)($a['updated'] ?? $a['created'] ?? $now),
        ]);
        $n++;
    }
    jsonOut(200, ['imported' => $n]);
}

/* 走到这里说明接口不存在 */
fail(404, '接口不存在');
