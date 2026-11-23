# 部署到服务器（LAMP 版）

> **先看这里** (๑•̀ㅂ•́)و✧ 本文所有 `deploy/`、`api/`、`assets/` 路径都相对**仓库根目录**。
> 这是服务器版（LAMP）；只想在自己电脑上跑，请用另一个仓库 [blur-qr-blog-Windows](https://github.com/ipc-mdd-gl3-1545/blur-qr-blog-Windows)。

本项目主推 **LAMP 架构**：Linux + Apache + MySQL + PHP。
后端用 PHP + PDO，前端是原生 HTML/JS，无需构建、无需 Node、无需常驻进程，
任何支持 PHP + MySQL 的主机（云服务器 / 虚拟机 / 共享虚拟主机）都能跑。

```
浏览器 ──HTTP/HTTPS──▶ Apache 2.4 ──┬─▶ 静态文件（HTML/CSS/JS/视频/图片）直出
                                    └─▶ /api/* ─▶ PHP-FPM ─▶ MySQL + 文件目录
```

> Node 单机版已拆到独立仓库，本仓库不含 `server.js`，LAMP 部署用不到它。

---

## 零、VMware 虚拟机里部署（Ubuntu 26.04）

虚拟机默认 NAT 网络没有公网 IP，分两种情况：

| 目标 | 做法 |
| --- | --- |
| 宿主机 / 局域网能打开 | 用桥接网络，或 NAT + 端口转发；服务监听 80 端口 |
| 真正的互联网能打开 | 云服务器 + 安全组放行 80/443，或内网穿透隧道 |

虚拟机装好系统、能联网后，把项目放到 `/var/www/blog`（用共享文件夹、scp 或分发服务都行），
然后执行下面的一键安装。

---

## 一、5 分钟快速部署（推荐）

### 1. 把项目放到网站根目录

```bash
sudo mkdir -p /var/www/blog && cd /var/www/blog
git clone https://github.com/ipc-mdd-gl3-1545/blur-qr-blog-Ubuntu.git .
# 或者：tar -xzf blog.tar.gz -C /var/www/blog
```

### 2. 执行一键安装

```bash
sudo bash deploy/lamp/install.sh
```

脚本会自动完成：

1. 安装 `apache2` `mysql-server` `php` `php-mysql` `php-mbstring` `php-xml` `php-curl` `php-fpm`
2. 随机生成数据库密码，建库 `blog`、建用户 `blog`、导入 `init.sql`
3. 生成 `api/config.php`（数据库密码、会话密钥、随机后台密码）
4. 设置目录属主为 `www-data`，配置文件权限 600
5. 启用 rewrite / headers / deflate 模块，安装 Apache 虚拟主机
6. 调高 PHP 上传上限到 4096M，写入 cron 每 10 秒采样监控
7. 重启 Apache 和 MySQL

装完会打印：

```
网站地址   http://你的IP/
后台地址   http://你的IP/manage
后台密码   xxxxxxxx        # 随机生成，只显示这一次，务必保存！
数据库密码 xxxxxxxx        # 已写入 api/config.php
```

### 3. 验证

```bash
curl -s http://127.0.0.1/healthz            # 应返回 {"ok":true,...}
systemctl status apache2 mysql --no-pager  # 两个都应 active
```

浏览器打开网站地址，能看首页即可。后台用上面打印的密码登录 `/manage`。

### 4. 防火墙 / 安全组

```bash
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp    # 配了 HTTPS 才需要
```

云服务器还要在控制台安全组放行对应端口。

---

## 二、手动部署（不想用脚本）

### 1. 装软件

```bash
sudo apt update
sudo apt install -y apache2 mysql-server php php-mysql php-mbstring php-xml php-fpm libapache2-mod-php
sudo a2enmod rewrite headers deflate
```

### 2. 建库和表

```bash
sudo mysql -u root < /var/www/blog/deploy/lamp/init.sql
sudo mysql -u root
```

在 MySQL 里建应用用户（把 `你的强密码` 换掉）：

```sql
CREATE USER IF NOT EXISTS 'blog'@'localhost' IDENTIFIED BY '你的强密码';
GRANT ALL PRIVILEGES ON blog.* TO 'blog'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

### 3. 写配置

```bash
cp /var/www/blog/api/config.example.php /var/www/blog/api/config.php
sudo nano /var/www/blog/api/config.php
# 重点改 DB_PASS / ADMIN_PASSWORD / SESSION_SECRET / 各目录路径
sudo chmod 600 /var/www/blog/api/config.php
```

### 4. 配 Apache

```bash
sudo cp /var/www/blog/deploy/lamp/apache-vhost.conf /etc/apache2/sites-available/blog.conf
# 先编辑 blog.conf 里的 ServerName 为你的 IP 或域名
sudo a2dissite 000-default
sudo a2ensite blog
sudo apache2ctl configtest     # 应显示 Syntax OK
sudo systemctl reload apache2
```

### 5. 目录权限

```bash
sudo chown -R www-data:www-data /var/www/blog
sudo chmod 755 /var/www/blog/{api,videos,uploads,data}
sudo chmod 600 /var/www/blog/api/config.php
```

### 6. PHP 上传上限

编辑 `/etc/php/8.x/fpm/php.ini`（x 是版本号）：

```ini
upload_max_filesize = 4096M
post_max_size = 4096M
max_execution_time = 1800
```

也可放在 `/var/www/blog/api/.user.ini`（PHP-FPM 会读取），改完 `sudo systemctl restart php8.x-fpm`。

---

## 三、`api/config.php` 配置说明

| 配置项 | 默认 | 说明 |
| --- | --- | --- |
| `DB_HOST` / `DB_PORT` | `127.0.0.1` / `3306` | MySQL 地址 |
| `DB_NAME` / `DB_USER` / `DB_PASS` | `blog` / `blog` / — | 数据库名、用户、密码 |
| `WEB_ROOT` | `/var/www/blog` | 网站根目录 |
| `DATA_DIR` | `/var/www/blog/data` | 日志、密码文件、会话密钥 |
| `UPLOAD_DIR` | `/var/www/blog/uploads` | 图片上传目录 |
| `VIDEO_DIR` | `/var/www/blog/videos` | 小影院视频目录 |
| `ADMIN_PATH` | `/manage` | 后台入口，改了要同步改 Apache rewrite |
| `ADMIN_PASSWORD` | — | 首次安装用来生成 bcrypt 哈希，写入 data/admin.json 后可留空 |
| `SESSION_SECRET` | 留空自动生成 | 会话签名密钥，生成后写入 data/session.key |
| `MAX_IMAGE_MB` | `5` | 单张图片上限 |
| `MAX_VIDEO_MB` | `4096` | 单个视频上限 |
| `DANMAKU_MAX_LEN` | `50` | 单条弹幕字数上限 |
| `DANMAKU_COOLDOWN_SEC` | `3` | 同一 IP 两次发送最小间隔（秒） |
| `LOG_FILE_MB` | `5` | 日志文件轮转阈值 |
| `METRIC_INTERVAL_SEC` | `10` | 监控采样间隔（秒） |
| `LOGIN_MAX_TRIES` | `8` | 10 分钟内登录尝试上限，超限锁 15 分钟 |
| `TRUST_PROXY` | `true` | 位于反代后面时读取 X-Forwarded-For |

> `api/config.php` 含密码，**绝不提交、不截图、不发群**（`.gitignore` 已排除）。

---

## 四、小影院：放本地视频

视频放在 `VIDEO_DIR`（默认 `/var/www/blog/videos`），由 Apache 直接读盘，支持 Range 拖动。

```bash
# 方式一：后台上传（推荐）
#   后台左侧 → 🎞️ 小影院 → 上传视频，带进度条

# 方式二：命令行拷贝
sudo cp /path/to/movie.mp4 /var/www/blog/videos/
sudo chown www-data:www-data /var/www/blog/videos/movie.mp4
```

视频目录要能被 `www-data` 写（后台上传用）和读（播放用）：

```bash
sudo chown -R www-data:www-data /var/www/blog/videos
sudo chmod 775 /var/www/blog/videos
```

- 视频删掉后，它的弹幕可在「弹幕」页或数据库页一键清理
- 浏览器能直接播 `mp4`(H.264) 和 `webm`；`mkv`/`avi` 先转码
- Apache 的 `LimitRequestBody` 已设为 4GB，PHP 的 `post_max_size` 也要 ≥ 视频大小

---

## 五、后台入口（不暴露在首页）

后台不在首页放链接，只能通过 `ADMIN_PATH`（默认 `/manage`）访问。
`/admin.html` 被 Apache 直接拒绝访问，必须走 rewrite 后的 PHP 入口。

左侧导航七页：

| 页 | 能做什么 |
| --- | --- |
| 概览 | 站点数据卡片 + 快捷入口 |
| 文章 | 写、改、删、存草稿、传封面 |
| 小影院 | 上传/删除视频，看目录占用 |
| 弹幕 | 按视频分组查看、搜索、单删、清空 |
| 数据库 | 表结构、翻数据、导出 CSV、下载整库 SQL 快照、只读 SQL、维护 |
| 日志 | 登录、发布、上传、报错，按级别/类别筛，可下载 |
| 监控 | CPU/内存/磁盘/请求/QPS + 趋势曲线 + 网络拓扑图 |

### 数据库页的安全设计

- 表名走 `information_schema` 白名单，列名走白名单，用户输入不拼进 SQL 标识符
- 只读查询只放行 `SELECT / WITH / EXPLAIN / SHOW / DESCRIBE`，禁多语句和写关键字
- 整库快照用纯 PHP 导出 SQL（不依赖 `mysqldump` 命令，共享主机也能用）
- 所有操作都在登录态后，写操作有同源校验

### 监控数据怎么来的

Node 版靠常驻进程内存采样；PHP 无常驻进程，所以：

- 请求统计、采样点、登录限速、弹幕冷却都存 **MySQL 的 `sys_` 表**（多进程安全）
- cron 每 10 秒调用 `api/sample.php` 采一个点；没人访问监控页时也有数据
- 即使没配 cron，访问监控页时也会按需补采，不会完全空白
- 日志直接写 `data/blog.log`，后台从文件尾部读取，超 5MB 轮转

---

## 六、日常运维

### 备份

```bash
# 方式一：后台「数据库」页点「下载整库快照」（.sql 文件）
# 方式二：命令行 mysqldump
mysqldump -u blog -p blog > /backup/blog-$(date +%F).sql
# 图片和视频单独打包
tar -czf /backup/uploads-$(date +%F).tar.gz /var/www/blog/uploads /var/www/blog/videos
```

建议加个 crontab 每天凌晨备份：

```bash
crontab -e
# 每天 03:00 备份数据库，保留最近 14 天
0 3 * * * mysqldump -u blog -p'你的密码' blog > /backup/blog-$(date +\%F).sql && find /backup -name 'blog-*.sql' -mtime +14 -delete
```

### 恢复

```bash
mysql -u blog -p blog < /backup/blog-2026-11-22.sql
```

### 升级代码

```bash
cd /var/www/blog && git pull
# PHP 文件改完立即生效，不用重启；改了 Apache 配置才需要 reload
sudo systemctl reload apache2
```

### 改后台密码

```bash
php -r 'echo password_hash("新密码", PASSWORD_DEFAULT), "\n";'
# 把输出的那串写进 data/admin.json 的 hash 字段
sudo nano /var/www/blog/data/admin.json
```

---

## 七、安全已做的措施

- bcrypt 口令哈希（PHP `password_hash`），不再用明文库
- HMAC-SHA256 签名会话 Cookie（HttpOnly + SameSite=Strict + 可选 Secure）
- 登录限速：同一 IP 10 分钟内 8 次失败锁定 15 分钟（存 MySQL）
- 写操作同源校验（Origin / Sec-Fetch-Site）+ SameSite Cookie 双防 CSRF
- CSP / X-Frame-Options / nosniff / Referrer-Policy 等安全响应头
- Apache 屏蔽 `api/`、`data/`、`config.php`、`.env`、`*.db`、`*.sql`、`*.log` 等敏感路径
- 数据库全 PDO 预处理，动态标识符走白名单
- 视频名消毒：拒绝路径穿越、隐藏文件、非法扩展

---

## 八、常见问题

**后台打开是空白 / 500**
先看 Apache 错误日志：`sudo tail -50 /var/log/apache2/blog-error.log`。
常见原因是 `api/config.php` 没写或数据库密码错。

**上传视频超时 / 413**
PHP 的 `upload_max_filesize`、`post_max_size` 要 ≥ 视频大小（建议 4096M），
Apache 的 `LimitRequestBody` 已设 4GB。改完 PHP 配置要重启 PHP-FPM。

**视频放上去但播放是 404**
确认视频在 `VIDEO_DIR` 里、扩展名受支持、属主是 `www-data`。
播放走 `/api/play?local=xxx`，由 PHP 读盘输出 Range（不是 Apache 直出文件本身）。

**监控页没数据**
确认 cron 在跑：`grep sample.php /etc/crontab`。
或先访问一次监控页触发按需采样，等 10 秒再看。

**数据库页显示 `—`**
PHP 需要 `pdo_mysql` 扩展：`sudo apt install php-mysql` 后重启 PHP-FPM。

**日志显示 `ExperimentalWarning: SQLite is an experimental feature`**
LAMP 版已无 SQLite，这条是 Node 版遗留，看到说明你在看旧部署，改用 LAMP 即可。

---

## 九、目录结构

```
/var/www/blog/
├── index.html / article.html / *.html   前台页面
├── admin.html            后台页面（不直接暴露，经 /manage 返回）
├── api/                  LAMP 后端
│   ├── bootstrap.php     配置/数据库/会话/日志/监控
│   ├── index.php         API 单入口
│   ├── admin.php         后台接口
│   ├── manage.php        后台页面入口
│   ├── sample.php        cron 采样
│   ├── config.php        配置（含密码，不进仓库）
│   └── config.example.php
├── deploy/lamp/          LAMP 部署脚本
│   ├── install.sh        一键安装
│   ├── apache-vhost.conf Apache 配置
│   └── init.sql          建库脚本
├── assets/               静态资源
├── data/                 日志、密码哈希、会话密钥（不进仓库）
├── uploads/              图片（不进仓库）
└── videos/               视频（不进仓库）
```

---

## 附录：Node 单机版去哪了

Node 版（`server.js` + 内置 `node:sqlite`，不需要 MySQL）已经拆成独立仓库：

**https://github.com/ipc-mdd-gl3-1545/blur-qr-blog-Windows**

本仓库只保留 LAMP 这套，`server.js`、`tools/`、`deploy/install.sh`、`deploy/blog.service`
都不在这里了，所以别再照旧文档去 `node server.js` 啦 (´･ω･`)

两者共用同一套前端 HTML，但数据不互通：

- 本仓库：MySQL 库 `blog`
- Node 版：SQLite 文件 `data/blog.db`

要在两边搬文章，用后台的「导出 JSON / 导入 JSON」；
弹幕存在另一张表里，需要单独导出（后台「弹幕」页支持按视频查看）。
