#!/bin/bash
set -e

# 版本信息
VERSION="$(cat /var/www/html/VERSION 2>/dev/null || echo 'unknown')"
echo "=============================================="
echo " 赛博多人股市 (Cyber Stock Simulator) v$VERSION"
echo "==============================================="

# 0. webroot 读取权限自愈 (仅补读取权限, 不动属主与写权限):
#    即使宿主机覆盖更新时权限混乱, 也保证 Apache (www-data) 一定能读取应用文件
echo "Normalizing webroot read permissions..."
chmod -R a+rX /var/www/html 2>/dev/null || true

# 1. 后台市场撮合守护进程 (每 3 秒推进 Tick、K线、强平巡检与事件引擎)
if [ -f /var/www/html/api/worker.php ]; then
    echo "Starting background market worker daemon..."
    php /var/www/html/api/worker.php > /tmp/worker.log 2>&1 &
fi

# 2. FastMCP 云端服务 (AstrBot 久留美操盘手, Streamable HTTP 单端点 /mcp, 由 Apache 反代)
if [ -f /var/www/html/mcp_kurumi.py ] && python3 -c "import mcp" 2>/dev/null; then
    echo "Starting Kurumi MCP Streamable HTTP server (port 8000, endpoint /mcp)..."
    python3 /var/www/html/mcp_kurumi.py --http > /tmp/mcp.log 2>&1 &
else
    echo "WARNING: mcp_kurumi.py 或 MCP SDK 缺失, 跳过 MCP 服务启动 (检查 docker-compose.yml 是否挂载 mcp_kurumi.py)"
fi

echo "Starting Apache Web Server..."
exec apache2-foreground
