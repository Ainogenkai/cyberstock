#!/bin/bash
# ==============================================================================
# 赛博多人股市 - 本地一键预览脚本 (无需构建 Docker 应用镜像)
#
# 用法:
#   bash dev/preview.sh start   # 启动预览 (数据库 + PHP 内置服务器)
#   bash dev/preview.sh stop    # 停止预览 (保留数据库数据)
#   bash dev/preview.sh down    # 停止并清空预览数据 (重置环境)
#
# 环境变量:
#   PREVIEW_PORT    Web 预览端口   (默认 8099)
#   PREVIEW_PG_PORT PostgreSQL 端口 (默认 15432)
# ==============================================================================
set -e

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RUNTIME="$ROOT/dev/.runtime"
PORT="${PREVIEW_PORT:-8099}"
PG_PORT="${PREVIEW_PG_PORT:-15432}"
PG_CONTAINER="cyberstock-preview-pg"
PG_VOLUME="cyberstock_preview_pgdata"
PHP_PID_FILE="$RUNTIME/php-server.pid"

# ---------- 便携 PHP 运行时 (优先使用系统 PHP, 缺失时免 root 解包 apt 版) ----------
PHP_FLAGS=""

find_system_php() {
    for c in php php8.4 php8.3 php8.2; do
        if command -v "$c" >/dev/null 2>&1 && "$c" -m 2>/dev/null | grep -qi pdo_pgsql; then
            echo "$c"
            return 0
        fi
    done
    return 1
}

ensure_runtime() {
    local sysphp
    if sysphp=$(find_system_php); then
        echo "$sysphp"
        return 0
    fi
    # 使用之前提取的便携运行时 (优先查找本地与 /tmp 缓存)
    if [ -x "$RUNTIME/phpx/root/usr/bin/php8.3" ]; then
        echo "PORTABLE"
        return 0
    fi
    if [ -x "/tmp/opencode/phpx/root/usr/bin/php8.3" ]; then
        mkdir -p "$RUNTIME"
        cp -rn /tmp/opencode/phpx "$RUNTIME/" 2>/dev/null || true
        if [ -x "$RUNTIME/phpx/root/usr/bin/php8.3" ]; then
            echo "PORTABLE"
            return 0
        fi
    fi
    # 现场提取便携运行时 (免 root, 仅需 apt 仓库可达)
    echo "[setup] 未检测到系统 PHP, 正在免 root 提取便携 PHP 8.3 运行时..." >&2
    mkdir -p "$RUNTIME/phpx"
    (
        cd "$RUNTIME/phpx"
        apt-get download php8.3-cli php8.3-common php8.3-pgsql libpq5 >/dev/null 2>&1
        for d in *.deb; do dpkg-deb -x "$d" root; done
    )
    echo "PORTABLE"
}

run_php() {
    local kind="$1"; shift
    if [ "$kind" = "PORTABLE" ]; then
        LD_LIBRARY_PATH="$RUNTIME/phpx/root/usr/lib/x86_64-linux-gnu${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}" \
        "$RUNTIME/phpx/root/usr/bin/php8.3" \
            -d extension_dir="$RUNTIME/phpx/root/usr/lib/php/20230831" \
            -d extension=pdo.so -d extension=pdo_pgsql.so -d extension=pgsql.so \
            "$@"
    else
        "$kind" "$@"
    fi
}

# ---------- 数据库 ----------
ensure_database() {
    if ! command -v docker >/dev/null 2>&1; then
        echo "[error] 未检测到 docker, 且本机无 PostgreSQL 配置逻辑。"
        echo "        如需连接外部数据库: PREVIEW_DB_HOST=x PREVIEW_DB_PORT=x bash dev/preview.sh start"
        exit 1
    fi
    if ! docker image inspect postgres:15-alpine >/dev/null 2>&1; then
        echo "[setup] 拉取 postgres:15-alpine 镜像..."
        docker pull postgres:15-alpine
    fi
    if ! docker ps --format '{{.Names}}' | grep -qx "$PG_CONTAINER"; then
        docker rm -f "$PG_CONTAINER" >/dev/null 2>&1 || true
        docker run -d --name "$PG_CONTAINER" \
            -e POSTGRES_DB=cyberstock -e POSTGRES_USER=cyberstock_user \
            -e POSTGRES_PASSWORD='CyberStockPass_2026!' \
            -v "$PG_VOLUME":/var/lib/postgresql/data \
            -p "127.0.0.1:$PG_PORT":5432 postgres:15-alpine >/dev/null
        echo "[setup] PostgreSQL 预览容器已启动 (端口 $PG_PORT, 数据持久化于卷 $PG_VOLUME)"
    fi
    # 等待就绪
    for i in $(seq 1 30); do
        if docker exec "$PG_CONTAINER" pg_isready -U cyberstock_user >/dev/null 2>&1; then
            return 0
        fi
        sleep 1
    done
    echo "[error] PostgreSQL 未能在 30 秒内就绪"; exit 1
}

DB_HOST="${PREVIEW_DB_HOST:-127.0.0.1}"
DB_PORT="${PREVIEW_DB_PORT:-$PG_PORT}"

# ---------- 命令 ----------
case "${1:-start}" in
start)
    ensure_database
    PHPKIND=$(ensure_runtime)

    if [ -f "$PHP_PID_FILE" ] && kill -0 "$(cat "$PHP_PID_FILE")" 2>/dev/null; then
        echo "[warn] 预览服务器已在运行 (PID $(cat "$PHP_PID_FILE"))"
    else
        export DB_HOST DB_PORT DB_NAME=cyberstock DB_USER=cyberstock_user DB_PASSWORD='CyberStockPass_2026!'
        mkdir -p "$RUNTIME"
        if [ "$PHPKIND" = "PORTABLE" ]; then
            export LD_LIBRARY_PATH="$RUNTIME/phpx/root/usr/lib/x86_64-linux-gnu${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"
            PHP_CMD="$RUNTIME/phpx/root/usr/bin/php8.3"
            PHP_ARGS=(-d extension_dir="$RUNTIME/phpx/root/usr/lib/php/20230831"
                      -d extension=pdo.so -d extension=pdo_pgsql.so -d extension=pgsql.so)
        else
            PHP_CMD="$PHPKIND"
            PHP_ARGS=()
        fi
        # nohup + 后台: 预览服务器独立于终端存活
        nohup "$PHP_CMD" "${PHP_ARGS[@]}" -S "127.0.0.1:$PORT" -t "$ROOT/src" \
            > "$RUNTIME/php-server.log" 2>&1 &
        echo $! > "$PHP_PID_FILE"
        sleep 1
        if ! kill -0 "$(cat "$PHP_PID_FILE")" 2>/dev/null; then
            echo "[error] PHP 服务器启动失败, 日志:"; tail -5 "$RUNTIME/php-server.log"; exit 1
        fi
    fi

    echo ""
    echo "=========================================================="
    echo "  预览已就绪:  http://127.0.0.1:$PORT"
    echo "  管理后台:    admin / CyberStock@2026"
    echo "  注册邀请码:  CYBER666"
    echo "  停止预览:    bash dev/preview.sh stop"
    echo "  重置数据:    bash dev/preview.sh down"
    echo "=========================================================="
    ;;
stop)
    if [ -f "$PHP_PID_FILE" ] && kill "$(cat "$PHP_PID_FILE")" 2>/dev/null; then
        rm -f "$PHP_PID_FILE"; echo "[ok] PHP 预览服务器已停止"
    else
        echo "[warn] PHP 预览服务器未在运行"
    fi
    docker rm -f "$PG_CONTAINER" >/dev/null 2>&1 && echo "[ok] PostgreSQL 预览容器已停止 (数据保留)" || true
    ;;
down)
    "$0" stop >/dev/null 2>&1 || true
    docker volume rm -f "$PG_VOLUME" >/dev/null 2>&1 && echo "[ok] 预览数据库已重置" || true
    ;;
*)
    echo "用法: bash dev/preview.sh {start|stop|down}"; exit 1
    ;;
esac
