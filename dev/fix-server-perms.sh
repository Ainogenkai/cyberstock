#!/bin/bash
# ==============================================================================
# 赛博多人股市 - 服务器权限一键修复 (解决 1Panel 压缩包覆盖报权限不足的问题)
#
# 在服务器上执行 (1Panel 终端或 SSH):
#   bash dev/fix-server-perms.sh            # 属主设为 root:root (1Panel 面板解压默认以 root 运行)
#   bash dev/fix-server-perms.sh 1panel     # 属主设为 1panel:1panel (若你的面板文件操作非 root)
#
# 作用: 项目文件标准化为 644 / 目录 755 / 可执行脚本 755, 并校正属主。
# 执行一次后, 此后面板解压覆盖不会再报权限错误。
# ==============================================================================
set -e

OWNER="${1:-root}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

if [ "$(id -u)" -ne 0 ]; then
    echo "[error] 请以 root 身份运行 (1Panel 终端默认即 root), 或使用 sudo"
    exit 1
fi

if ! id "$OWNER" >/dev/null 2>&1; then
    echo "[error] 用户 $OWNER 不存在。可用用户: $(awk -F: '$3>=1000 && $3<65534 {print $1}' /etc/passwd | tr '\n' ' ') root"
    exit 1
fi

echo "[fix] 项目目录: $PROJECT_DIR"
echo "[fix] 目标属主: $OWNER:$OWNER"

cd "$PROJECT_DIR"

# 1. 属主校正 (仅项目自身, 不触碰容器卷内数据)
chown -R "$OWNER:$OWNER" .

# 2. 权限标准化: 目录 755, 文件 644, 可执行脚本 755
find . -path ./dev/.runtime -prune -o -type d -exec chmod 755 {} \;
find . -path ./dev/.runtime -prune -o -type f -exec chmod 644 {} \;
chmod 755 dev/*.sh mcp_kurumi.py docker/entrypoint.sh 2>/dev/null || true

# 3. 敏感文件收紧: .env 只给属主读写 (含数据库密码)
[ -f .env ] && chmod 600 .env && echo "[fix] .env 已收紧为 600"

echo "[ok] 权限修复完成。此后的 1Panel 面板解压覆盖将不再报权限不足。"
echo "[tip] 日常更新建议使用 dev/pack.sh --src-only 打包, 上传解压覆盖 src/ 即可免重启生效。"
