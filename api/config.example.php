<?php
/**
 * LAMP 版配置样例。部署时复制为 config.php 并修改。
 * config.php 含数据库密码和会话密钥，切勿提交到 Git。
 */
return [
    // ---- MySQL 连接 ----
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => 3306,
    'DB_NAME' => 'blog',
    'DB_USER' => 'blog',
    'DB_PASS' => '请改成强密码',
    'DB_CHARSET' => 'utf8mb4',

    // ---- 站点路径（用绝对路径，别用相对路径）----
    'WEB_ROOT'   => '/var/www/blog',        // 网站根目录（放 index.html 的地方）
    'DATA_DIR'   => '/var/www/blog/data',   // 日志、密码文件、会话密钥
    'UPLOAD_DIR' => '/var/www/blog/uploads',// 图片上传目录
    'VIDEO_DIR'  => '/var/www/blog/videos', // 小影院视频目录

    // ---- 后台入口与会话 ----
    'ADMIN_PATH'        => '/manage',  // 后台路径，改了要同步改 Apache rewrite
    'ADMIN_PASSWORD'    => '请改成强密码', // 首次安装用来生成哈希；装完会自动写入 data/admin.json 并可留空
    'SESSION_TTL_HOURS' => 12,
    'SESSION_SECRET'    => '',         // 留空则首次运行自动生成到 data/session.key

    // ---- 上传大小限制（MB）----
    'MAX_IMAGE_MB' => 5,
    'MAX_VIDEO_MB' => 4096,

    // ---- 弹幕 ----
    'DANMAKU_MAX_LEN'       => 50,
    'DANMAKU_COOLDOWN_SEC'  => 3,
    'DANMAKU_FETCH_MAX'     => 2000,

    // ---- 日志与监控 ----
    'LOG_REQUESTS'       => true,
    'LOG_BUFFER'         => 1200,   // 后台日志页最多显示多少条（从文件尾部读）
    'LOG_FILE_MB'        => 5,      // 日志文件轮转阈值
    'METRIC_INTERVAL_SEC'=> 10,     // 采样间隔（由 cron 或访问时触发）
    'METRIC_HISTORY'     => 180,    // 保留采样点数（30 分钟）

    // ---- 安全 ----
    'TRUST_PROXY'  => true,   // 位于 Nginx/反代后面时开启，读取 X-Forwarded-For
    'LOGIN_MAX_TRIES' => 8,   // 10 分钟内最多尝试次数，超限锁定 15 分钟
];
