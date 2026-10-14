#!/usr/bin/env python3
"""
验证 src/api/*.php 文件的语法基础与文件完整性
1. 基础结构 (<?php 声明、括号配对、引用依赖存在)
2. 单引号字符串内 SQL 字面量转义回归检查
   (历史事故: portfolio.php 曾出现未转义的 'LONG'/'DAILY_CHECKIN' 导致整文件解析失败)
3. 若本机存在 PHP 解释器，则额外执行官方 php -l 语法检查
"""
import glob
import os
import re
import shutil
import subprocess

php_files = sorted(glob.glob('src/api/*.php'))
print(f"找到 PHP 接口文件 ({len(php_files)} 个):", php_files)


def scan_unescaped_sql_literals(code: str) -> list:
    """
    扫描「行首上下文位于单引号 PHP 字符串中」且包含未转义 SQL 字面量的行。
    PHP 单引号字符串内的 SQL 字面量必须写作 \'XXX\'，未转义的 'WORD' 会截断字符串。
    双引号字符串内的单引号 (如 db.php 的 exec("...IN ('000001',...)")) 是合法的，不误报。
    """
    BAD = re.compile(r"(?<!\\)'\w+'(?=\s*(?:AND|OR|,|\)|;))")
    SQLKEY = re.compile(r'\b(SELECT|INSERT|UPDATE|DELETE|WHERE|VALUES)\b')

    bad_lines = []
    i, n = 0, len(code)
    in_single = in_double = in_line = in_block = False
    line_begin = 0
    state_at_line_start = 'code'

    def flush(line: str) -> None:
        if state_at_line_start == 'single' and SQLKEY.search(line) and BAD.search(line):
            bad_lines.append(line.strip())

    while i < n:
        c = code[i]
        nxt = code[i + 1] if i + 1 < n else ''
        if c == '\n':
            flush(code[line_begin:i])
            line_begin = i + 1
            state_at_line_start = 'single' if in_single else ('double' if in_double else 'code')
            i += 1
            continue
        if in_line:
            if c == '\n':
                in_line = False
            i += 1
            continue
        if in_block:
            if c == '*' and nxt == '/':
                in_block = False
                i += 2
                continue
            i += 1
            continue
        if in_single:
            if c == '\\':
                i += 2
                continue
            if c == "'":
                in_single = False
            i += 1
            continue
        if in_double:
            if c == '\\':
                i += 2
                continue
            if c == '"':
                in_double = False
            i += 1
            continue
        if c == "'":
            if nxt == "'":  # 空字符串 ''
                i += 2
                continue
            in_single = True
            i += 1
            continue
        if c == '"':
            in_double = True
            i += 1
            continue
        if c == '/' and nxt == '/':
            in_line = True
            i += 2
            continue
        if c == '#':
            in_line = True
            i += 1
            continue
        if c == '/' and nxt == '*':
            in_block = True
            i += 2
            continue
        i += 1
    flush(code[line_begin:])
    return bad_lines


for fpath in php_files:
    with open(fpath, 'r', encoding='utf-8') as f:
        content = f.read()

    assert content.startswith('<?php'), f"{fpath} 缺少 <?php 声明"

    # 简单大括号匹配
    open_braces = content.count('{')
    close_braces = content.count('}')
    assert open_braces == close_braces, f"{fpath} 大括号不匹配: open={open_braces}, close={close_braces}"

    open_parens = content.count('(')
    close_parens = content.count(')')
    assert open_parens == close_parens, f"{fpath} 圆括号不匹配: open={open_parens}, close={close_parens}"

    # 检查引用文件路径是否存在
    for line in content.splitlines():
        if 'require_once' in line:
            # 提取文件名
            if '__DIR__' in line:
                part = line.split("__DIR__ . '")[1].split("'")[0]
                target_path = os.path.normpath(os.path.join(os.path.dirname(fpath), '.' + part))
                assert os.path.exists(target_path), f"{fpath} 中的引用文件不存在: {target_path}"

    # 回归检查: 单引号字符串内未转义的 SQL 字面量
    bad_lines = scan_unescaped_sql_literals(content)
    assert not bad_lines, (
        f"{fpath} 检测到单引号字符串内未转义的 SQL 字面量 (PHP 中必须写作 \\'XXX\\'):\n" +
        "\n".join(f"  -> {l}" for l in bad_lines)
    )

print("✓ 所有 PHP 文件的基本语法结构与依赖引用全部校验通过！")

# ------------------------------------------------------------------------------
# 回归 1: .htaccess 中永久禁止 AllowOverride
# (历史事故: .htaccess 中书写 AllowOverride All 是非法指令, 会导致全站 500)
# ------------------------------------------------------------------------------
htaccess_path = os.path.join('src', '.htaccess')
if os.path.exists(htaccess_path):
    with open(htaccess_path, 'r', encoding='utf-8') as f:
        ht = f.read()
    assert not re.search(r'^\s*AllowOverride\b', ht, re.MULTILINE), (
        f"{htaccess_path} 中出现了 AllowOverride 指令! 该指令仅允许写在服务器主配置 "
        f"(Dockerfile VirtualHost / apache conf) 中, 写在 .htaccess 里会导致全站 500"
    )
    print("✓ .htaccess 中无非法 AllowOverride 指令！")

# ------------------------------------------------------------------------------
# 回归 2: 仓库权限标准化白名单 (目录 755, 普通文件 644, 指定脚本 755)
# (历史事故: 全仓 775 权限打进压缩包, 服务器 1Panel 解压覆盖时报权限不足死循环)
# ------------------------------------------------------------------------------
EXECUTABLE_WHITELIST = {
    'dev/preview.sh', 'dev/pack.sh', 'dev/fix-server-perms.sh',
    'docker/entrypoint.sh', 'mcp_kurumi.py',
}
SKIP_DIRS = {'dev/.runtime', 'dist', '.git'}
bad_perms = []
for dirpath, dirnames, filenames in os.walk('.'):
    rel_dir = os.path.relpath(dirpath)
    if any(rel_dir == s or rel_dir.startswith(s + os.sep) for s in SKIP_DIRS):
        dirnames[:] = []
        continue
    for name in filenames:
        fpath = os.path.relpath(os.path.join(dirpath, name))
        mode = os.stat(fpath).st_mode & 0o777
        if fpath.replace(os.sep, '/') in EXECUTABLE_WHITELIST:
            expect = 0o755
        else:
            expect = 0o644
        if mode != expect:
            bad_perms.append(f"{fpath}: {oct(mode)} (期望 {oct(expect)})")
assert not bad_perms, "仓库存在非标准权限文件 (会导致打包权限死循环):\n" + "\n".join(bad_perms)
print("✓ 仓库权限标准化校验通过 (文件 644 / 脚本 755)！")

# 若存在 PHP 解释器则执行官方语法检查
php_bin = shutil.which('php') or shutil.which('php8.3') or shutil.which('php8.2')
if php_bin:
    for fpath in php_files:
        res = subprocess.run([php_bin, '-l', fpath], capture_output=True, text=True)
        assert res.returncode == 0, f"{fpath} php -l 官方语法检查失败:\n{res.stdout}{res.stderr}"
    print(f"✓ 已使用官方解释器 ({php_bin}) 完成 php -l 语法检查！")
else:
    print("(未检测到 PHP 解释器，跳过 php -l 官方语法检查)")
