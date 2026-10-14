FROM php:8.2-apache

# 安装 PostgreSQL 驱动、系统基础依赖、Python 3 及 pip
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev \
    libzip-dev \
    libonig-dev \
    unzip \
    tzdata \
    python3 \
    python3-pip \
    && rm -rf /var/lib/apt/lists/*

# 安装 FastMCP 官方 SDK (仅锁 1.x: mcp 2.x 已移除 mcp.server.fastmcp 模块, 会导致导入即崩)
RUN pip3 install --no-cache-dir --break-system-packages "mcp>=1.30,<2"

# 安装 PHP 扩展 (pdo_pgsql, pgsql, opcache, mbstring 中文支持)
RUN docker-php-ext-install pdo pdo_pgsql pgsql opcache mbstring

# 应用生产级 PHP 配置 (display_errors=Off、opcache、时区)
COPY docker/php.ini /usr/local/etc/php/php.ini

# 入口脚本 (拉起撮合守护进程 + FastMCP Streamable HTTP 服务 + Apache)
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# 启用 Apache 必要模块 (含代理模块，将 /mcp 反代到内部 FastMCP 服务)
RUN a2enmod rewrite headers proxy proxy_http

# 设置系统时区
ENV TZ=Asia/Shanghai
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# 配置 Apache: 纯云端 MCP Streamable HTTP 路由反代 (单端点 /mcp, 直接复用 Web 8080 端口，无需额外开端口)
# 注意: AllowOverride 仅允许出现在本文件(服务器主配置)中, 绝不可写进 .htaccess (会导致全站 500)
# FilesMatch 禁下载规则在此与 src/.htaccess 双保险: 即使 .htaccess 被误删, 敏感文件也不会泄露
# 流式保障: proxy-sendchunked 强制分块传输 + no-gzip 关闭压缩缓冲, 避免 Streamable HTTP 事件流被 Apache 缓存住
# Host 归一: ProxyPreserveHost Off —— 反代时把 Host 改写为后端地址 (127.0.0.1:8000),
#   使 mcp SDK 的 DNS-rebinding 防护始终收到合法 Host, 彻底规避 "421 Misdirected Request"。
#   这样外部入口 (域名/IP/端口/换域名) 怎么变都无需再动配置, 而 SDK 防护保持开启不降级。
RUN echo '<VirtualHost *:80>\n\
    DocumentRoot /var/www/html\n\
    \n\
    ProxyPreserveHost Off\n\
    SetEnv proxy-sendchunked 1\n\
    SetEnv no-gzip 1\n\
    ProxyPass /mcp http://127.0.0.1:8000/mcp\n\
    ProxyPassReverse /mcp http://127.0.0.1:8000/mcp\n\
    \n\
    AddDefaultCharset UTF-8\n\
    <Directory /var/www/html>\n\
        Options -Indexes +FollowSymLinks\n\
        AllowOverride All\n\
        Require all granted\n\
    </Directory>\n\
    <FilesMatch "\\.(sql|py|md|sh|log|bak|ini|env|lock|json)$">\n\
        Require all denied\n\
    </FilesMatch>\n\
</VirtualHost>' > /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

EXPOSE 80

# 统一入口：撮合守护进程 + FastMCP 云端服务 (Streamable HTTP) + Apache 前台
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
