#!/bin/bash
# ==============================================================================
# 赛博多人股市 - 部署打包工具 (输出标准 644/755 权限的 zip, 供 1Panel 面板上传解压)
#
# 用法:
#   bash dev/pack.sh              # 全量部署包 (白名单模式, 只含部署必要文件)
#   bash dev/pack.sh --src-only   # 日常更新包 (仅 src/, 覆盖即生效无需重启)
#
# 产物: dist/cyberstock-{full|src}-YYYYMMDD-HHMMSS.zip
#
# 白名单原则 (与 .gitignore 同思路, 反向操作):
#   只打包下面 ITEMS 里列出的文件/目录, 其余一律不进包 ——
#   天然免疫 .git 仓库目录、本地运行时缓存 (dev/.runtime)、
#   Python 缓存、编辑器杂物等, 无需维护黑名单。
# ==============================================================================
set -e

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST="$ROOT/dist"
STAMP="$(date +%Y%m%d-%H%M%S)"
MODE="${1:-full}"

command -v zip >/dev/null 2>&1 || { echo "[error] 未检测到 zip 命令, 请安装: sudo apt install zip"; exit 1; }

mkdir -p "$DIST"
cd "$ROOT"

# 全量包白名单: 部署必需的目录与文件 (存在才加入, 缺失自动跳过)
ITEMS=(
    src
    docker
    database
    dev
    Dockerfile
    docker-compose.yml
    mcp_kurumi.py
    .env.example
    VERSION
    README.md
    LICENSE
    DEPLOY_1PANEL.md
    ASTRBOT_KURUMI_SETUP.md
)

ITEM_ARGS=()
for item in "${ITEMS[@]}"; do
    [ -e "$ROOT/$item" ] && ITEM_ARGS+=("$item")
done

# 打包前权限体检: 确保压缩包携带标准权限位 (普通文件 644, 可执行脚本 755)
for item in "${ITEM_ARGS[@]}"; do
    if [ -d "$item" ]; then
        find "$item" -path "dev/.runtime" -prune -o -type f -exec chmod 644 {} +
    else
        chmod 644 "$item"
    fi
done
chmod 755 dev/*.sh mcp_kurumi.py docker/entrypoint.sh 2>/dev/null || true

# 通用兜底排除 (防白名单目录内部混入缓存类文件)
EXCLUDES=(
    -x "dev/.runtime/*" "dev/.runtime"
    -x "*__pycache__*" "*.pyc"
    -x "*/.DS_Store" ".DS_Store"
    -x "*.log" "*.swp"
)

if [ "$MODE" = "--src-only" ]; then
    OUT="cyberstock-src-$STAMP.zip"
    zip -rq "$DIST/$OUT" src -x "src/.DS_Store"
    KIND="日常更新包 (仅 src/, 上传到项目根目录解压覆盖即生效)"
elif [ "$MODE" = "full" ]; then
    OUT="cyberstock-full-$STAMP.zip"
    zip -rq "$DIST/$OUT" "${ITEM_ARGS[@]}" "${EXCLUDES[@]}"
    KIND="全量部署包 (白名单模式, 上传解压覆盖后, 涉及 Dockerfile 变动需重建编排)"
else
    echo "用法: bash dev/pack.sh [--src-only]"; exit 1
fi

echo "=========================================================="
echo "  打包完成: dist/$OUT"
echo "  类型:     $KIND"
echo "  大小:     $(du -h "$DIST/$OUT" | cut -f1)"
echo "=========================================================="
