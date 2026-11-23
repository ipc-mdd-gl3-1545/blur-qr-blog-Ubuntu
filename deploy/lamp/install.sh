#!/usr/bin/env bash
# 模糊二维码 · 博客 LAMP 版一键安装（Ubuntu 22.04 / 24.04 / 26.04）
# 用法：把整个项目放到 /var/www/blog，然后 sudo bash deploy/lamp/install.sh
set -euo pipefail

APP=/var/www/blog
DB_NAME=blog
DB_USER=blog
ADMIN_PATH=/manage

green() { echo -e "\033[32m$*\033[0m"; }
yellow() { echo -e "\033[33m$*\033[0m"; }

if [ "$EUID" -ne 0 ]; then echo "请用 sudo 运行"; exit 1; fi
if [ ! -f "$APP/index.html" ]; then echo "找不到 $APP/index.html，请先把项目放到该目录"; exit 1; fi

echo "==> 1/7 安装 Apache / MySQL / PHP"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y apache2 mysql-server php php-mysql php-mbstring php-xml php-curl php-fpm libapache2-mod-php

echo "==> 2/7 生成数据库密码并初始化"
DB_PASS=$(openssl rand -hex 18)
mysql -u root <<SQL
CREATE DATABASE IF NOT EXISTS ${DB_NAME} DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
mysql -u root "${DB_NAME}" < "$APP/deploy/lamp/init.sql"

echo "==> 3/7 写配置"
ADMIN_PW=$(openssl rand -hex 9)
SECRET=$(openssl rand -hex 32)
mkdir -p "$APP/data" "$APP/uploads" "$APP/videos"
cat > "$APP/api/config.php" <<PHP
<?php
return [
  'DB_HOST' => '127.0.0.1',
  'DB_PORT' => 3306,
  'DB_NAME' => '${DB_NAME}',
  'DB_USER' => '${DB_USER}',
  'DB_PASS' => '${DB_PASS}',
  'DB_CHARSET' => 'utf8mb4',
  'WEB_ROOT' => '${APP}',
  'DATA_DIR' => '${APP}/data',
  'UPLOAD_DIR' => '${APP}/uploads',
  'VIDEO_DIR' => '${APP}/videos',
  'ADMIN_PATH' => '${ADMIN_PATH}',
  'ADMIN_PASSWORD' => '${ADMIN_PW}',
  'SESSION_SECRET' => '${SECRET}',
  'SESSION_TTL_HOURS' => 12,
  'MAX_IMAGE_MB' => 5,
  'MAX_VIDEO_MB' => 4096,
  'DANMAKU_MAX_LEN' => 50,
  'DANMAKU_COOLDOWN_SEC' => 3,
  'DANMAKU_FETCH_MAX' => 2000,
  'LOG_REQUESTS' => true,
  'LOG_BUFFER' => 1200,
  'LOG_FILE_MB' => 5,
  'METRIC_INTERVAL_SEC' => 10,
  'METRIC_HISTORY' => 180,
  'TRUST_PROXY' => true,
  'LOGIN_MAX_TRIES' => 8,
];
PHP
chmod 600 "$APP/api/config.php"

echo "==> 4/7 设置目录权限"
chown -R www-data:www-data "$APP"
chmod 755 "$APP" "$APP/api" "$APP/videos" "$APP/uploads" "$APP/data"
chmod 600 "$APP/api/config.php"

echo "==> 5/7 配置 Apache"
a2enmod rewrite headers deflate >/dev/null 2>&1 || true
cp "$APP/deploy/lamp/apache-vhost.conf" /etc/apache2/sites-available/blog.conf
a2dissite 000-default >/dev/null 2>&1 || true
a2ensite blog >/dev/null 2>&1
apache2ctl configtest

echo "==> 6/7 配置 PHP 上传上限与 cron 采样"
PHP_INI=$(php --ini | grep 'Loaded Configuration File' | awk '{print $NF}')
if [ -n "$PHP_INI" ] && [ -f "$PHP_INI" ]; then
  sed -i 's/^upload_max_filesize.*/upload_max_filesize = 4096M/' "$PHP_INI"
  sed -i 's/^post_max_size.*/post_max_size = 4096M/' "$PHP_INI"
  sed -i 's/^max_execution_time.*/max_execution_time = 1800/' "$PHP_INI"
  grep -q '^upload_max_filesize' "$PHP_INI" || echo 'upload_max_filesize = 4096M' >> "$PHP_INI"
  grep -q '^post_max_size' "$PHP_INI" || echo 'post_max_size = 4096M' >> "$PHP_INI"
fi
# cron：每 10 秒采一个监控点（用循环睡眠实现秒级）
CRON_LINE="* * * * * root for i in 1 2 3 4 5 6; do curl -s http://127.0.0.1/api/sample.php >/dev/null 2>&1; sleep 10; done"
if ! grep -qF 'api/sample.php' /etc/crontab 2>/dev/null; then
  echo "$CRON_LINE" >> /etc/crontab
fi

echo "==> 7/7 重启服务"
systemctl restart mysql
systemctl restart apache2
systemctl enable apache2 mysql >/dev/null 2>&1 || true

echo
green "================ 安装完成 ================"
echo "网站地址     http://$(hostname -I | awk '{print $1}')/"
echo "后台地址     http://$(hostname -I | awk '{print $1}')${ADMIN_PATH}"
yellow "后台初始密码 $ADMIN_PW   （只显示这一次，务必保存！）"
echo "数据库       ${DB_NAME} / ${DB_USER}"
yellow "数据库密码   ${DB_PASS}   （已写入 api/config.php）"
echo
echo "视频放到     $APP/videos  （chown www-data:www-data）"
echo "日志文件     $APP/data/blog.log"
echo "配置文件     $APP/api/config.php"
echo
